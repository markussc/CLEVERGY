<?php

namespace App\Utils\Connectors;

use Doctrine\ORM\EntityManagerInterface;
use App\Entity\Settings;
use App\Entity\ShellyDataStore;
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
    protected $em;
    protected $browser;
    private $client;
    private $baseUrl;
    private $server;
    private $authkey;
    protected $connectors;

    public function __construct(EntityManagerInterface $em, HttpClientInterface $client, \Buzz\Browser $browser,Array $connectors, $host, $session_cookie_path)
    {
        $this->em = $em;
        $this->client = $client;
        $this->baseUrl = 'https://shelly-3-eu.shelly.cloud';
        $this->browser = $browser;
        $this->connectors = $connectors;
        // set timeout for buzz browser client
        $this->browser->getClient()->setTimeout(3);
        $this->host = $host;
        $this->session_cookie_path = $session_cookie_path;
        if (array_key_exists('shellycloud', $connectors)) {
            $this->server = $connectors['shellycloud']['server'];
            $this->authkey = $connectors['shellycloud']['authkey'];
        }
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
                $result = $this->getOneLatest($device);
                if (is_array($result['status']) && array_key_exists('power', $result['status'])) {
                    $connectorId = $device['ip'].'_'.$device['port'];
                    $result['consumption_day'] = $this->em->getRepository('App:ShellyDataStore')->getConsumption($connectorId, new \DateTime('today'), new \DateTime('now'));
                    $result['consumption_yesterday'] = $this->em->getRepository('App:ShellyDataStore')->getConsumption($connectorId, new \DateTime('yesterday'), new \DateTime('today'));
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
        $mode = $this->em->getRepository('App:Settings')->getMode($connectorId);
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

        $result = [
            'ip' => $device['ip'],
            'port' => $device['port'],
            'name' => $device['name'],
            'type' => $device['type'],
            'status' => $status,
            'nominalPower' => $nominalPower,
            'autoIntervals' => $autoIntervals,
            'mode' => $mode,
            'activeMinutes' => $this->em->getRepository('App:ShellyDataStore')->getActiveDuration($connectorId, $today, $now),
            'timestamp' => new \DateTime('now'),
        ];
        if (array_key_exists('power', $result['status'])) {
            $result['consumption_day'] = $this->em->getRepository('App:ShellyDataStore')->getConsumption($connectorId, $today, $now);
            $result['consumption_yesterday'] = $this->em->getRepository('App:ShellyDataStore')->getConsumption($connectorId, new \DateTime('yesterday'), new \DateTime('today'));
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
        $mode = $this->em->getRepository('App:Settings')->getMode($connectorId);
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
        $latest = $this->em->getRepository('App:ShellyDataStore')->getLatest($connectorId);
        if (method_exists($latest, "getData")) {
            $status = $latest->getData();
            $timestamp = $latest->getTimestamp();
        } else {
            $status = 0;
            $timestamp = 0;
        }
        return [
            'ip' => $device['ip'],
            'port' => $device['port'],
            'name' => $device['name'],
            'type' => $device['type'],
            'status' => $status,
            'nominalPower' => $nominalPower,
            'autoIntervals' => $autoIntervals,
            'mode' => $mode,
            'activeMinutes' => $this->em->getRepository('App:ShellyDataStore')->getActiveDuration($connectorId, $today, $now),
            'timestamp' => $timestamp,
        ];
    }

    public function executeCommand($deviceId, $command)
    {
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
        }
        // no known command
        return false;
    }

    public function switchOK($deviceId)
    {
        // check if manual mode is set
        if ($this->em->getRepository('App:Settings')->getMode($this->getId($this->connectors['shelly'][$deviceId])) == Settings::MODE_MANUAL) {
            return false;
        }

        // check if autoIntervals or nominalPower are set (if not, this is not a device to be managed automatically)
        if (!isset($this->connectors['shelly'][$deviceId]['autoIntervals']) && !isset($this->connectors['shelly'][$deviceId]['nominalPower'])) {
            return false;
        }

        // check if any autoIntervals are set (and if so, whether we are inside)
        if ($this->connectors['shelly'][$deviceId]['autoIntervals']) {
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

        // get latest timestamp with opposite status
        $roller = false;
        if ($this->connectors['shelly'][$deviceId]['type'] == 'roller') {
            $oppositeStatus = $currentStatus; // for rollers, we check for any other than the current status
            $roller = true;
        } else {
            $oppositeStatus = ($currentStatus + 1)%2;
        }
        $oldStatus = $this->em->getRepository('App:ShellyDataStore')->getLatest($this->getId($this->connectors['shelly'][$deviceId]), $oppositeStatus, $roller);
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
        } elseif (!empty($r) && $device['type'] == 'relay') {
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
                return $this->createStatus(2);
            } else {
                return $this->createStatus(3);
            }
        }
    }

    public function createStatus($status, $position = 100, $power = 0)
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
            ];
        } elseif ($status == 3) {
            return [
                'label' => 'label.device.status.closed',
                'val' => 3,
                'position' => $position,
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
            return true;
        } else {
            return false;
        }
    }

    private function setOff($device)
    {
        $r = $this->queryShelly($device, 'off');
        if (!empty($r)) {
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
                    $reqUrl = 'settings/roller/'.$device['port'].'?roller_open_url='.$triggerUrl.'/4';
                    break;
                case 'configureClose':
                    $triggerUrl = 'http://'.$this->host.$this->session_cookie_path.'trigger/'.$device['ip'].'_'.$device['port'];
                    $reqUrl = 'settings/roller/'.$device['port'].'?roller_close_url='.$triggerUrl.'/5';
                    break;
            }
        } elseif ($device['type'] == 'relay') {
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
                case 'configureDark':
                    $triggerUrl = 'http://'.$this->host.$this->session_cookie_path.'trigger/'.$device['ip'].'/2';
                    $reqUrl = 'settings?dark_url='.$triggerUrl;
                    break;
                case 'configureTwilight':
                    $triggerUrl = 'http://'.$this->host.$this->session_cookie_path.'trigger/'.$device['ip'].'/2';
                    $reqUrl = 'settings?twilight_url='.$triggerUrl;
                    break;
                case 'configureDaylight':
                    $triggerUrl = 'http://'.$this->host.$this->session_cookie_path.'trigger/'.$device['ip'].'/2';
                    $reqUrl = 'settings?open_url='.$triggerUrl;
                    break;
                case 'configureClose':
                    $triggerUrl = 'http://'.$this->host.$this->session_cookie_path.'trigger/'.$device['ip'].'/3';
                    $reqUrl = 'settings?close_url='.$triggerUrl;
                    break;
            }
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

    public function getConfig($ip, $port = null)
    {
        foreach ($this->connectors['shelly'] as $device) {
            if ($device['ip'] == $ip && ($port === null || $device['port'] == $port)) {
                return $device;
            }
        }
        return null;
    }

    private function storeStatus($device, $status)
    {
        $connectorId = $this->getId($device);
        $shellyEntity = new ShellyDataStore();
        $shellyEntity->setTimestamp(new \DateTime('now'));
        $shellyEntity->setConnectorId($connectorId);
        $shellyEntity->setData($status);
        $this->em->persist($shellyEntity);
        $this->em->flush();
    }

    private function getId($device)
    {
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
                    $latest = $this->em->getRepository('App:ShellyDataStore')->getLatest($deviceConf['ip'].'_'.$deviceConf['port']);
                    if (method_exists($latest, "getData")) {
                        $status = $latest->getData();
                        $timestamp = $latest->getTimestamp();
                    } else {
                        $status = $this->createStatus(2); // status = open
                        $timestamp = 0;
                    }
                    if ($status['val'] !== 3) {
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
                if (array_key_exists('type', $deviceConf) && ($deviceConf['type'] == 'roller') || $deviceConf['type'] == 'relay') {
                    return true;
                }
            }
        }

        return false;
    }

    private function getStatusCloud($cloudId)
    {
        try {
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
}
