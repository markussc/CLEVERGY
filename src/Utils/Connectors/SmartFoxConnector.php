<?php

namespace App\Utils\Connectors;

use App\Entity\SmartFoxDataStore;
use App\Entity\Settings;
use App\Service\SolarRadiationToolbox;
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
    protected $solRad;
    protected $client;
    protected $basePath;
    protected $ip;
    protected $version;
    private $connectors;

    public function __construct(EntityManagerInterface $em, SolarRadiationToolbox $solRad, HttpClientInterface $client, Array $connectors)
    {
        $this->em = $em;
        $this->solRad = $solRad;
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

    private function getConfig(): array
    {
        $config = null;
        $settings = $this->em->getRepository(Settings::class)->findOneByConnectorId($this->ip);
        if ($settings) {
            $config = $settings->getConfig();
        }
        if (!is_array($config)) {
            $ts = new \DateTime('-30 minutes');
            $config = [
                'timestamp' =>['date' => $ts->format('c')],
                'powerLimitFactor' => 0,
                'idleType' => null,
            ];
        }

        return $config;
    }

    private function saveConfig(array $config)
    {
        $settings = $this->em->getRepository(Settings::class)->findOneByConnectorId($this->ip);
        if (!$settings) {
            $settings = new Settings();
            $settings->setConnectorId($this->ip);
            $this->em->persist($settings);
        }
        $settings->setConfig($config);
        $this->em->flush();
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
        try {
            if ($this->getIp()) {
                $smartFoxLatest = $this->getAllLatest();
                $smartFox = $this->getPowerIo();
                $currentPower = $smartFox['power_io'];
                $power = $currentPower;
                $msg = null;
                $idleType = null;
                $now = new \DateTime();
                if (array_key_exists('StorageSocMean', $smartFoxLatest)) {
                    if (
                            $smartFoxLatest['StorageSocMean'] > 50 &&
                            $smartFoxLatest['StorageSoc'] > 15 &&
                            $smartFoxLatest['StorageSocMin24h'] > 10
                        ) {
                        // if we have
                        // - relatively larce mean SOC
                        // - some energy left
                        // - SOC over the last 24h never below SOC 10%
                        // --> increase reported power to minimize unnecessary net power consumption
                        if ($smartFoxLatest['PvPower'][0] > 500) {
                            $currentPower = $currentPower + ($smartFoxLatest['StorageSocMean'] + $smartFoxLatest['StorageSoc'] + $smartFoxLatest['StorageSocMin24h'])/2;
                        } else {
                            $currentPower = $currentPower + ($smartFoxLatest['StorageSocMean'] + $smartFoxLatest['StorageSocMin24h'])/1.5;
                        }
                        $power = $currentPower;
                    }
                    if (
                            $smartFoxLatest['PvPower'][0] > 0 &&
                            $smartFoxLatest['StorageSoc'] > ($smartFoxLatest['StorageSocMax48h'] - $smartFoxLatest['StorageSocMin48h'])/2 &&
                            $smartFoxLatest['StorageSoc'] < 95
                        ) {
                            $this->solRad->setSolarPotentials($smartFoxLatest['pvEnergyPrognosis']);
                            $chargingPower = 0;
                            $dischargingPower = 0;
                            $storCapacity = 0;
                            foreach ($this->connectors['smartfox']['storage'] as $stor) {
                                $chargingPower = $chargingPower + $stor['charging'];
                                $dischargingPower = $dischargingPower + $stor['discharging'];
                                $storCapacity = $storCapacity + $stor['capacity'];
                            }
                            // the base load will be assumed with 1/12 of the storage capacity (battery should be sufficient to supply base load for 12 hours)
                            $storEnergyPotential = $this->solRad->checkEnergyRequest($chargingPower, $dischargingPower, $storCapacity/12);
                            if (
                                $storEnergyPotential > 1.5 * (100-$smartFoxLatest['StorageSoc'])/100 * $storCapacity
                            ) {
                            // if we have
                            // - PV production
                            // - current SOC higher than half of mean of last 48h's max/min values but below 95% (values between 95 - 100% shall be managed by the BMS itself)
                            // - prognosis says chances are good to still reach more than 1.5 times residuum to full battery
                            $power = max(-10, $currentPower); // announce no negative values in order not to charge battery
                            if ($power < 0) {
                                $msg = 'Mean SOC high, do not charge to more than required according to remaining charging time today';
                                $idleType = 'charge';
                            }
                        }
                    }
                    if ($smartFoxLatest['StorageSocMean'] < 20 && $smartFoxLatest['StorageSoc'] <= 15) {
                        // battery SOC low over last 48 hours, don't discharge lower than 15%
                        $power = min(10, $currentPower); // announce no positive values in order not to discharge battery
                        if ($power > 0) {
                            $msg = 'Mean SOC low, do not discharge below 15%';
                            $idleType = 'discharge';
                        }
                    }
                    if ($smartFoxLatest['StorageSocMean'] < 20 && $cloudiness > 75 && $smartFoxLatest['StorageSoc'] <= 10) {
                        // low mean soc, cloudy sky expected in near future, therefore do not discharge below 10%
                        $power = min(10, $currentPower); // announce no positive values in order not to discharge battery
                        if ($power > 0) {
                            $msg = 'Mean SOC low, cloudy sky expected, do not discharge below 10%';
                            $idleType = 'discharge';
                        }
                    }
                    if ($now->format('H') >= 16 && $smartFoxLatest['StorageSoc'] <= 10) {
                        // do not discharge below 10% after 4pm
                        $power = min(10, $currentPower);
                        if ($power > 0) {
                            $msg = 'Do not discharge below 10% after 4pm';
                            $idleType = 'discharge';
                        }
                    } elseif ($now->format('H') < 5 && $smartFoxLatest['StorageSoc'] <= 5) {
                        // do not discharge below 5% before 5am
                        $power = min(10, $currentPower);
                        if ($power > 0) {
                            $msg = 'Do not discharge below 5% before 5am';
                            $idleType = 'discharge';
                        }
                    }
                    if ($smartFoxLatest['StorageSocMean'] < 15 && $smartFoxLatest['StorageSoc'] <= 10) {
                        // extremely low battery SOC, charge battery to 10% by accepting net consumption
                        $power = -100;
                    }
                }
                if (array_key_exists('StorageTemp', $smartFoxLatest) && ($smartFoxLatest['StorageTemp'] > 36 || $smartFoxLatest['StorageTemp'] < 5)) {
                    // if battery gets really warm or is very cold, do not charge/discharge
                    $msg = 'Excess cell temperature, do not use battery until normalized';
                }
                $config = $this->getConfig();
                if ($msg === null && ((($power > 50 && $idleType == 'charge' || $power < -50 && $idleType == 'discharge') && new \DateTime($config['timestamp']['date']) < new \DateTime('- 5 minutes')) || new \DateTime($config['timestamp']['date']) < new \DateTime('- 30 minutes'))) {
                    $value = ['total_act_power' => $power];
                    $config['powerLimitFactor'] = 0;
                    $config['idleType'] = null;
                } else {
                    if (!$msg) {
                        $msg = 'waiting for restart of charging and discharching';
                    }
                    if ($config['powerLimitFactor'] == 0) {
                        // no or outdated power limitation
                        $power = $power;
                        $config['powerLimitFactor'] = 1;
                        $config['idleType'] = $idleType;
                        $config['timestamp'] = new \DateTime();
                        $value = ['message' => 'starting to limit power by factor ' . $config['powerLimitFactor'], 'total_act_power' => $power];
                    } elseif ($config['powerLimitFactor'] < min(5, abs($smartFoxLatest['StoragePower']/30) - 1)) {
                        if ($smartFoxLatest['StoragePower'] > 0) {
                            $power = max(0, $smartFoxLatest['StoragePower'] - ($config['powerLimitFactor']-1) * 100);
                        } else {
                            $power = min(0, $smartFoxLatest['StoragePower'] + ($config['powerLimitFactor']-1) * 100);
                        }
                        $config['powerLimitFactor'] = $config['powerLimitFactor'] + 1;
                        $value = ['message' => 'starting to limit power by factor ' . $config['powerLimitFactor'], 'total_act_power' => $power];
                    } else {
                        $value = ['message' => $msg];
                    }
                }
                $this->saveConfig($config);
            }
        } catch (\Exception $e) {
            $value = ['message' => 'Exception during SmartFox value retrieval'];
        }

        return $value;
    }

    public function getFroniusV1MeterResponse($cloudiness = 0)
    {
        $value = $this->getShellyPro3EMResponse($cloudiness);
        if (is_array($value) && array_key_exists('total_act_power', $value)) {
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
            $arr['pvEnergyLast24h'] = $this->em->getRepository(SmartFoxDataStore::class)->getEnergyInterval($this->ip, 'PvEnergy', new \DateTime('-24 hours'), new \DateTime('now'));
            $arr["pvEnergyPrognosis"] = $this->solRad->getSolarPotentials();
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
                "pvEnergyLast24h" => $this->em->getRepository(SmartFoxDataStore::class)->getEnergyInterval($this->ip, 'PvEnergy', new \DateTime('-24 hours'), new \DateTime('now')),
                "pvEnergyPrognosis" => $this->solRad->getSolarPotentials(),
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
                $arr['StorageSocMin24h'] = $this->em->getRepository(SmartFoxDataStore::class)->getMin($this->ip, 24*60, 'StorageSoc');
                $arr['StorageSocMin48h'] = $this->em->getRepository(SmartFoxDataStore::class)->getMin($this->ip, 48*60, 'StorageSoc');
                $arr['StorageSocMax24h'] = $this->em->getRepository(SmartFoxDataStore::class)->getMax($this->ip, 24*60, 'StorageSoc');
                $arr['StorageSocMax48h'] = $this->em->getRepository(SmartFoxDataStore::class)->getMax($this->ip, 48*60, 'StorageSoc');
            } else {
                // add existing data
                $arr['StorageDetails'] = $latestEntry['StorageDetails'];
                $arr['StorageEnergyIn'] = $latestEntry['StorageEnergyIn'];
                $arr['StorageEnergyOut'] = $latestEntry['StorageEnergyOut'];
                $arr['StoragePower'] = $latestEntry['StoragePower'];
                $arr['StorageSoc'] = $latestEntry['StorageSoc'];
                $arr['StorageSocMean'] = $latestEntry['StorageSocMean'];
                $arr['StorageTemp'] = $latestEntry['StorageTemp'];
                $arr['StorageSocMin24h'] = $latestEntry['StorageSocMin24h'];
                $arr['StorageSocMin48h'] = $latestEntry['StorageSocMin48h'];
                $arr['StorageSocMax24h'] = $latestEntry['StorageSocMax24h'];
                $arr['StorageSocMax48h'] = $latestEntry['StorageSocMax48h'];
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

    private function queryNelinor($ip, $counter = 0)
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
                $charging = min(2300, max(-2300, intval(unpack('l', $buf, 60)[1])));
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
                    'temp' => min(100, max(-100, intval(unpack('l', $buf, 69)[1]) / 100)),
                    'soc' => $soc,
                ];
            }
            socket_close($socket);
        } catch (\Exception $e) {
            if ($socket !== null) {
                socket_close($socket);
            }
        }

        // retry if received values might be corrupted
        if ($retArr['status'] == 0 && $counter < 1) {
            // maybe offline, try again once
            sleep($counter + 1);
            $retArr = $this->queryNelinor($ip, $counter+1);
        } elseif (($retArr['temp'] == -100 || $retArr['temp'] == 100) && $counter < 2) {
            sleep($counter + 1);
            // very unlikely temperature, try again twice
            $retArr = $this->queryNelinor($ip, $counter+1);
        } elseif ($retArr['soc'] == 0 && $retArr['power'] == -2300 && $counter < 2) {
            // rather unlikely to uncharge will full power while soc is zero, try again twice
            sleep($counter + 1);
            $retArr = $this->queryNelinor($ip, $counter+1);
        }
        return $retArr;
    }
}
