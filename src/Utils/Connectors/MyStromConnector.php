<?php

namespace App\Utils\Connectors;

use Doctrine\ORM\EntityManager;
use App\Entity\Settings;
use App\Entity\MyStromDataStore;

/**
 * Connector to retrieve data from MyStrom devices
 * For information refer to www.mystrom.ch or api.mystrom.ch
 *
 * @author Markus Schafroth
 */
class MyStromConnector
{
    protected $em;
    protected $browser;
    protected $connectors;

    public function __construct(EntityManager $em, \Buzz\Browser $browser, Array $connectors, $host, $session_cookie_path)
    {
        $this->em = $em;
        $this->browser = $browser;
        $this->connectors = $connectors;
        // set timeout for buzz browser client
        $this->browser->getClient()->setTimeout(3);
        $this->host = $host;
        $this->session_cookie_path = $session_cookie_path;
    }

    public function getAlarms()
    {
        $alarms = [];
        if (array_key_exists('mystrom', $this->connectors)) {
            foreach ($this->connectors['mystrom'] as $deviceConf) {
                if (array_key_exists('type', $deviceConf) && $deviceConf['type'] == 'motion') {
                    $status = $this->em->getRepository('App:MyStromDataStore')->getLatest($deviceConf['ip']);
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
            foreach ($this->connectors['mystrom'] as $device) {
                $mode = $this->em->getRepository('App:Settings')->getMode($device['ip']);
                if (isset($device['nominalPower'])) {
                    $nominalPower = $device['nominalPower'];
                } else {
                    $nominalPower = 0;
                }
                if (isset($device['autoIntervals'])) {
                    $autoIntervals = $device['autoIntervals'];
                } else {
                    $autoIntervals = [];
                }
                if (isset($device['type'])) {
                    $type = $device['type'];
                } else {
                    $type = 'relay';
                }
                $result = [
                    'ip' => $device['ip'],
                    'name' => $device['name'],
                    'type' => $type,
                    'status' => $this->createStatus($this->em->getRepository('App:MyStromDataStore')->getLatestExtended($device['ip'])),
                    'nominalPower' => $nominalPower,
                    'autoIntervals' => $autoIntervals,
                    'mode' => $mode,
                    'activeMinutes' => $this->em->getRepository('App:MyStromDataStore')->getActiveDuration($device['ip'], $today, $now),
                    'timerData' => $this->getTimerData($device),
                    'carTimerData' => $this->getCarTimerData($device),
                ];
                if (array_key_exists('power', $result['status'])) {
                    $result['consumption_day'] = $this->em->getRepository('App:MyStromDataStore')->getConsumption($device['ip'], $today, $now);
                    $result['consumption_yesterday'] = $this->em->getRepository('App:MyStromDataStore')->getConsumption($device['ip'], new \DateTime('yesterday'), $today);
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
        $mode = $this->em->getRepository('App:Settings')->getMode($device['ip']);
        if (isset($device['nominalPower'])) {
            $nominalPower = $device['nominalPower'];
        } else {
            $nominalPower = 0;
        }
        if (isset($device['autoIntervals'])) {
            $autoIntervals = $device['autoIntervals'];
        } else {
            $autoIntervals = [];
        }
        if (isset($device['type'])) {
            $type = $device['type'];
        } else {
            $type = 'relay';
        }

        $result = [
            'ip' => $device['ip'],
            'name' => $device['name'],
            'type' => $type,
            'status' => $status,
            'nominalPower' => $nominalPower,
            'autoIntervals' => $autoIntervals,
            'mode' => $mode,
            'activeMinutes' => $this->em->getRepository('App:MyStromDataStore')->getActiveDuration($device['ip'], $today, $now),
            'timerData' => $this->getTimerData($device),
            'carTimerData' => $this->getCarTimerData($device),
        ];
        if (array_key_exists('power', $result['status'])) {
            $result['consumption_day'] = $this->em->getRepository('App:MyStromDataStore')->getConsumption($device['ip'], $today, $now);
            $result['consumption_yesterday'] = $this->em->getRepository('App:MyStromDataStore')->getConsumption($device['ip'], new \DateTime('yesterday'), $today);
        }

        return $result;
    }

    public function activateAllPIR()
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
        if ($this->em->getRepository('App:Settings')->getMode($this->connectors['mystrom'][$deviceId]['ip']) == Settings::MODE_MANUAL) {
            return false;
        }

        // check if autoIntervals or nominalPower are set (if not, this is not a device to be managed automatically)
        if (!isset($this->connectors['mystrom'][$deviceId]['autoIntervals']) && !isset($this->connectors['mystrom'][$deviceId]['nominalPower'])) {
            return false;
        }

        // get current status
        $currentStatus = $this->getStatus($this->connectors['mystrom'][$deviceId])['val'];

        // get latest timestamp with opposite status
        $oldStatus = $this->em->getRepository('App:MyStromDataStore')->getLatest($this->connectors['mystrom'][$deviceId]['ip'], ($currentStatus + 1)%2);
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

            return $this->createStatus($status);
        }
    }

    public function storeStatus($device, $status)
    {
        $mystromEntity = new MyStromDataStore();
        $mystromEntity->setTimestamp(new \DateTime('now'));
        $mystromEntity->setConnectorId($device['ip']);
        $mystromEntity->setData($status['val']);
        if (array_key_exists('power', $status)) {
            $mystromEntity->setExtendedData($status);
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

        return $ret;
    }

    private function setOn($device)
    {
        $r = $this->queryMyStrom($device, 'on');
        if (!empty($r)) {
            // get the current (new) status and store to database
            $status = $this->getStatus($device);
            $this->storeStatus($device, $status);
            return true;
        } else {
            return false;
        }
    }

    private function setOff($device)
    {
        $r = $this->queryMyStrom($device, 'off');
        if (!empty($r)) {
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
            $response = $this->browser->get($url);
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
        $headers = [];
        $response = $this->browser->post($url, $headers, http_build_query([$payload]));
        try {
            $response = $this->browser->post($url, $headers, $payload);
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
        foreach ($this->connectors['mystrom'] as $device) {
            if ($device['ip'] == $ip) {
                if (array_key_exists('type', $device) && $device['type'] == 'carTimer') {
                    $device['carTimerData'] = $this->getCarTimerData($device);
                }
                return $device;
            }
        }
        return null;
    }

    private function getTimerData($device)
    {
        $timerData =  [];
        if (array_key_exists('type', $device) && $device['type'] == 'battery') {
            $connectorId = $device['ip'];
            $now = new \DateTime();
            $device = $this->em->getRepository('App:Settings')->findOneByConnectorId($connectorId);
            if ($device) {
                $config = $device->getConfig();
                if (is_array($config) && array_key_exists('startTime', $config)) {
                    $startTime = date_create($config['startTime']['date']);
                    $activeDuration = $this->em->getRepository('App:MyStromDataStore')->getActiveDuration($connectorId, $startTime, $now); // in minutes since started
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
            $device = $this->em->getRepository('App:Settings')->findOneByConnectorId($connectorId);
            if ($device) {
                $config = $device->getConfig();
                if (is_array($config) && array_key_exists('carId', $config)  && array_key_exists('deadline', $config)  && array_key_exists('percent', $config)) {
                    $carTimerData = [
                        'carId' => $config['carId'],
                        'connectorId' => $this->connectors['ecar'][$config['carId']]['carId'],
                        'capacity' => $this->connectors['ecar'][$config['carId']]['capacity'],
                        'deadline' => $config['deadline'],
                        'percent' => $config['percent'],
                    ];
                }
            }
        }

        return $carTimerData;
    }

    private function startTimer($deviceConf, $activeTime)
    {
        $device = $this->em->getRepository('App:Settings')->findOneByConnectorId($deviceConf['ip']);
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

    private function startCarTimer($deviceConf, $timerConf)
    {
        $device = $this->em->getRepository('App:Settings')->findOneByConnectorId($deviceConf['ip']);
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
}
