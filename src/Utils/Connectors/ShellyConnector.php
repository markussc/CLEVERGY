<?php

namespace App\Utils\Connectors;

use Doctrine\ORM\EntityManagerInterface;
use App\Entity\Settings;
use App\Entity\ShellyDataStore;
use App\Utils\ConfigManager;
use App\Utils\Connectors\EcarConnector;
use App\Controller\ChromecastController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Connector to communicate with Shelly devices
 * For information refer to shelly.cloud
 *
 * @author Markus Schafroth
 */
class ShellyConnector
{
    protected $cm;
    protected $em;
    protected $ecar;
    private $client;
    private $baseUrl;
    private $authkey;
    protected $connectors;

    public function __construct(ChromecastController $cc, ConfigManager $cm, EntityManagerInterface $em, EcarConnector $ecar, HttpClientInterface $client, Array $connectors, $host, $session_cookie_path)
    {
        $this->cc = $cc;
        $this->cm = $cm;
        $this->em = $em;
        $this->ecar = $ecar;
        $this->client = $client;
        $this->baseUrl = 'https://undefined';
        $this->connectors = $connectors;
        $this->host = $host;
        $this->session_cookie_path = $session_cookie_path;
        if (array_key_exists('shellycloud', $connectors)) {
            $this->baseUrl = $connectors['shellycloud']['server'];
            $this->authkey = $connectors['shellycloud']['authkey'];
        }
    }

    public function hasConnectorId($connectorId)
    {
        $exists = false;
        if (array_key_exists('shelly', $this->connectors)) {
            foreach ($this->connectors['shelly'] as $deviceConf) {
                if ($this->getId($deviceConf) == $connectorId) {
                    $exists = true;
                    break;
                }
            }
        }

        return $exists;
    }

    public function getConfig($connectorId)
    {
        $config = $this->cm->getConfig('shelly', $connectorId);
        if (is_array($config) && array_key_exists('type', $config) && $config['type'] == 'carTimer') {
            $config['carTimerData'] = $this->getCarTimerData($config);
        }

        return $config;
    }

    /**
     * Reads the latest available data from the database
     * @return array
     */
    public function getAllLatest()
    {
        $results = [];

        if (array_key_exists('shelly', $this->connectors) && is_array($this->connectors['shelly'])) {
            foreach ($this->connectors['shelly'] as $device) {
                if (isset($device['type']) && $device['type'] == 'button') {
                    continue;
                }
                $result = $this->getOneLatest($device);
                if (is_array($result['status']) && array_key_exists('power', $result['status'])) {
                    $connectorId = $device['ip'].'_'.$device['port'];
                    $result['consumption_day'] = $this->em->getRepository(ShellyDataStore::class)->getConsumption($connectorId, new \DateTime('today'), new \DateTime('now'));
                    $result['consumption_yesterday'] = $this->em->getRepository(ShellyDataStore::class)->getConsumption($connectorId, new \DateTime('yesterday'), new \DateTime('today'));
                }
                $results[] = $result;
            }
        }

        return $results;
    }

    public function getAll()
    {
        $results = [];

        if (array_key_exists('shelly', $this->connectors) && is_array($this->connectors['shelly'])) {
            foreach ($this->connectors['shelly'] as $device) {
                if ($device['type'] == 'button') {
                    continue;
                }
                if ($device['type'] == 'door') {
                    // we query the shelly cloud api (max. 1 request per second, therefore a sleep after every query)
                    if (array_key_exists('cloudId', $device)) {
                        // we query the shelly cloud api (max. 1 request per second, therefore a sleep after every query)
                        $results[] = $this->getOne($device);
                        sleep(1);
                    } else {
                        // we don't have access to the shelly cloud for this device, therefore we get the latest stored value from database
                        $results[] = $this->getOneLatest($device);
                    }
                } else {
                    $results[] = $this->getOne($device);
                }
                if ($device['type'] == 'roller') {
                    // set configuration for action links
                    $this->queryShelly($device, 'configureOpen');
                    $this->queryShelly($device, 'configureClose');
                }
            }
        }

        return $results;
    }

