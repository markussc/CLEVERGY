<?php

namespace AppBundle\Utils\Connectors;

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
        $latest = $this->em->getRepository('AppBundle:SmartFoxDataStore')->getLatest($this->ip);
        if ($latest && count($latest)) {
            $latest['energyToday'] = $this->em->getRepository('AppBundle:SmartFoxDataStore')->getEnergyToday($this->ip);
        }

        return $latest;
    }

    public function getAll($calculatedData = false)
    {
        if ($this->version === "pro") {
            $responseArr = $this->getFromPRO();
        } else {
            $responseArr = $this->getFromREG9TE();
        }

        // if requested, add calculated data
        if ($calculatedData) {
            $responseArr['energyToday'] = $this->em->getRepository('AppBundle:SmartFoxDataStore')->getEnergyToday($this->ip);
        }

        return $responseArr;
    }

    public function getIp()
    {
        return $this->ip;
    }

    private function getFromREG9TE()
    {
        return json_decode($this->browser->get($this->basePath . '/all')->getContent(), true);
    }

    private function getFromPRO()
    {
        $xmlData = $this->browser->get($this->basePath . '/values.xml')->getContent();
        $xml = new \SimpleXMLElement($xmlData);
        $data = [];
        foreach ($xml->children() as $value) {
            $val = (string)$value;
            if (strpos($val, " kW") !== false) {
                $val = 1000*substr($val, 0, strpos($val, " kW"));
            } elseif (strpos($val, " kWh") !== false) {
                $val = 1000*substr($val, 0, strpos($val, " kWh"));
            } elseif (strpos($val, " Wh") !== false) {
                $val = 1*substr($val, 0, strpos($val, " Wh"));
            } elseif (strpos($val, " °C") !== false) {
                $val = 1*substr($val, 0, strpos($val, " °C"));
            } elseif (strpos($val, " W") !== false) {
                $val = 1*substr($val, 0, strpos($val, " W"));
            }
            $data[(string)$value['id']] = $val;
        }

        return [
            "energy_in" => $data["u5827-41"],
            "energy_out" => $data["u5824-41"],
            "day_energy_in" => $data["u5863-41"],
            "day_energy_out" => $data["u5872-41"],
            "power_io" => $data["u5790-41"],
            "digital" => ["0" => ["state" => $data["u5674-41"]]],
            "PvPower" => ["0" => $data["u5272-41"]],
            "PvEnergy" => ["0" => $data["u7015"]],
        ];
    }
}
