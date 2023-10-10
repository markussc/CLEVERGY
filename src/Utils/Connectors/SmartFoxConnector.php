<?php

namespace App\Utils\Connectors;

use App\Entity\SmartFoxDataStore;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Connector to retrieve data from the SmartFox device
 * For information regarding SmartFox refer to www.smartfox.at
 *
 * @author Markus Schafroth
 */
class SmartFoxConnector
{
    protected $em;
    protected $client;
    protected $basePath;
    protected $ip;
    protected $version;
    private $connectors;

    public function __construct(EntityManagerInterface $em, HttpClientInterface $client, Array $connectors)
    {
        $this->em = $em;
        $this->client = $client;
        $this->ip = null;
        $this->version = null;
        $this->connectors = $connectors;
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
        $latest = $this->em->getRepository(SmartFoxDataStore::class)->getLatest($this->ip);

        return $latest;
    }

    public function getAll()
    {
        try {
            if ($this->version === "pro") {
                $responseArr = $this->getFromPRO();
            } else {
                $responseArr = $this->getFromREG9TE();
            }

            $responseArr = $this->addAlternativePv($responseArr);
            $responseArr = $this->addStorage($responseArr);
        } catch (\Exception $e) {
            $responseArr = null;
        }

        return $responseArr;
    }

    public function getLiveStorage()
    {
        $storages = [];
        if ($this->hasStorage()) {
            foreach ($this->connectors['smartfox']['storage'] as $storage) {
                $values = $this->queryNelinor($storage['ip']);
                $values['name'] = $storage['name'];
                $storages[] = $values;
            }
        }

        return $storages;
    }

    public function getIp()
    {
        return $this->ip;
    }

    public function hasAltPv()
    {
        if (array_key_exists('smartfox', $this->connectors) && array_key_exists('alternative', $this->connectors['smartfox'])) {
            return true;
        } else {
            return false;
        }
    }

    public function hasStorage()
    {
        if (array_key_exists('smartfox', $this->connectors) && array_key_exists('storage', $this->connectors['smartfox'])) {
            return true;
        } else {
            return false;
        }
    }

    private function getFromREG9TE()
    {
        $arr = json_decode($this->client->request('GET', $this->basePath . '/all')->getContent(), true);
        $arr['day_energy_in'] = $this->em->getRepository(SmartFoxDataStore::class)->getEnergyInterval($this->ip, 'energy_in');
        $arr['day_energy_out'] = $this->em->getRepository(SmartFoxDataStore::class)->getEnergyInterval($this->ip, 'energy_out');
        $arr['energyToday'] = $this->em->getRepository(SmartFoxDataStore::class)->getEnergyToday($this->ip);
        if ($this->hasAltPv()) {
            $arr['altEnergyToday'] = $this->em->getRepository(SmartFoxDataStore::class)->getEnergyInterval($this->ip, 'PvEnergyAlt');
        }
        if ($this->hasStorage()) {
            $arr['storageEnergyToday_in'] = $this->em->getRepository(SmartFoxDataStore::class)->getEnergyInterval($this->ip, 'StorageEnergyIn');
            $arr['storageEnergyToday_out'] = $this->em->getRepository(SmartFoxDataStore::class)->getEnergyInterval($this->ip, 'StorageEnergyOut');
        }

        return $arr;
    }