    public function getOne($device)
    {
        $today = new \DateTime('today');
        $now = new \DateTime();

        if (!array_key_exists('port', $device)) {
            $device['port'] = 0;
        }
        $status = $this->getStatus($device);
        $connectorId = $device['ip'].'_'.$device['port'];
        $mode = $this->em->getRepository(Settings::class)->getMode($connectorId);
        $config = $this->cm->getConfig('shelly', $connectorId);
        if (!isset($config['port'])) {
            $config['port'] = 0;
        }
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

        $result = [
            'ip' => $config['ip'],
            'port' => $config['port'],
            'name' => $config['name'],
            'type' => $config['type'],
            'status' => $status,
            'nominalPower' => $nominalPower,
            'autoIntervals' => $autoIntervals,
            'mode' => $mode,
            'activeMinutes' => $this->em->getRepository(ShellyDataStore::class)->getActiveDuration($connectorId, $today, $now),
            'timerData' => $this->getTimerData($config),
            'carTimerData' => $this->getCarTimerData($config),
            'timestamp' => new \DateTime('now'),
        ];
        if (is_array($result['status']) && array_key_exists('power', $result['status'])) {
            $result['consumption_day'] = $this->em->getRepository(ShellyDataStore::class)->getConsumption($connectorId, $today, $now);
            $result['consumption_yesterday'] = $this->em->getRepository(ShellyDataStore::class)->getConsumption($connectorId, new \DateTime('yesterday'), new \DateTime('today'));
        }

        return $result;
    }

    public function getOneLatest($device)
    {
        $today = new \DateTime('today');
        $now = new \DateTime();
        if (!array_key_exists('port', $device)) {
            $device['port'] = 0;
        }

        $connectorId = $device['ip'].'_'.$device['port'];
        $mode = $this->em->getRepository(Settings::class)->getMode($connectorId);
        $config = $this->cm->getConfig('shelly', $connectorId);
        if (!isset($config['port'])) {
            $config['port'] = 0;
        }
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
        $latest = $this->em->getRepository(ShellyDataStore::class)->getLatest($connectorId);
        if (method_exists($latest, "getData")) {
            $status = $latest->getData();
            $timestamp = $latest->getTimestamp();
        } else {
            $status = 0;
            $timestamp = 0;
        }
        return [
            'ip' => $config['ip'],
            'port' => $config['port'],
            'name' => $config['name'],
            'type' => $config['type'],
            'status' => $status,
            'nominalPower' => $nominalPower,
            'autoIntervals' => $autoIntervals,
            'mode' => $mode,
            'activeMinutes' => $this->em->getRepository(ShellyDataStore::class)->getActiveDuration($connectorId, $today, $now),
            'timerData' => $this->getTimerData($config),
            'carTimerData' => $this->getCarTimerData($config),
            'timestamp' => $timestamp,
        ];
    }

    public function executeCommand($deviceId, $command)
    {
        if (strpos(strval($command), 'timer') === 0) {
            $timer = explode('timer_', $command);
            if (count($timer) == 2) {
                return $this->startTimer($this->connectors['shelly'][$deviceId], $timer[1]);
            }
        }
        if (strpos(strval($command), 'cartimer_') === 0) {
            $command = str_replace("cartimer_", "", $command);
            $cartimer = explode('_', $command);
            if (count($cartimer) == 3) {
                return $this->startCarTimer($this->connectors['shelly'][$deviceId], $cartimer);
            }
        }
    
        switch ($command) {
            case 100:
                if ($this->connectors['shelly'][$deviceId]['type'] == 'roller') {
                    $this->queryShelly($this->connectors['shelly'][$deviceId], 'configureOpen');
                    $this->queryShelly($this->connectors['shelly'][$deviceId], 'configureClose');
                } elseif ($this->connectors['shelly'][$deviceId]['type'] == 'door') {
                    $this->queryShelly($this->connectors['shelly'][$deviceId], 'configureDark');
                    $this->queryShelly($this->connectors['shelly'][$deviceId], 'configureTwilight');
                    $this->queryShelly($this->connectors['shelly'][$deviceId], 'configureDaylight');
                    $this->queryShelly($this->connectors['shelly'][$deviceId], 'configureClose');
                }
                return;
            case 1:
                // turn it on
                return $this->setOn($this->connectors['shelly'][$deviceId]);
            case 0:
                // turn it off
                return $this->setOff($this->connectors['shelly'][$deviceId]);
            case 2:
                // roller open
                return $this->setOpen($this->connectors['shelly'][$deviceId]);
            case 3:
                // roller close
                return $this->setClose($this->connectors['shelly'][$deviceId]);
            case -1:
                // roller stop
                return $this->setStop($this->connectors['shelly'][$deviceId]);
            case 'long':
                $actions = $this->getConfig($deviceId)['actions']['long'];
                return $this->executeButtonAction($actions);
            case 'short1':
                $actions = $this->getConfig($deviceId)['actions']['short1'];
                return $this->executeButtonAction($actions);
            case 'short2':
                $actions = $this->getConfig($deviceId)['actions']['short2'];
                return $this->executeButtonAction($actions);
            case 'short3':
                $actions = $this->getConfig($deviceId)['actions']['short3'];
                return $this->executeButtonAction($actions);
        }
        // no known command
        return false;
    }

