<?php

namespace App\Utils\Connectors;

use App\Entity\ConexioDataStore;
use Doctrine\ORM\EntityManagerInterface;
use Nesk\Puphpeteer\Puppeteer;

/**
 * Connector to retrieve data from the conexio200 web modul (used by Soltop for solar-thermical systems)
 * For information refer to www.soltop.ch
 *
 * @author Markus Schafroth
 */
class ConexioConnector
{
    protected $em;
    protected $basePath;
    protected $connectors;

    public function __construct(EntityManagerInterface $em, Array $connectors)
    {
        $this->em = $em;
        $this->connectors = $connectors;
        $this->ip = null;
        $this->basePath = '';
        if (array_key_exists('conexio', $connectors)) {
            $this->ip = $connectors['conexio']['ip'];
            $this->basePath = 'http://' . $this->ip;
        }
    }

    /**
     * Reads the latest available data from the database
     * @return array
     */
    public function getAllLatest()
    {
        $latest = [];
        if (array_key_exists('conexio', $this->connectors)) {
            $ip = $this->connectors['conexio']['ip'];
            $latest = $this->em->getRepository(ConexioDataStore::class)->getLatest($ip);
            if ($latest && count($latest)) {
                 $latest['energyToday'] = $this->em->getRepository(ConexioDataStore::class)->getEnergyToday($this->connectors['conexio']['ip']);
            }
        }

        return $latest;
    }

    /**
     * @return array
     * 
     * Retrieves the available data using the webinterface
     */
    public function getAll($calculatedData = false)
    {
        try {
            $url = $this->basePath . '/medius_val.xml';
            $puppeteer = new Puppeteer;
            $browser = $puppeteer->launch([
                'args' => [
                    '--disable-gpu',
                    '--disable-dev-shm-usage',
                    '--disable-setuid-sandbox',
                    '--no-sandbox',
                ]
            ]);
            $page = $browser->newPage();

            // authenticate
            $page->authenticate(['username' => $this->connectors['conexio']['username'], 'password' => $this->connectors['conexio']['password']]);

            // get data-string
            $page->goto($url);
            $rawData = $page->evaluate('document.querySelector("data").innerHTML');
            $browser->close();

            // extract the required data
            $data = $this->extractData($rawData);

            // if requested, add calculated data
            if ($calculatedData) {
                $data['energyToday'] = $this->em->getRepository(ConexioDataStore::class)->getEnergyToday($this->connectors['conexio']['ip']);
            }
        } catch (\Exception $e) {
            return false;
        }
        return $data;
    }

    public function getIp()
    {
        return $this->ip;
    }

    private function extractData($str)
    {
        $size = $this->convertAtoH($str, 2);
        $str = substr($str, 2);
        $str = substr($str, 8); // Timestamp übergehen
        $data = [];
        for ($i=0; $i < $size/2; $i++) {
            $value = $this->convertAtoH($str,4);
            $str = substr($str, 4);
            if($i != 20 && $i != 27 && $value > 32767)
            {
                $value -= 65536;
            }

            //temps
            if($i < 7)
            {
                $idx = 's' . ($i+1);
                $data[$idx] = $value/10;
            }
            else if($i > 10 && $i < 14)
            {
                $idx = 'r' . ($i-10);
                $data[$idx] = $value/2;
            }
            else if($i == 15)
            {
                $idx = 'r' . ($i-15);
                $data[$idx] = $value/2;
            }
            else if($i == 19)
            {
                $data['q-1'] = $value;
            }
            else if($i == 20)
            {
                $data['q'] = $value;
            }
            else if($i == 21)
            {
                $data['solarpumphours'] = $value;
            }
            else if($i == 22)
            {
                $data['sp1_upper_ventil_hours'] = $value;
            }
            else if($i == 27)
            {
                switch($value) {
                    case $value < 0:
                        $data['he3'] = 0;
                        break;
                    case $value > 30000:
                        $data['he3'] = 33;
                        break;
                    case $value > 20000:
                        $data['he3'] = 66;
                        break;
                    default:
                        $data['he3'] = intval((65831-$value) / 1000); // we get the percent value
                }
            }
        }
        // add calculated current power (delta between S6 and S7 multiplied by a constant 335 [defined by Durchfluss and Spez. Wärmekapazität) gives power in Watts
        if (!array_key_exists('s6', $data)) {
            $data['s6'] = 0;
        }
        if (!array_key_exists('s7', $data)) {
            $data['s7'] = 0;
        }
        if (!array_key_exists('r1', $data)) {
            $data['r1'] = 0;
        }
        if (!array_key_exists('he3', $data)) {
            $data['he3'] = 0;
        } elseif ($data['r1'] == 0) {
            $data['he3'] = 0; // if the pump is not on (230v), the PWM value is irrelevant
        }
        $data['p'] = (int)(($data['s6'] - $data['s7'])*340*$data['he3']/100);

        if(!array_key_exists('q', $data)) {
            // the retrieved data is incomplete or invalid, we do not want to use it
            $data = false;
        }
        return $data;
    }

    private function convertAtoH($hexstring, $size)
    {
        $hexstring = substr($hexstring, 0, $size);
        return intval($hexstring,16);
    }
}
