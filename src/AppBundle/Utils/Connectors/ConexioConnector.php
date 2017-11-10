<?php

namespace AppBundle\Utils\Connectors;

use Doctrine\ORM\EntityManager;

/**
 * Connector to retrieve data from the conexio200 web modul (used by Soltop for solar-thermical systems)
 * For information refer to www.soltop.ch
 *
 * @author Markus Schafroth
 */
class ConexioConnector
{
    protected $em;
    protected $browser;
    protected $basePath;
    protected $connectors;

    public function __construct(EntityManager $em, \Buzz\Browser $browser, Array $connectors)
    {
        $this->em = $em;
        $this->browser = $browser;
        $this->connectors = $connectors;
        $this->basePath = 'http://' . $connectors['conexio']['ip'];
    }

    /**
     * Reads the latest available data from the database
     * @return array
     */
    public function getAllLatest()
    {
        $ip = $this->connectors['conexio']['ip'];
        return $this->em->getRepository('AppBundle:ConexioDataStore')->getLatest($ip);
    }

    /**
     * @return array
     * 
     * Retrieves the available data using the webinterface
     */
    public function getAll()
    {
        // digest authentication
        $this->browser->setListener(new \Buzz\Listener\DigestAuthListener($this->connectors['conexio']['username'], $this->connectors['conexio']['password']));
        $url = $this->basePath . '/medius_val.xml';
        $response = $this->browser->get($url);
        $statusCode = $response->getStatusCode();
        if ($statusCode == 401) {
            $response = $this->browser->get($url)->getContent(); // TODO: this does not work correctly if invoked from the command (digest auth not working)
        } else {
            $response = $response->getContent();
        }

        $data = $this->extractData($response);

        return $data;
    }

    public function getIp()
    {
        return $this->connectors['conexio']['ip'];
    }

    private function extractData($xmlData)
    {
        $str = substr($xmlData, 11);
        $size = $this->convertAtoH($str, 2);
        $str = substr($str, 2);
        $str = substr($str, 8); // Timestamp übergehen
        $data = [];
        for ($i=0; $i < $size/2; $i++) {
            $value = $this->convertAtoH($str,4);
            $str = substr($str, 4);
            //temps
            if($i < 7)
            {
                if($value > 32768)
                {
                    $value -= 65536;
                }
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
                $data['qNow'] = $value;
            }
            else if($i == 20)
            {
                $data['q'] = $value;
            }
        }

        return $data;
    }

    private function convertAtoH($hexstring, $size)
    {
        $hexstring = substr($hexstring, 0, $size);
        return intval($hexstring,16);
    }
}
