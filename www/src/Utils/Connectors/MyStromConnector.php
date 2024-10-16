<?php

namespace App\Utils\Connectors;

use Doctrine\ORM\EntityManagerInterface;
use App\Entity\Settings;
use App\Entity\MyStromDataStore;
use App\Utils\ConfigManager;
use App\Utils\Connectors\EcarConnector;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Connector to retrieve data from MyStrom devices
 * For information refer to www.mystrom.ch or api.mystrom.ch
 *
 * @author Markus Schafroth
 */
class MyStromConnector
{
    protected $cm;
    protected $em;
    protected $client;
    protected $ecar;
    protected $connectors;
    protected $host;
    protected $session_cookie_path;

    public function __construct(ConfigManager $cm, EntityManagerInterface $em, HttpClientInterface $client, EcarConnector $ecar, Array $connectors, $host, $session_cookie_path)
    {
        $this->cm = $cm;
        $this->em = $em;
        $this->client = $client;
        $this->ecar = $ecar;
        $this->connectors = $connectors;
        $this->host = $host;
        $this->session_cookie_path = $session_cookie_path;
    }

    public function hasConnectorId($connectorId)
    {
        $exists = false;
        if (array_key_exists('mystrom', $this->connectors)) {
            foreach ($this->connectors['mystrom'] as $deviceConf) {
                if (isset($deviceConf['ip']) && $deviceConf['ip'] == $connectorId) {
                    $exists = true;
                    break;
                }
            }
        }

        return $exists;
    }

    public function getId($device)
    {
        return $device['ip'];
    }

    public function getAlarms()
    {
        $alarms = [];
        if (array_key_exists('mystrom', $this->connectors)) {
            foreach ($this->connectors['mystrom'] as $deviceConf) {
                if (array_key_exists('type', $deviceConf) && $deviceConf['type'] == 'motion') {
                    $status = $this->em->getRepository(MyStromDataStore::class)->getLatest($deviceConf['ip']);
                    if ($status) {
                        $alarms[] = [
                            'name' => $deviceConf['name'],
                            'state' => 'label.device.status.motion_detected',
                            'type' => 'motion',
                        ];
                    }
                }
            }
        }

        return $alarms;
    }

