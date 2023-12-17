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

    public function getAll($addStorage = false)
    {
        try {
            if ($this->version === "pro") {
                $responseArr = $this->getFromPRO();
            } else {
                $responseArr = $this->getFromREG9TE();
            }

            $responseArr = $this->addAlternativePv($responseArr);
            if ($addStorage) {
                $responseArr = $this->addStorage($responseArr);
            }
        } catch (\Exception $e) {
            $responseArr = null;
        }

        return $responseArr;
    }

    private function getPowerIo()
    {
        try {
            if ($this->version === "pro") {
                $responseArr = $this->getFromPRO(false);
            } else {
                $responseArr = $this->getFromREG9TE(false);
            }
        } catch (\Exception $e) {
            $responseArr = null;
        }

        return $responseArr;
    }

    public function getLiveStorage()
    {
        $data = $this->getAllLatest();
        if (array_key_exists('StorageDetails', $data)) {
            return $data['StorageDetails'];
        } else {
            return [];
        }
    }

    public function getLiveStorageTotalSoc()
    {
        $data = $this->getAllLatest();
        $soc = 0;
        $counter = 0;
        if (array_key_exists('StorageDetails', $data)) {
            foreach ($data['StorageDetails'] as $storage) {
                $soc += $storage['soc'];
                $counter++;
            }
        }
        if ($counter) {
            return $soc / $counter;
        } else {
            return 0;
        }
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

    public function getShellyPro3EMResponse()
    {
        $value = null;
        if ($this->getIp()) {
            $smartFoxLatest = $this->getAllLatest();
            $smartFox = $this->getPowerIo();
            $power = $smartFox['power_io'];
            if (array_key_exists('StorageSocMean', $smartFoxLatest)) {
                if ($smartFoxLatest['StorageSocMean'] > 80 && $smartFoxLatest['StorageSoc'] >= 85) {
                    // battery SOC high over last 48 hours, don't charge higher than 85%
                    $power = max(0, $power); // announce no negative values in order not to charge battery
                } elseif ($smartFoxLatest['StorageSocMean'] < 30 && $smartFoxLatest['StorageSoc'] <= 40) {
                    // battery SOC low over last 48 hours, don't discharge lower than 40%
                    $power = min(0, $power); // announce no positive values in order not to discharge battery
                }
            }
            // prevent thermal stress (negative effects will occur from 35°C upwards)
            if (array_key_exists('StorageTemp', $smartFoxLatest) && $smartFoxLatest['StorageTemp'] > 30) {
                $power = $power/2; // if battery gets warm, limit charge/discharge to 1/2
            } elseif (array_key_exists('StorageTemp', $smartFoxLatest) && $smartFoxLatest['StorageTemp'] > 35) {
                $power = $power/4; // if battery gets warmer, limit charge/discharge to 1/4
            } elseif (array_key_exists('StorageTemp', $smartFoxLatest) && ($smartFoxLatest['StorageTemp'] > 40 || $smartFoxLatest['StorageTemp'] < 5)) {
                $power = 0; // if battery gets really warm or is very cold, do not charge/discharge
            }
            $value = ['total_act_power' => intval($power)];
        }

        return $value;
    }

    public function getFroniusV1MeterResponse()
    {
        $value = $this->getShellyPro3EMResponse();
        if (is_array($value)) {
            $now = new \DateTime();
            $value = [
                'Body' => [
                    'Data' => [
                        '0' => [
                            'Enable' => 1,
                            'Visible' => 1,
                            'Meter_Location_Current' => 0,
                            'PowerReal_P_Sum' => $value['total_act_power'],
                            "TimeStamp" => $now->getTimestamp(),
                        ],
                    ],
                ],
                'Head' => [
                    'RequestArguments' => [
                        'DeviceClass' => 'Meter',
                        'Scope' => 'System',
                    ],
                    'Status' => [
                        'Code' => 0,
                        'Reason' => '',
                        'UserMessage' => '',
                    ],
                    'Timestamp' => $now->format('c'),
                ],
            ];
        }

        return $value;
    }

    private function getFromREG9TE($full = true)
    {
        $arr = json_decode($this->client->request('GET', $this->basePath . '/all')->getContent(), true);
        if ($full){
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
        }

        return $arr;
    }

    private function getFromPRO($full = true)
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

        $values = ["power_io" => $data["detailsPowerValue"]];
        if ($full) {
            $values = array_merge($values, [
                "energy_in" => $data["energyValue"],
                "energy_out" => $data["eToGridValue"],
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
            ]);
            if ($this->hasAltPv()) {
                $values['altEnergyToday'] = $this->em->getRepository(SmartFoxDataStore::class)->getEnergyInterval($this->ip, 'PvEnergyAlt');
            }
            if ($this->hasStorage()) {
                $arr['storageEnergyToday_in'] = $this->em->getRepository(SmartFoxDataStore::class)->getEnergyInterval($this->ip, 'StorageEnergyIn');
                $arr['storageEnergyToday_out'] = $this->em->getRepository(SmartFoxDataStore::class)->getEnergyInterval($this->ip, 'StorageEnergyOut');
            }
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
                $maxStorageTemp = 0;
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
                if (array_key_exists('StorageSocMean', $latestEntry)) {
                    $latestStorageSocMean = $latestEntry['StorageSocMean'];
                } else {
                    $latestStorageSocMean = 0;
                }
                foreach ($this->connectors['smartfox']['storage'] as $storage) {
                    if ($storage['type'] == 'nelinor') {
                        $storageCounter++;
                        $storageData = $this->queryNelinor($storage['ip']);
                        $arr['StorageDetails'][$storage['name']] = $storageData;
                        if ($storageData['power'] >= 0) {
                            // charging battery
                            $totalStoragePowerIn += $storageData['power'];
                        } else {
                            // uncharging battery
                            $totalStoragePowerOut += $storageData['power'];
                        }
                        $totalStorageSoc += $storageData['soc'];
                        $maxStorageTemp = max($maxStorageTemp, $storageData['temp']);
                    }
                }
                // calculate the energy produced at the given power level during one minute
                $arr['StorageEnergyIn'] = round($latestStorageEnergyIn + 60*$totalStoragePowerIn/3600);
                $arr['StorageEnergyOut'] = round($latestStorageEnergyOut + 60*$totalStoragePowerOut/3600);
                $arr['StoragePower'] = $totalStoragePowerIn + $totalStoragePowerOut;
                $arr['StorageSoc'] = $totalStorageSoc/$storageCounter;
                $arr['StorageSocMean'] = ($latestStorageSocMean * 2879 + $arr['StorageSoc'])/2880; // sliding window over last 48hours (assuming we have one entry per minute)
                $arr['StorageTemp'] = $maxStorageTemp;
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
        $port = 9865; // fixed port of nelinor
        $retArr = [
            'status' => 0,
            'power' => 0,
            'temp' => 0,
            'soc' => 0
        ];
        $socket = null;
        try {
            $buf = '';
            $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
            if ($socket !== false &&
                    socket_connect($socket, $ip, $port) &&
                    false !== ($bytes = socket_recv($socket, $buf, 61, MSG_WAITALL))) {
                $retArr = [
                    'status' => unpack('C', $buf, 51)[1],
                    'power' => min(3000, max(-3000, unpack('l', $buf, 36)[1])), // limit to realistic values
                    'temp' => unpack('l', $buf, 45)[1],
                    'soc' => max(100, min(0, intval(unpack('l', $buf, 54)[1]/100))),
                ];
            }
            socket_close($socket);
        } catch (\Exception $e) {
            if ($socket !== null) {
                socket_close($socket);
            }
        }

        return $retArr;
    }
}
