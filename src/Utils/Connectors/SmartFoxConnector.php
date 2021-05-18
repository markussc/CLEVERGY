<?php

namespace App\Utils\Connectors;

use Doctrine\ORM\EntityManager;

/**
 * Connector to retrieve data from the SmartFox device
 * For information regarding SmartFox refer to www.smartfox.at
 *
 * @author Markus Schafroth
 */
class SmartFoxConnector
{
    protected $em;
    protected $browser;
    protected $basePath;
    protected $ip;
    protected $version;

    public function __construct(EntityManager $em, \Buzz\Browser $browser, Array $connectors)
    {
        $this->em = $em;
        $this->browser = $browser;
        $this->ip = null;
        $this->version = null;
        if (array_key_exists('smartfox', $connectors)) {
            $this->ip = $connectors['smartfox']['ip'];
            if (array_key_exists('version', $connectors['smartfox'])) {
                $this->version = $connectors['smartfox']['version'];
            }
        }
        $this->basePath = 'http://' . $this->ip;
    }

    public function getAllLatest()
    {
        $latest = $this->em->getRepository('App:SmartFoxDataStore')->getLatest($this->ip);

        return $latest;
    }

    public function getAll()
    {
        if ($this->version === "pro") {
            $responseArr = $this->getFromPRO();
        } else {
            $responseArr = $this->getFromREG9TE();
        }

        return $responseArr;
    }

    public function getIp()
    {
        return $this->ip;
    }

    private function getFromREG9TE()
    {
        $arr = json_decode($this->browser->get($this->basePath . '/all')->getContent(), true);
        $arr['day_energy_in'] = $this->em->getRepository('App:SmartFoxDataStore')->getEnergyInterval($this->ip, 'energy_in');
        $arr['day_energy_out'] = $this->em->getRepository('App:SmartFoxDataStore')->getEnergyInterval($this->ip, 'energy_out');
        $arr['energyToday'] = $this->em->getRepository('App:SmartFoxDataStore')->getEnergyToday($this->ip);

        return $arr;
    }

    private function getFromPRO()
    {
        $xmlData = $this->browser->get($this->basePath . '/values.xml')->getContent();
        $xml = new \SimpleXMLElement($xmlData);
        $data = [];
        foreach ($xml->children() as $value) {
            $val = (string)$value;
            if (strpos($val, " kW") !== false) {
                $val = 1000*(float)substr($val, 0, strpos($val, " kW"));
            } elseif (strpos($val, " kWh") !== false) {
                $val = 1000*(float)substr($val, 0, strpos($val, " kWh"));
            } elseif (strpos($val, " Wh") !== false) {
                $val = 1*(float)substr($val, 0, strpos($val, " Wh"));
            } elseif (strpos($val, " °C") !== false) {
                $val = 1*(float)substr($val, 0, strpos($val, " °C"));
            } elseif (strpos($val, " W") !== false) {
                $val = 1*(float)substr($val, 0, strpos($val, " W"));
            }
            $data[(string)$value['id']] = $val;
        }

        $values = [
            "energy_in" => $data["eDayValue"],
            "energy_out" => $data["eDayToGridValue"],
            "power_io" => $data["detailsPowerValue"],
            "digital" => [
                "0" => ["state" => $data["relayStatusValue1"]],
                "1" => ["state" => $data["relayStatusValue2"]],
                "2" => ["state" => $data["relayStatusValue3"]],
                "3" => ["state" => $data["relayStatusValue4"]],
            ],
            "PvPower" => ["0" => $data["wr1PowerValue"]],
            "PvEnergy" => ["0" => $data["wr1EnergyValue"]],
            "day_energy_in" => $this->em->getRepository('App:SmartFoxDataStore')->getEnergyInterval($this->ip, 'energy_in'),
            "day_energy_out" => $this->em->getRepository('App:SmartFoxDataStore')->getEnergyInterval($this->ip, 'energy_out'),
            "energyToday" => $this->em->getRepository('App:SmartFoxDataStore')->getEnergyToday($this->ip)
        ];

        return $values;
    }
}
