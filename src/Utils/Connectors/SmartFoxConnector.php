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

    public function getAll($updateStorage = false)
    {
        try {
            if ($this->version === "pro") {
                $responseArr = $this->getFromPRO();
            } else {
                $responseArr = $this->getFromREG9TE();
            }

            $responseArr = $this->addAlternativePv($responseArr);
            $responseArr = $this->addStorage($responseArr, $updateStorage);
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

    public function getShellyPro3EMResponse($cloudiness = 0)
    {
        $value = null;
        if ($this->getIp()) {
            $smartFoxLatest = $this->getAllLatest();
            $smartFox = $this->getPowerIo();
            $currentPower = $smartFox['power_io'];
            $now = new \DateTime();
            if (array_key_exists('StorageSocMean', $smartFoxLatest)) {
                if ($smartFoxLatest['StorageSocMean'] > 80 && $smartFoxLatest['StorageSoc'] >= 85) {
                    // battery SOC high over last 48 hours, don't charge higher than 85%
                    $power = max(0, $currentPower); // announce no negative values in order not to charge battery
                } elseif ($smartFoxLatest['StorageSocMean'] < 30 && $smartFoxLatest['StorageSoc'] <= 40) {
                    // battery SOC low over last 48 hours, don't discharge lower than 40%
                    if ($smartFoxLatest['StoragePower'] > 0 && $now->format('s')%60 < $smartFoxLatest['StoragePower']/23) {
                        $power = min(25, $currentPower);
                    } elseif ($smartFoxLatest['StoragePower'] < 0 && $now->format('s')%60 == 30) {
                        $power = min(-25, $currentPower);
                    }  else {
                        $power = min(0, $currentPower+30); // announce no positive values in order not to discharge battery
                    }
                }
                if ($cloudiness > 50 && $smartFoxLatest['StorageSoc'] <= 30) {
                    // cloudy sky expected in near future, therefore do not discharge below 30%
                    if ($smartFoxLatest['StoragePower'] > 0 && $now->format('s')%60 < $smartFoxLatest['StoragePower']/23) {
                        $power = min(25, $currentPower);
                    } elseif ($smartFoxLatest['StoragePower'] < 0 && $now->format('s')%60 == 30) {
                        $power = min(-25, $currentPower);
                    }  else {
                        $power = min(0, $currentPower+30); // announce no positive values in order not to discharge battery
                    }
                }
                if ($now->format('H') >= 16 && $smartFoxLatest['StorageSoc'] <= 20) {
                    // do not discharge below 20% after 4pm
                    $power = min(0, $currentPower+25);
                } elseif ($now->format('H') < 9 && $smartFoxLatest['StorageSoc'] <= 10) {
                    // do not discharge below 10% before 9am
                    $power = min(0, $currentPower+25);
                }
                if ($smartFoxLatest['StorageSocMean'] < 20 && $smartFoxLatest['StorageSoc'] <= 25) {
                    // extremely low battery SOC, charge battery to 25% by accepting net consumption
                    $power = -100;
                }
            }
            if (array_key_exists('StorageTemp', $smartFoxLatest) && ($smartFoxLatest['StorageTemp'] > 36 || $smartFoxLatest['StorageTemp'] < 5)) {
                $power = 0; // if battery gets really warm or is very cold, do not charge/discharge
            }
            $value = ['total_act_power' => intval($power)];
        }

        return $value;
    }

    public function getFroniusV1MeterResponse($cloudiness = 0)
    {
        $value = $this->getShellyPro3EMResponse($cloudiness);
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
                $values['storageEnergyToday_in'] = $this->em->getRepository(SmartFoxDataStore::class)->getEnergyInterval($this->ip, 'StorageEnergyIn');
                $values['storageEnergyToday_out'] = $this->em->getRepository(SmartFoxDataStore::class)->getEnergyInterval($this->ip, 'StorageEnergyOut');
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

    private function addStorage($arr, $update)
    {
        if (array_key_exists('smartfox', $this->connectors) && array_key_exists('storage', $this->connectors['smartfox'])) {
            $latestEntry = $this->getAllLatest();
            if ($update) {
                $storageCounter = 0;
                $totalStoragePowerIn = 0;
                $totalStoragePowerOut = 0;
                $totalStorageSoc = 0;
                $maxStorageTemp = 0;
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
            } else {
                // add existing data
                $arr['StorageDetails'] = $latestEntry['StorageDetails'];
                $arr['StorageEnergyIn'] = $latestEntry['StorageEnergyIn'];
                $arr['StorageEnergyOut'] = $latestEntry['StorageEnergyOut'];
                $arr['StoragePower'] = $latestEntry['StoragePower'];
                $arr['StorageSoc'] = $latestEntry['StorageSoc'];
                $arr['StorageSocMean'] = $latestEntry['StorageSocMean'];
                $arr['StorageTemp'] = $latestEntry['StorageTemp'];
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
                    false !== ($bytes = socket_recv($socket, $buf, 100, MSG_WAITALL))) {
                $charging = min(1800, max(-1800, intval(unpack('l', $buf, 60)[1])));
                $chargingDir = intval(unpack('c', $buf, 66)[1]);
                if ($chargingDir == 52) { // 52 means: discharge; 53 means: charge
                    // discharge battery
                    $charging = -1 * $charging;
                }
                $batteryLevel = min(7000, max(0, intval(unpack('l', $buf, 78)[1])));
                if (intval($batteryLevel) > 0) {
                    $soc = intval(100 / 7000 * $batteryLevel);
                } else {
                    $soc = 0;
                }
                $retArr = [
                    'status' => unpack('C', $buf, 51)[1],
                    'power' => $charging,
                    'temp' => intval(unpack('l', $buf, 69)[1]) / 100,
                    'soc' => $soc,
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