    private function getFromPRO()
    {
        $xmlData = $this->client->request('GET', $this->basePath . '/values.xml')->getContent();
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
            } elseif (strpos($val, "%") !== false) {
                $val = 1*(float)substr($val, 0, strpos($val, "%"));
            }
            $data[(string)$value['id']] = $val;
        }

        $values = [
            "energy_in" => $data["energyValue"],
            "energy_out" => $data["eToGridValue"],
            "power_io" => $data["detailsPowerValue"],
            "digital" => [
                "0" => ["state" => $data["relayStatusValue1"]],
                "1" => ["state" => $data["relayStatusValue2"]],
                "2" => ["state" => $data["relayStatusValue3"]],
                "3" => ["state" => $data["relayStatusValue4"]],
            ],
            "PvPower" => ["0" => $data["wr1PowerValue"]],
            "PvEnergy" => ["0" => $data["wr1EnergyValue"]],
            "day_energy_in" => $this->em->getRepository(SmartFoxDataStore::class)->getEnergyInterval($this->ip, 'energy_in'),
            "day_energy_out" => $this->em->getRepository(SmartFoxDataStore::class)->getEnergyInterval($this->ip, 'energy_out'),
            "energyToday" => $this->em->getRepository(SmartFoxDataStore::class)->getEnergyToday($this->ip),
            "consumptionControl1Percent" => $data["consumptionControl1Percent"]
        ];
        if ($this->hasAltPv()) {
            $values['altEnergyToday'] = $this->em->getRepository(SmartFoxDataStore::class)->getEnergyInterval($this->ip, 'PvEnergyAlt');
        }
        if ($this->hasStorage()) {
            $arr['storageEnergyToday_in'] = $this->em->getRepository(SmartFoxDataStore::class)->getEnergyInterval($this->ip, 'StorageEnergyIn');
            $arr['storageEnergyToday_out'] = $this->em->getRepository(SmartFoxDataStore::class)->getEnergyInterval($this->ip, 'StorageEnergyOut');
        }

        return $values;
    }

    private function addAlternativePv($arr)
    {
        if (array_key_exists('smartfox', $this->connectors)) {
            if (array_key_exists('alternative', $this->connectors['smartfox'])) {
                $totalAlternativePower = 0;
                $latestEntry = $this->getAllLatest();
                if (array_key_exists(1, $latestEntry['PvEnergy'])) {
                    $latestAltPvEnergy = $latestEntry['PvEnergy'][1];
                } else {
                    $latestAltPvEnergy = 0;
                }
                foreach ($this->connectors['smartfox']['alternative'] as $alternative) {
                    if ($alternative['type'] == 'mystrom') {
                        $pvPower = $this->queryMyStromPower($alternative['ip']);
                    } elseif ($alternative['type'] == 'shelly') {
                        $pvPower = $this->queryShellyPower($alternative['ip'], $alternative['port']);
                    }
                    // make sure values are positive (independent of device type)
                    $totalAlternativePower += abs($pvPower);
                }
                // calculate the energy produced at the given power level during one minute
                $arr['PvEnergy'][1] = round($latestAltPvEnergy + 60*$totalAlternativePower/3600);
                $arr['PvPower'][1] = round($totalAlternativePower);
            }
        }

        return $arr;
    }

    private function addStorage($arr)
    {
        if (array_key_exists('smartfox', $this->connectors)) {
            if (array_key_exists('storage', $this->connectors['smartfox'])) {
                $storageCounter = 0;
                $totalStoragePowerIn = 0;
                $totalStoragePowerOut = 0;
                $totalStorageSoc = 0;
                $latestEntry = $this->getAllLatest();
                if (array_key_exists('StorageEnergyIn', $latestEntry)) {
                    $latestStorageEnergyIn = $latestEntry['StorageEnergyIn'];
                } else {
                    $latestStorageEnergyIn = 0;
                }
                if (array_key_exists('StorageEnergyOut', $latestEntry)) {
                    $latestStorageEnergyOut = $latestEntry['StorageEnergyOut'];
                } else {
                    $latestStorageEnergyOut = 0;
                }
                foreach ($this->connectors['smartfox']['storage'] as $storage) {
                    if ($storage['type'] == 'nelinor') {
                        $storageCounter++;
                        $storageData = $this->queryNelinor($storage['ip']);
                        if ($storageData['power'] >= 0) {
                            // charging battery
                            $totalStoragePowerIn += $storageData['power'];
                        } else {
                            // uncharging battery
                            $totalStoragePowerOut += $storageData['power'];
                        }
                        $totalStorageSoc += $storageData['soc'];
                    }
                }
                // calculate the energy produced at the given power level during one minute
                $arr['StorageEnergyIn'] = round($latestStorageEnergyIn + 60*$totalStoragePowerIn/3600);
                $arr['StorageEnergyOut'] = round($latestStorageEnergyOut + 60*$totalStoragePowerOut/3600);
                $arr['StoragePower'] = $totalStoragePowerIn + $totalStoragePowerOut;
                $arr['StorageSoc'] = $totalStorageSoc/$storageCounter;
            }
        }

        return $arr;
    }

    private function queryMyStromPower($ip)
    {
        $url = 'http://' . $ip . '/report';
        try {
            $response = $this->client->request('GET', $url);
            $statusCode = $response->getStatusCode();
            if ($statusCode != 200) {
                return 0;
            }
            $json = $response->getContent();

            return json_decode($json, true)['Ws'];
        } catch (\Exception $e) {
            return 0;
        }
    }

    private function queryShellyPower($ip, $port)
    {
        $url = 'http://' . $ip . '/meter/' . $port;
        try {
            $response = $this->client->request('GET', $url);
            $statusCode = $response->getStatusCode();
            if ($statusCode != 200) {
                return 0;
            }
            $json = $response->getContent();

            return json_decode($json, true)['power'];
        } catch (\Exception $e) {
            return 0;
        }
    }

    private function queryNelinor($ip)
    {
        /*
        $url = 'http://' . $ip . '/report'; // to be verified
        try {
            $response = $this->client->request('GET', $url);
            $statusCode = $response->getStatusCode();
            if ($statusCode != 200) {
                return 0;
            }
            $binaryResp = $response->getContent();
            $power = 0; // to be extracted from binary response
            $soc = 0; // to be extracted from binary response
            return ['power' => $power, 'soc' => $soc];
        } catch (\Exception $e) {
            return ['power' => 0, 'soc' => 0];
        }*/
        return ['power' => round($this->queryMyStromPower($ip)), 'soc' => 100];
    }
}