    private function executeButtonAction($actions)
    {
        foreach ($actions as $action => $attr) {
            switch($action) {
                case 'Chromecast_Power':
                    return $this->cc->powerAction($attr['ccId'], -1);
                case 'Chromecast_Play':
                    return $this->cc->playAction($attr['ccId'], $attr['streamId']);
            }
        }

        return true;
    }

    public function switchOK($deviceId)
    {
        // check if manual mode is set
        if ($this->em->getRepository(Settings::class)->getMode($this->getId($this->connectors['shelly'][$deviceId])) == Settings::MODE_MANUAL) {
            return false;
        }

        // check if autoIntervals or nominalPower are set (if not, this is not a device to be managed automatically)
        if (!isset($this->connectors['shelly'][$deviceId]['autoIntervals']) && !isset($this->connectors['shelly'][$deviceId]['nominalPower'])) {
            return false;
        }

        // check if any autoIntervals are set (and if so, whether we are inside)
        if (isset($this->connectors['shelly'][$deviceId]['autoIntervals']) && is_array($this->connectors['shelly'][$deviceId]['autoIntervals'])) {
            $autoOK = false;
            $nowH = (int)date('H');
            $nowM = (int)date('i');
            foreach ($this->connectors['shelly'][$deviceId]['autoIntervals'] as $autoInterval) {
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
        $currentStatus = $this->getStatus($this->connectors['shelly'][$deviceId])['val'];

        // check if it is a fully loaded battery
        if (!$currentStatus && isset($this->connectors['shelly'][$deviceId]['type']) && $this->connectors['shelly'][$deviceId]['type'] == 'battery') {
            if ($this->getTimerData($this->connectors['shelly'][$deviceId])['activePercentage'] >= 100) {
                return false;
            }
        }

        // get latest timestamp with opposite status
        $roller = false;
        if ($this->connectors['shelly'][$deviceId]['type'] == 'roller') {
            $oppositeStatus = $currentStatus; // for rollers, we check for any other than the current status
            $roller = true;
        } else {
            $oppositeStatus = ($currentStatus + 1)%2;
        }
        $oldStatus = $this->em->getRepository(ShellyDataStore::class)->getLatest($this->getId($this->connectors['shelly'][$deviceId]), $oppositeStatus, $roller);
        if (count($oldStatus) == 1) {
            $oldTimestamp = $oldStatus[0]->getTimestamp();

            // calculate time diff
            $now = new \DateTime('now');
            $diff = ($now->getTimestamp() - $oldTimestamp->getTimestamp())/60; // diff in minutes
            if ($this->connectors['shelly'][$deviceId]['type'] == 'roller') {
                $minWaitTime = array_key_exists('minWaitTime', $this->connectors['shelly'][$deviceId])?$this->connectors['shelly'][$deviceId]['minWaitTime']:15;
                if ($diff > $minWaitTime) {
                    // check the minWaitTime
                    return true;
                }
            } else {
                if ($currentStatus) {
                    // currently on, we want to switch off
                    $minOnTime = array_key_exists('minOnTime', $this->connectors['shelly'][$deviceId])?$this->connectors['shelly'][$deviceId]['minOnTime']:15;
                    if ($diff > $minOnTime) {
                        // check the minOnTime
                        return true;
                    }
                } else {
                    // currently off, we want to switch on
                    $minOffTime = array_key_exists('minOffTime', $this->connectors['shelly'][$deviceId])?$this->connectors['shelly'][$deviceId]['minOffTime']:15;
                    if ($diff > $minOffTime) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    public function getStatus($device)
    {
        $r = $this->queryShelly($device, 'status');
        if (!empty($r) && $device['type'] == 'roller') {
            if (array_key_exists('state', $r) && $r['state'] == 'open') {
                return $this->createStatus(4, $r['current_pos']);
            }
            if (array_key_exists('state', $r) && $r['state'] == 'close') {
                return $this->createStatus(5, $r['current_pos']);
            }
            if (array_key_exists('last_direction', $r) && $r['last_direction'] == "open") {
                return $this->createStatus(2, $r['current_pos']);
            } else {
                return $this->createStatus(3, $r['current_pos']);
            }
        } elseif (!empty($r) && ($device['type'] == 'relay' || $device['type'] == 'battery' || $device['type'] == 'carTimer')) {
            if (array_key_exists('ison', $r) && $r['ison'] == true) {
                $powerResp = $this->queryShelly($device, 'power');
                if (!empty($powerResp) && array_key_exists('power', $powerResp)) {
                    $power = $powerResp['power'];
                } else {
                    $power = 0;
                }
                return $this->createStatus(1, 0, $power);
            } else {
                return $this->createStatus(0);
            }
        } elseif (!empty($r) && $device['type'] == 'door') {
            if (array_key_exists('sensor', $r) && $r['sensor']['state'] == "open") {
                return $this->createStatus(2, 100, 0, $r['bat']['value']);
            } else {
                return $this->createStatus(3, 100, 0, $r['bat']['value']);
            }
        } else {
            return $this->createStatus(0);
        }
    }

    public function createStatus($status, $position = 100, $power = 0, $battery = 100)
    {
        if ($status == 1) {
            return [
                'label' => 'label.device.status.on',
                'val' => 1,
                'power' => $power,
            ];
        } elseif ($status == 0) {
            return [
                'label' => 'label.device.status.off',
                'val' => 0,
                'power' => $power,
            ];
        } elseif ($status == 2) {
            return [
                'label' => 'label.device.status.open',
                'val' => 2,
                'position' => $position,
                'battery' => $battery,
            ];
        } elseif ($status == 3) {
            return [
                'label' => 'label.device.status.closed',
                'val' => 3,
                'position' => $position,
                'battery' => $battery,
            ];
        }
        elseif ($status == 4) {
            return [
                'label' => 'label.device.status.opening',
                'val' => 4,
                'position' => $position,
            ];
        } elseif ($status == 5) {
            return [
                'label' => 'label.device.status.closing',
                'val' => 5,
                'position' => $position,
            ];
        }
    }

    private function setOn($device)
    {
        $r = $this->queryShelly($device, 'on');
        if (!empty($r)) {
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
        $r = $this->queryShelly($device, 'off');
        if (!empty($r)) {
            // get the current (new) status and store to database
            $status = $this->getStatus($device);
            $this->storeStatus($device, $status);
            return true;
        } else {
            return false;
        }
    }

    private function setOpen($device)
    {
        $r = $this->queryShelly($device, 'open');
        if (!empty($r)) {
            $status = $this->getStatus($device);
            $this->storeStatus($device, $status);
            return true;
        } else {
            return false;
        }
    }

    private function setClose($device)
    {
        $r = $this->queryShelly($device, 'close');
        if (!empty($r)) {
            $status = $this->getStatus($device);
            $this->storeStatus($device, $status);
            return true;
        } else {
            return false;
        }
    }

    private function setStop($device)
    {
        $r = $this->queryShelly($device, 'stop');
        if (!empty($r)) {
            $status = $this->getStatus($device);
            $this->storeStatus($device, $status);
            return true;
        } else {
            return false;
        }
    }

    private function queryShelly($device, $cmd)
    {
        $reqUrl = '';
        if ($device['type'] == 'roller') {
            switch ($cmd) {
                case 'status':
                    $reqUrl = 'roller/'.$device['port'];
                    break;
                case 'open':
                    $reqUrl = 'roller/'.$device['port'].'?go=open';
                    break;
                case 'close':
                    $reqUrl = 'roller/'.$device['port'].'?go=close';
                    break;
                case 'stop':
                    $reqUrl = 'roller/'.$device['port'].'?go=stop';
                    break;
                case 'configureOpen':
                    $triggerUrl = 'http://'.$this->host.$this->session_cookie_path.'trigger/'.$device['ip'].'_'.$device['port'];
                    $reqUrl = 'settings/actions?index=0&name=roller_close_url&enabled=true&urls[]='.$triggerUrl.'/4';
                    break;
                case 'configureClose':
                    $triggerUrl = 'http://'.$this->host.$this->session_cookie_path.'trigger/'.$device['ip'].'_'.$device['port'];
                    $reqUrl = 'settings/actions?index=0&name=roller_close_url&enabled=true&urls[]='.$triggerUrl.'/5';
                    break;
            }
        } elseif ($device['type'] == 'relay' || $device['type'] == 'battery' || $device['type'] == 'carTimer') {
            switch ($cmd) {
                case 'status':
                    $reqUrl = 'relay/'.$device['port'];
                    break;
                case 'power':
                    $reqUrl = 'meter/'.$device['port'];
                    break;
                case 'on':
                    $reqUrl = 'relay/'.$device['port'].'?turn=on';
                    break;
                case 'off':
                    $reqUrl = 'relay/'.$device['port'].'?turn=off';
                    break;
            }
        } elseif ($device['type'] == 'door') {
            switch ($cmd) {
                case 'status':
                    if (array_key_exists('cloudId', $device)) {
                        return $this->getStatusCloud($device['cloudId']);
                    } else {
                        return false;
                    }
                case 'configureOpen':
                    $triggerUrl = 'http://'.$this->host.$this->session_cookie_path.'trigger/'.$device['ip'].'_0/2';
                    $reqUrl = 'settings/actions?index=0&name=open_url&enabled=true&urls[]='.$triggerUrl;
                    break;
                case 'configureClose':
                    $triggerUrl = 'http://'.$this->host.$this->session_cookie_path.'trigger/'.$device['ip'].'_0/3';
                    $reqUrl = 'settings/actions?index=0&name=close_url&enabled=true&urls[]='.$triggerUrl;
                    break;
            }
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

    public function storeStatus($device, $status)
    {
        if ($status !== null) {
            $connectorId = $this->getId($device);
            $shellyEntity = new ShellyDataStore();
            $shellyEntity->setTimestamp(new \DateTime('now'));
            $shellyEntity->setConnectorId($connectorId);
            $shellyEntity->setData($status);
            if (array_key_exists('power', $status)) {
                if (array_key_exists('nominalPower', $device) && $device['nominalPower'] > 0 && ($status['power'] > 0 || !$this->cm->hasDynamicConfig($connectorId))) {
                    // update nominalPower with the current power of the device if consumption is detected
                    $this->cm->updateConfig($connectorId, ['nominalPower' => $status['power']]);
                }
            }
            $this->em->persist($shellyEntity);
            $this->em->flush();
        }
    }

    public function getId($device)
    {
        if (!isset($device['port'])) {
            $device['port'] = 0;
        }
        return $device['ip'].'_'.$device['port'];
    }

    public function getAlarms()
    {
        $alarms = [];
        if (array_key_exists('shelly', $this->connectors)) {
            foreach ($this->connectors['shelly'] as $deviceConf) {
                if (array_key_exists('type', $deviceConf) && $deviceConf['type'] == 'door') {
                    if (!array_key_exists('port', $deviceConf)) {
                        $deviceConf['port'] = 0;
                    }
                    $latest = $this->em->getRepository(ShellyDataStore::class)->getLatest($deviceConf['ip'].'_'.$deviceConf['port']);
                    if (method_exists($latest, "getData")) {
                        $status = $latest->getData();
                        $timestamp = $latest->getTimestamp();
                    } else {
                        $status = $this->createStatus(2); // status = open
                        $timestamp = 0;
                    }
                    if (is_array($status) && array_key_exists('val', $status) && $status['val'] !== 3) {
                        // status = 3 means closed
                        $alarms[] = [
                            'name' => $deviceConf['name'],
                            'state' => $status['label'],
                            'timestamp' => $timestamp,
                            'type' => 'door',
                        ];
                    }
                }
            }
        }

        return $alarms;
    }

    public function doorAvailable()
    {
        if (array_key_exists('shelly', $this->connectors)) {
            foreach ($this->connectors['shelly'] as $deviceConf) {
                if (array_key_exists('type', $deviceConf) && $deviceConf['type'] == 'door') {
                    return true;
                }
            }
        }

        return false;
    }

    public function rollerRelayAvailable()
    {
        if (array_key_exists('shelly', $this->connectors)) {
            foreach ($this->connectors['shelly'] as $deviceConf) {
                if (array_key_exists('type', $deviceConf) && ($deviceConf['type'] == 'roller') || $deviceConf['type'] == 'relay' || $deviceConf['type'] == 'battery' || $deviceConf['type'] == 'carTimer') {
                    return true;
                }
            }
        }

        return false;
    }

    private function getStatusCloud($cloudId)
    {
        try {
            sleep(1); // API allows max. 1 call per second
            $response = $this->client->request(
                'POST',
                $this->baseUrl . '/device/status',
                [
                    'body' => [
                        'auth_key' => $this->authkey,
                        'id' => $cloudId,
                    ],
                ]
            );
            if ($response->getStatusCode() === Response::HTTP_OK) {
                $response = json_decode($response->getContent(), true);
                return $response['data']['device_status'];
            }
        } catch (\Exception $e) {
            return false;
        }
    }

    
    private function getTimerData($device)
    {
        $timerData =  [];
        if (array_key_exists('type', $device) && $device['type'] == 'battery') {
            $connectorId = $this->getId($device);
            $now = new \DateTime();
            $device = $this->em->getRepository(Settings::class)->findOneByConnectorId($connectorId);
            if ($device) {
                $config = $device->getConfig();
                if (is_array($config) && array_key_exists('startTime', $config)) {
                    $startTime = date_create($config['startTime']['date']);
                    $activeDuration = $this->em->getRepository(ShellyDataStore::class)->getActiveDuration($connectorId, $startTime, $now); // in minutes since started
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
            $connectorId = $this->getId($device);
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

    private function startTimer($deviceConf, $activeTime)
    {
        $connectorId = $this->getId($deviceConf);
        $device = $this->em->getRepository(Settings::class)->findOneByConnectorId($connectorId);
        if (!$device) {
            $device = new Settings();
            $device->setConnectorId($this->getId($connectorId));
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
        $connectorId = $this->getId($deviceConf);
        $device = $this->em->getRepository(Settings::class)->findOneByConnectorId($connectorId);
        if (!$device) {
            $device = new Settings();
            $device->setConnectorId($connectorId);
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
        $connectorId = $this->getId($deviceConf);
        $retVal = true;
        if (array_key_exists('type', $deviceConf) && $deviceConf['type'] == 'carTimer') {
            $status = $this->getStatus($deviceConf);
            if ($status['val'] && array_key_exists('power', $status) && $status['power'] > 100) {
                // currently turned on with substantial power flow
                $config = $this->getConfig($connectorId);
                $carId = $config['carTimerData']['carId'];
                $this->ecar->stopCharging($carId);
                // we are trying to stop charging, we therefore do not allow immediate switch-off
                $retVal = false;
            }
        }

        return $retVal;
    }

    private function enableCarCharger($deviceConf)
    {
        if (array_key_exists('type', $deviceConf) && $deviceConf['type'] == 'carTimer') {
            $connectorId = $this->getId($deviceConf);
            // turn on the car charcher
            $config = $this->getConfig($connectorId);
            $carId = $config['carTimerData']['carId'];
            $this->ecar->startCharging($carId);
        }

        return true;
    }
}