    public function motionAvailable()
    {
        if (array_key_exists('mystrom', $this->connectors)) {
            foreach ($this->connectors['mystrom'] as $deviceConf) {
                if (array_key_exists('type', $deviceConf) && $deviceConf['type'] == 'motion') {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Reads the latest available data from the database
     * @return array
     */
    public function getAllLatest()
    {
        $results = [];
        $today = new \DateTime('today');
        $now = new \DateTime();
        if (array_key_exists('mystrom', $this->connectors) && is_array($this->connectors['mystrom'])) {
            foreach ($this->cm->getConnectorIds('mystrom') as $connectorId) {
                $mode = $this->em->getRepository(Settings::class)->getMode($connectorId);
                $config = $this->cm->getConfig('mystrom', $connectorId);
                if (isset($config['nominalPower'])) {
                    $nominalPower = $config['nominalPower'];
                } else {
                    $nominalPower = 0;
                }
                if (isset($config['autoIntervals'])) {
                    $autoIntervals = $config['autoIntervals'];
                } else {
                    $autoIntervals = [];
                }
                if (isset($config['type'])) {
                    $type = $config['type'];
                } else {
                    $type = 'relay';
                }
                $result = [
                    'ip' => $config['ip'],
                    'name' => $config['name'],
                    'type' => $type,
                    'status' => $this->createStatus($this->em->getRepository(MyStromDataStore::class)->getLatestExtended($connectorId)),
                    'nominalPower' => $nominalPower,
                    'autoIntervals' => $autoIntervals,
                    'mode' => $mode,
                    'activeMinutes' => $this->em->getRepository(MyStromDataStore::class)->getActiveDuration($connectorId, $today, $now),
                    'timerData' => $this->getTimerData($config),
                    'carTimerData' => $this->getCarTimerData($config),
                ];
                if (is_array($result['status']) && array_key_exists('power', $result['status'])) {
                    $result['consumption_day'] = $this->em->getRepository(MyStromDataStore::class)->getConsumption($connectorId, $today, $now);
                    $result['consumption_yesterday'] = $this->em->getRepository(MyStromDataStore::class)->getConsumption($connectorId, new \DateTime('yesterday'), $today);
                }
                $results[] = $result;
            }
        }

        return $results;
    }

    public function getAll()
    {
        $results = [];

        if (array_key_exists('mystrom', $this->connectors) && is_array($this->connectors['mystrom'])) {
            foreach ($this->connectors['mystrom'] as $device) {
                $results[] = $this->getOne($device);
            }
        }

        return $results;
    }

    public function getOne($device)
    {
        $today = new \DateTime('today');
        $now = new \DateTime();

        $status = $this->getStatus($device);
        $mode = $this->em->getRepository(Settings::class)->getMode($device['ip']);
        $config = $this->cm->getConfig('mystrom', $device['ip']);
        if (isset($config['nominalPower'])) {
            $nominalPower = $config['nominalPower'];
        } else {
            $nominalPower = 0;
        }
        if (isset($config['autoIntervals'])) {
            $autoIntervals = $config['autoIntervals'];
        } else {
            $autoIntervals = [];
        }
        if (isset($config['type'])) {
            $type = $config['type'];
        } else {
            $type = 'relay';
        }

        $result = [
            'ip' => $config['ip'],
            'name' => $config['name'],
            'type' => $type,
            'status' => $status,
            'nominalPower' => $nominalPower,
            'autoIntervals' => $autoIntervals,
            'mode' => $mode,
            'activeMinutes' => $this->em->getRepository(MyStromDataStore::class)->getActiveDuration($config['ip'], $today, $now),
            'timerData' => $this->getTimerData($device),
            'carTimerData' => $this->getCarTimerData($device),
        ];
        if (array_key_exists('power', $result['status'])) {
            $result['consumption_day'] = $this->em->getRepository(MyStromDataStore::class)->getConsumption($config['ip'], $today, $now);
            $result['consumption_yesterday'] = $this->em->getRepository(MyStromDataStore::class)->getConsumption($config['ip'], new \DateTime('yesterday'), $today);
        }

        return $result;
    }

    public function activateAllPIR(): void
    {
        if (array_key_exists('mystrom', $this->connectors) && is_array($this->connectors['mystrom'])) {
            foreach ($this->connectors['mystrom'] as $key => $device) {
                if (isset($device['type']) && $device['type'] == 'motion') {
                    $this->executeCommand($key, 10);
                }
            }
        }
    }

    public function executeCommand($deviceId, $command)
    {
        if (strpos(strval($command), 'timer') === 0) {
            $timer = explode('timer_', $command);
            if (count($timer) == 2) {
                return $this->startTimer($this->connectors['mystrom'][$deviceId], $timer[1]);
            }
        }
        if (strpos(strval($command), 'cartimer_') === 0) {
            $command = str_replace("cartimer_", "", $command);
            $cartimer = explode('_', $command);
            if (count($cartimer) == 3) {
                return $this->startCarTimer($this->connectors['mystrom'][$deviceId], $cartimer);
            }
        }
        switch ($command) {
            case 1:
                // turn it on
                return $this->setOn($this->connectors['mystrom'][$deviceId]);
            case 0:
                // turn it off
                return $this->setOff($this->connectors['mystrom'][$deviceId]);
            case 10:
                // activate PIR action
                return $this->activatePIR($this->connectors['mystrom'][$deviceId]);
        }
        // no known command
        return false;
    }

    public function switchOK($deviceId)
    {
        // check if manual mode is set
        if ($this->em->getRepository(Settings::class)->getMode($this->connectors['mystrom'][$deviceId]['ip']) == Settings::MODE_MANUAL) {
            return false;
        }

        // check if autoIntervals or nominalPower are set (if not, this is not a device to be managed automatically)
        if (!isset($this->connectors['mystrom'][$deviceId]['autoIntervals']) && !isset($this->connectors['mystrom'][$deviceId]['nominalPower'])) {
            return false;
        }

        // check if any autoIntervals are set (and if so, whether we are inside)
        if (isset($this->connectors['mystrom'][$deviceId]['autoIntervals']) && is_array($this->connectors['mystrom'][$deviceId]['autoIntervals'])) {
            $autoOK = false;
            $nowH = (int)date('H');
            $nowM = (int)date('i');
            foreach ($this->connectors['mystrom'][$deviceId]['autoIntervals'] as $autoInterval) {
                $startArr = explode(":", $autoInterval[0]);
                $endArr = explode(":", $autoInterval[1]);
                $startH = (int)$startArr[0];
                $startM = (int)$startArr[1];
                $endH = (int)$endArr[0];
                $endM = (int)$endArr[1];
                if (($nowH*60 + $nowM >= $startH*60 + $startM) && ($nowH*60 + $nowM < $endH*60 + $endM)) {
                    $autoOK = true;
                }
            }
            if (!$autoOK) {
                return false;
            }
        }

        // get current status
        $currentStatus = $this->getStatus($this->connectors['mystrom'][$deviceId])['val'];

        // check if it is a fully loaded battery
        if (!$currentStatus && isset($this->connectors['mystrom'][$deviceId]['type']) && $this->connectors['mystrom'][$deviceId]['type'] == 'battery') {
            $tdata = $this->getTimerData($this->connectors['mystrom'][$deviceId]);
            if (array_key_exists('activePercentage', $tdata) && $tdata['activePercentage'] >= 100) {
                return false;
            }
        }

        // get latest timestamp with opposite status
        $oldStatus = $this->em->getRepository(MyStromDataStore::class)->getLatest($this->connectors['mystrom'][$deviceId]['ip'], ($currentStatus + 1)%2);
        if (count($oldStatus) == 1) {
            $oldTimestamp = $oldStatus[0]->getTimestamp();

            // calculate time diff
            $now = new \DateTime('now');
            $diff = ($now->getTimestamp() - $oldTimestamp->getTimestamp())/60; // diff in minutes
            if ($currentStatus) {
                // currently on, we want to switch off
                $minOnTime = array_key_exists('minOnTime', $this->connectors['mystrom'][$deviceId])?$this->connectors['mystrom'][$deviceId]['minOnTime']:15;
                if ($diff > $minOnTime) {
                    // check the minOnTime
                    return true;
                }
            } else {
                // currently off, we want to switch on
                $minOffTime = array_key_exists('minOffTime', $this->connectors['mystrom'][$deviceId])?$this->connectors['mystrom'][$deviceId]['minOffTime']:15;
                if ($diff > $minOffTime) {
                    return true;
                }
            }
        }

        return false;
    }

    public function getStatus($device)
    {
        if (array_key_exists('type', $device) && $device['type'] == 'motion') {
            $r = $this->queryMyStrom($device, 'motion');
            $arrKey = 'motion';
        } else {
            $r = $this->queryMyStrom($device, 'status');
            $arrKey = 'relay';
        }
        if (!empty($r) && array_key_exists($arrKey, $r) && $r[$arrKey] == true) {
            $status = true;
            if (array_key_exists('power', $r)) {
                $status = [
                    'val' => 1,
                    'power' => $r['power']
                ];
            }
            return $this->createStatus($status);
        } else {
            if (is_array($r) && array_key_exists('power', $r)) {
                $power = $r['power'];
            } else {
                $power = 0;
            }
            $status = [
                'val' => 0,
                'power' => $power
            ];
            if ($r === false) {
                $status['offline'] = true;
            }

            return $this->createStatus($status);
        }
    }

    public function storeStatus($device, $status): void
    {
        $connectorId = $this->getId($device);
        $mystromEntity = new MyStromDataStore();
        $mystromEntity->setTimestamp(new \DateTime('now'));
        $mystromEntity->setConnectorId($connectorId);
        $mystromEntity->setData($status['val']);
        if (array_key_exists('power', $status)) {
            $mystromEntity->setExtendedData($status);
            if (array_key_exists('nominalPower', $device) && $device['nominalPower'] > 0 && ($status['power'] > 0 || !$this->cm->hasDynamicConfig($connectorId))) {
                // update nominalPower with the current power of the device if consumption is detected
                $this->cm->updateConfig($connectorId, ['nominalPower' => $status['power']]);
            }
        }
        $this->em->persist($mystromEntity);
        $this->em->flush();
    }

    private function createStatus($status)
    {
        $ret = [];
        if ((is_array($status) && array_key_exists('val', $status) && $status['val']) || $status === true) {
            $ret =  [
                'label' => 'label.device.status.on',
                'val' => 1,
            ];
        } else {
            $ret = [
                'label' => 'label.device.status.off',
                'val' => 0,
            ];
        }
        if (is_array($status) && array_key_exists('power', $status)) {
            $ret['power'] = $status['power'];
        }
        if (isset($status['offline'])) {
            $ret['offline'] = true;
        }

        return $ret;
    }

    private function setOn($device)
    {
        $r = $this->queryMyStrom($device, 'on');
        if (false !== $r) {
            // get the current (new) status and store to database
            $status = $this->getStatus($device);
            $this->storeStatus($device, $status);
            $this->enableCarCharger($device);
            return true;
        } else {
            return false;
        }
    }

    private function setOff($device, $recursionDepth = 0)
    {
        $ok = $this->disableCarCharger($device);
        if ($recursionDepth < 10 && !$ok) {
            // car is still charging, we do not want to forcibly turn off the switch yet (we wait max. 100 seconds for it to complete)
            // try again within 10 seconds
            // wait 10 seconds before continuation
            sleep(10);
            $this->setOff($device, $recursionDepth++);
            return false;
        }
        $r = $this->queryMyStrom($device, 'off');
        if (false !== $r) {
            // get the current (new) status and store to database
            $status = $this->getStatus($device);
            $this->storeStatus($device, $status);
            return true;
        } else {
            return false;
        }
    }

    private function activatePIR($device)
    {
        $url = '/api/v1/action/pir/generic';
        $payload = 'get://'.$this->host.$this->session_cookie_path.'trigger/'.$device['ip'];
        $r = $this->postMyStrom($device, $url, $payload);
        if (!empty($r)) {
            return true;
        } else {
            return false;
        }
    }

    private function queryMyStrom($device, $cmd)
    {
        switch ($cmd) {
            case 'status':
                $reqUrl = 'report';
                break;
            case 'motion':
                $reqUrl = 'api/v1/motion';
                break;
            case 'on':
                $reqUrl = 'relay?state=1';
                break;
            case 'off':
                $reqUrl =  'relay?state=0';
                break;
            default:
                $reqUrl = '';
        }
    
        $url = 'http://' . $device['ip'] . '/' . $reqUrl;
        try {
            $response = $this->client->request('GET', $url);
            $statusCode = $response->getStatusCode();
            if ($statusCode != 200) {
                return false;
            }
            $json = $response->getContent();

            return json_decode($json, true);
        } catch (\Exception $e) {
            return false;
        }
    }

    private function postMyStrom($device, $reqUrl, $payload)
    {
        $url = 'http://' . $device['ip'] . '/' . $reqUrl;
        try {
            $response = $this->client->request(
                    'POST',
                    $url,
                    [
                        'body' => $payload
                    ]
                );
            $statusCode = $response->getStatusCode();
            if ($statusCode != 200) {
                return false;
            }
            $json = $response->getContent();

            return json_decode($json, true);
        } catch (\Exception $e) {
            return false;
        }
    }

    public function getConfig($ip)
    {
        $config = $this->cm->getConfig('mystrom', $ip);
        if (is_array($config) && array_key_exists('type', $config) && $config['type'] == 'carTimer') {
            $config['carTimerData'] = $this->getCarTimerData($config);
        }

        return $config;
    }

    private function getTimerData($device)
    {
        $timerData =  [];
        if (array_key_exists('type', $device) && $device['type'] == 'battery') {
            $connectorId = $device['ip'];
            $now = new \DateTime();
            $device = $this->em->getRepository(Settings::class)->findOneByConnectorId($connectorId);
            if ($device) {
                $config = $device->getConfig();
                if (is_array($config) && array_key_exists('startTime', $config)) {
                    $startTime = date_create($config['startTime']['date']);
                    $activeDuration = $this->em->getRepository(MyStromDataStore::class)->getActiveDuration($connectorId, $startTime, $now); // in minutes since started
                    $activePercentage = 100;
                    if ($config['activeTime'] > 0) {
                        $activePercentage = intval(100/$config['activeTime']*$activeDuration/60);
                    }
                    $timerData = [
                        'startTime' => $startTime,
                        'activeTime' => $config['activeTime'],
                        'activeDuration' => $activeDuration,
                        'activePercentage' =>  $activePercentage,
                    ];
                }
            }
        }

        return $timerData;
    }

    private function getCarTimerData($device)
    {
        $carTimerData =  [];
        if (array_key_exists('type', $device) && $device['type'] == 'carTimer') {
            $connectorId = $device['ip'];
            $deviceSettings = $this->em->getRepository(Settings::class)->findOneByConnectorId($connectorId);
            if ($deviceSettings) {
                $config = $deviceSettings->getConfig();
                if (array_key_exists('ecar', $this->connectors) && is_array($config) && array_key_exists('carId', $config)  && array_key_exists('deadline', $config)  && array_key_exists('percent', $config) && array_key_exists($config['carId'], $this->connectors['ecar'])) {
                    $carTimerData = [
                        'carId' => $config['carId'],
                        'connectorId' => $this->connectors['ecar'][$config['carId']]['carId'],
                        'capacity' => $this->connectors['ecar'][$config['carId']]['capacity'],
                        'deadline' => $config['deadline'],
                        'percent' => $config['percent'],
                        'plugStatus' => $this->getStatus($device)['val'],
                    ];
                }
            }
        }

        return $carTimerData;
    }

    private function startTimer($deviceConf, $activeTime): void
    {
        $device = $this->em->getRepository(Settings::class)->findOneByConnectorId($deviceConf['ip']);
        if (!$device) {
            $device = new Settings();
            $device->setConnectorId($deviceConf['ip']);
            $device->setMode(Settings::MODE_AUTO);
            $this->em->persist($device);
        }
        $config = $device->getConfig();
        $config['activeTime'] = intval($activeTime);
        $config['startTime'] = new \DateTime('now');
        $device->setConfig($config);
        $this->em->flush($device);
    }

    private function startCarTimer($deviceConf, $timerConf): void
    {
        $device = $this->em->getRepository(Settings::class)->findOneByConnectorId($deviceConf['ip']);
        if (!$device) {
            $device = new Settings();
            $device->setConnectorId($deviceConf['ip']);
            $device->setMode(Settings::MODE_AUTO);
            $this->em->persist($device);
        }
        $device->setType("carTimer");
        $config = $device->getConfig();
        $config['carId'] = intval($timerConf[0]);
        $config['deadline'] = new \DateTime($timerConf[1]);
        $config['percent'] = intval($timerConf[2]);
        $device->setConfig($config);
        $this->em->flush($device);
    }

    // if this is a carCharger which is currently charging, turn it off first
    private function disableCarCharger($deviceConf)
    {
        $retVal = true;
        if (array_key_exists('type', $deviceConf) && $deviceConf['type'] == 'carTimer') {
            $status = $this->getStatus($deviceConf);
            if ($status['val'] && array_key_exists('power', $status) && $status['power'] > 100) {
                // currently turned on with substantial power flow
                $config = $this->getConfig($deviceConf['ip']);
                if (is_array($config) && array_key_exists('carTimerData', $config) && is_array($config['carTimerData']) && array_key_exists('carId', $config['carTimerData'])) {
                    $carId = $config['carTimerData']['carId'];
                    $this->ecar->stopCharging($carId);
                    // we are trying to stop charging, we therefore do not allow immediate switch-off
                    $retVal = false;
                }
            }
        }

        return $retVal;
    }

    private function enableCarCharger($deviceConf)
    {
        if (array_key_exists('type', $deviceConf) && $deviceConf['type'] == 'carTimer') {
            // turn on the car charcher
            $config = $this->getConfig($deviceConf['ip']);
            if (is_array($config) && array_key_exists('carTimerData', $config) && is_array($config['carTimerData']) && array_key_exists('carId', $config['carTimerData'])) {
                $carId = $config['carTimerData']['carId'];
                $this->ecar->startCharging($carId);
            }
        }

        return true;
    }
}
