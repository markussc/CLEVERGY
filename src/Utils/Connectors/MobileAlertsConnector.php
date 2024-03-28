<?php

namespace App\Utils\Connectors;

use App\Entity\MobileAlertsDataStore;
use App\Entity\Settings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Connector to retrieve data from the MobileAlerts cloud
 * For information refer to www.mobile-alerts.eu
 *
 * @author Markus Schafroth
 */
class MobileAlertsConnector
{
    protected $em;
    private $client;
    protected $basePath;
    protected $connectors;

    public function __construct(EntityManagerInterface $em, HttpClientInterface $client, Array $connectors)
    {
        $this->em = $em;
        $this->client = $client;
        $this->connectors = $connectors;
    }

    public function getAlarms()
    {
        $alarms = [];
        if (array_key_exists('mobilealerts', $this->connectors) && is_array($this->connectors['mobilealerts']['sensors'])) {
            foreach ($this->connectors['mobilealerts']['sensors'] as $sensorId => $sensorConf) {
                if (array_key_exists(4, $sensorConf[0]) && $sensorConf[0][4] == 'contact') {
                    $data = $this->em->getRepository(MobileAlertsDataStore::class)->getLatest($sensorId);
                    if (is_array($data) && $data[1]['value'] == 'label.device.status.open') {
                        $alarms[] = [
                            'name' => $sensorConf[0][0],
                            'state' => $data[1]['value'],
                            'type' => 'contact',
                        ];
                    }
                }
            }
        }

        return $alarms;
    }

    public function getLowBat()
    {
        $lowBat = [];
        if (array_key_exists('mobilealerts', $this->connectors) && is_array($this->connectors['mobilealerts']['sensors'])) {
            foreach ($this->connectors['mobilealerts']['sensors'] as $sensorId => $sensorConf) {
                $data = $this->em->getRepository(MobileAlertsDataStore::class)->getLatest($sensorId);
                if (is_array($data) && array_key_exists(0, $data) && is_array($data[0]) && array_key_exists('lowbattery', $data[0]) && $data[0]['lowbattery']) {
                    $lowBat[] = $sensorConf[0][0];
                }
            }
        }

        return $lowBat;
    }

    public function contactAvailable()
    {
        if (array_key_exists('mobilealerts', $this->connectors) && is_array($this->connectors['mobilealerts']['sensors'])) {
            foreach ($this->connectors['mobilealerts']['sensors'] as $sensorId => $sensorConf) {
                if (array_key_exists(4, $sensorConf[0]) && $sensorConf[0][4] == 'contact') {
                    return true;
                }
            }
        }

        return false;
    }

    public function getAlarmMode()
    {
        return $this->em->getRepository(Settings::class)->getMode('alarm');
    }

    /*
     * check if current data is older than 2h; the oldest sensor value is relevant.
     */
    public function currentInsideTempAvailable()
    {
        $latest = $this->getAllLatest();
        $timestamp = new \DateTime();
        $thisTimestamp = new \DateTime();
        $now = new \DateTime();
        foreach  ($latest as $device) {
            if (is_array($device) && array_key_exists(0, $device) && is_array($device[0]) && array_key_exists('label', $device[0]) && $device[0]['label'] == 'timestamp') {
                $thisTimestamp = new \DateTime($device[0]['value']);
            }
            if (is_array($device)) {
                foreach ($device as $sensor) {
                    if (is_array($sensor) && array_key_exists("usage", $sensor) &&
                            (   $sensor['usage'] == "insidetemp" ||
                                $sensor['usage'] == "firstfloortemp" ||
                                $sensor['usage'] == "secondfloortemp")) {
                        if ($thisTimestamp < $timestamp) {
                            $timestamp = $thisTimestamp;
                        }
                    }
                }
            }
        }
        if ($now->getTimestamp() - $timestamp->getTimestamp() > 7200) {
            return false;
        } else {
            return true;
        }
    }

    public function getCurrentMinInsideTemp()
    {
        $latest = $this->getAllLatest();
        $tmp = [];
        foreach  ($latest as $device) {
            foreach ($device as $sensor) {
                if (is_array($sensor) && array_key_exists("usage", $sensor) &&
                        (   $sensor['usage'] == "insidetemp" ||
                            $sensor['usage'] == "firstfloortemp" ||
                            $sensor['usage'] == "secondfloortemp")) {
                    $tmp[] = floatval($sensor['value']);
                }
            }
        }
        if (count($tmp) > 0) {
            $insideTemp = (2*min($tmp) + max($tmp))/3; // consider both min and max temperature to get the relevant minInsideTemp
        } else {
            $insideTemp = 20;
        }

        return $insideTemp;
    }

    /**
     * Reads the latest available data from the database
     * @return array
     */
    public function getAllLatest()
    {
        $data = [];
        if (array_key_exists('mobilealerts', $this->connectors) && is_array($this->connectors['mobilealerts']['sensors'])) {
            foreach ($this->connectors['mobilealerts']['sensors'] as $sensorId => $sensorConf) {
                $data[$sensorId] = $this->em->getRepository(MobileAlertsDataStore::class)->getLatest($sensorId);
            }
        }
        return $data;
    }

    /**
     * 
     * @return array
     * 
     * Switch method to select best working data retrieval mechanism
     */
    public function getAll()
    {
        return $this->getAllRest();
    }

    private function checkProSensor($id)
    {
        $proSensors = [
            '01', # MA10120
            '02', # Temperature
            '03', # Temperature + Humidity
            '06', # 2x Temperature + Humidity (Poolsensor)
            '07', # Base station
            '08', # MA10650
            '09', # MA10320
            '0B', # MA10660
            '0E', # MA10241
            '10', # Window/Door
        ];
        foreach ($proSensors as $proSensor) {
            if (strpos($id, $proSensor) === 0) {
                return true;
            }
        }

        return false;
    }

    private function createStorageData($currentSensor, $measurementCounter, $value)
    {
        $data = [];
        $unit = '';
        if (!isset($this->connectors['mobilealerts']['sensors'][$currentSensor][$measurementCounter][1])) {
            $this->connectors['mobilealerts']['sensors'][$currentSensor][$measurementCounter][1] = '';
        }
        if (array_key_exists(3, $this->connectors['mobilealerts']['sensors'][$currentSensor][$measurementCounter]) && $this->connectors['mobilealerts']['sensors'][$currentSensor][$measurementCounter][3] === "dashboard") {
            $dashboard = true;
        } else {
            $dashboard = false;
        }
        if (array_key_exists(4, $this->connectors['mobilealerts']['sensors'][$currentSensor][$measurementCounter])) {
            $usage = $this->connectors['mobilealerts']['sensors'][$currentSensor][$measurementCounter][4];
        } else {
            $usage = false;
        }
        if (array_key_exists(4, $this->connectors['mobilealerts']['sensors'][$currentSensor][$measurementCounter]) && $this->connectors['mobilealerts']['sensors'][$currentSensor][$measurementCounter][4] === 'contact') {
            if (!array_key_exists(5, $this->connectors['mobilealerts']['sensors'][$currentSensor][$measurementCounter]) || $this->connectors['mobilealerts']['sensors'][$currentSensor][$measurementCounter][5] !== 'inverted') {
                if ($value) {
                    $value = 'label.device.status.open';
                } else {
                    $value = 'label.device.status.closed';
                }
            } else {
                if (!$value) {
                    $value = 'label.device.status.open';
                } else {
                    $value = 'label.device.status.closed';
                }
            }
        } else {
            $value = preg_replace("/[^0-9,.,-]/", "", str_replace(',', '.', $value));
            $unit = $this->connectors['mobilealerts']['sensors'][$currentSensor][$measurementCounter][1];
        }
        if ($value != 43530 && $value != 65295) {
            $data = [
                'label' => $this->connectors['mobilealerts']['sensors'][$currentSensor][$measurementCounter][0],
                'value' => $value,
                'unit' => $unit,
                'dashboard' => $dashboard,
                'usage' => $usage,
            ];
        }

        return $data;
    }

    /**
     * 
     * @return array
     * 
     * Retrieves the available data using the official REST API
     * Note: Only a limited set of sensors is available for this kind of data retrieval
     */
    private function getAllRest()
    {
        // executes a post request containing deviceids + phoneid to the REST API
        //curl -d deviceids=XXXXXXXXXXXX http://www.data199.com:8080/api/pv1/device/lastmeasurement
        // available for sensors according to https://mobile-alerts.eu/info/public_server_api_documentation.pdf

        $this->basePath = "https://www.data199.com/api/pv1/device/lastmeasurement";

        // get proSensors only (this is to check if the sensor is in the list of supported sensors). Previously, the REST API was available only for the Pro sensors.
        $deviceIds = [];
        foreach ($this->connectors['mobilealerts']['sensors'] as $id => $sensor) {
            if ($this->checkProSensor($id)) {
                $deviceIds[] = $id;
            }
        }

        // try to post request
        try {
            $responseJson = $this->client->request(
                'POST',
                $this->basePath,
                [
                    'body' => [
                        'deviceids' => join(',', $deviceIds)
                    ]
                ])->getContent();
            $responseArr = json_decode($responseJson, true);
        } catch (\Exception $e) {
            return [];
        }

        // prepare return
        return $this->extractData($responseArr);
    }

    /**
     * takes the response array as delivered by either the REST or APP API and returns the entries ready for storage
     */
    private function extractData($responseArr)
    {
        $assocArr = [];
        if (array_key_exists('devices', $responseArr)) {
            foreach ($responseArr['devices'] as $device) {
                $id = $device['deviceid'];
                if (!array_key_exists('measurement', $device)) {
                    // no new measurement data available, we do not want to store the entry
                    continue;
                }
                $datetime = new \DateTime('@'.$device['measurement']['ts']);
                if (array_key_exists('lowbattery', $device)) {
                    $lowbattery = $device['lowbattery'];
                } else {
                    $lowbattery = false;
                }
                $assocArr[$id][] =  [
                    'label' => 'timestamp',
                    'value' => $datetime->format('d.m.Y H:i:s'), // we do not need this value to be stored human readable here
                    'datetime' => $datetime,
                    'lowbattery' => $lowbattery,
                ];

                // use the function createStorageData to create the array backwards compatible
                $type = substr($id, 0, 2);
                switch ($type) {
                    case '01': // pro
                        $assocArr[$id][] = $this->createStorageData($id, 0, $device['measurement']['t1']);
                        $assocArr[$id][] = $this->createStorageData($id, 1, $device['measurement']['t2']);
                        break;
                    case '02':
                        $assocArr[$id][] = $this->createStorageData($id, 0, $device['measurement']['t1']);
                        break;
                    case '03':
                        $assocArr[$id][] = $this->createStorageData($id, 0, $device['measurement']['t1']);
                        $assocArr[$id][] = $this->createStorageData($id, 1, $device['measurement']['h']);
                        break;
                    case '06':
                        $assocArr[$id][] = $this->createStorageData($id, 0, $device['measurement']['t1']);
                        $assocArr[$id][] = $this->createStorageData($id, 1, $device['measurement']['t2']);
                        $assocArr[$id][] = $this->createStorageData($id, 2, $device['measurement']['h']);
                        break;
                    case '07':
                        $assocArr[$id][] = $this->createStorageData($id, 0, $device['measurement']['t1']);
                        $assocArr[$id][] = $this->createStorageData($id, 1, $device['measurement']['h']);
                        $assocArr[$id][] = $this->createStorageData($id, 2, $device['measurement']['t2']);
                        $assocArr[$id][] = $this->createStorageData($id, 3, $device['measurement']['h2']);
                        break;
                    case '08': // pro
                        $assocArr[$id][] = $this->createStorageData($id, 0, $device['measurement']['r']);
                        break;
                    case '09': // pro
                        $assocArr[$id][] = $this->createStorageData($id, 0, $device['measurement']['t1']);
                        $assocArr[$id][] = $this->createStorageData($id, 1, $device['measurement']['t2']);
                        $assocArr[$id][] = $this->createStorageData($id, 2, $device['measurement']['h']);
                        break;
                    case '10':
                        $assocArr[$id][] = $this->createStorageData($id, 0, $device['measurement']['w']);
                        break;
                    case '0B': // pro
                        $assocArr[$id][] = $this->createStorageData($id, 0, $device['measurement']['ws']);
                        $assocArr[$id][] = $this->createStorageData($id, 1, $device['measurement']['wg']);
                        $assocArr[$id][] = $this->createStorageData($id, 2, $device['measurement']['wd']);
                        break;
                    case '0E': // pro
                        $assocArr[$id][] = $this->createStorageData($id, 0, $device['measurement']['t1']);
                        $assocArr[$id][] = $this->createStorageData($id, 1, $device['measurement']['h']);
                        break;
                }
            }
        }

        return $assocArr;
    }

    public function getId($index)
    {
        $i = 0;
        foreach ($this->connectors['mobilealerts']['sensors'] as $id => $conf) {
            if ($index == $i) {
                return $id;
            }
        }

        return null;
    }

    public function getAvailable()
    {
        if (array_key_exists('mobilealerts', $this->connectors)) {
            return true;
        } else {
            return false;
        }
    }

    /**
     *
     * @return array
     *
     * Retrieves the available data using the APP API (NOTE: This method is currently not required as all sensors may be queried via the public REST API)
     * Refer to: https://github.com/sarnau/MMMMobileAlerts/blob/master/MobileAlertsGatewayApplicationAPI.markdown
     */
    private function getAllApp()
    {
        $this->basePath = "https://www.data199.com/api/pv1/device/lastmeasurement";

        // get proSensors only
        $deviceIds = [];
        foreach ($this->connectors['mobilealerts']['sensors'] as $id => $sensor) {
            if (!$this->checkProSensor($id)) {
                $deviceIds[] = $id;
            }
        }

        $url = 'http://www.data199.com:8080/api/v1/dashboard';
        $request = 'devicetoken=empty';
        $request .= '&vendorid=BE60BB85-EAC9-4C5B-8885-1A54A9D51E29';
        $request .= '&phoneid='.$this->connectors['mobilealerts']['phoneid'];
        $request .= '&version=1.21';
        $request .= '&build=248';
        $request .= '&executable=Mobile Alerts';
        $request .= '&bundle=de.synertronixx.remotemonitor';
        $request .= '&lang=en';
        $request .= '&timezoneoffset=60';
        $request .= '&timeampm=true';
        $request .= '&usecelsius=true';
        $request .= '&usemm=true';
        $request .= '&speedunit=0';
        $request .= '&timestamp='.time();

        $requestMD5 = $request.'uvh2r1qmbqk8dcgv0hc31a6l8s5cnb0ii7oglpfj'; # SALT for the MD5
        $requestMD5 = str_replace('-', '', $requestMD5);
        $requestMD5 = str_replace(',', '', $requestMD5);
        $requestMD5 = str_replace('.', '', $requestMD5);
        $requestMD5 = strtolower($requestMD5);
        $requestMD5 = utf8_encode($requestMD5);
        $hexdig = md5($requestMD5);

        $request .= '&requesttoken='.$hexdig;
        $request .= '&deviceids='.join(',', $deviceIds);

        // try to post request
        try {
            $responseJson = $this->client->request(
                'POST',
                $this->basePath,
                [
                    'headers' => [
                        'User-Agent' => 'remotemonitor/248 CFNetwork/758.2.8 Darwin/15.0.0',
                        'Accept-Language' => 'en-us',
                        'Content-Type' => 'application/x-www-form-urlencoded; charset=utf-8',
                        'Host' => 'www.data199.com:8080'
                    ],
                    'body' => $request
                ])->getContent(false);
            $responseArr = json_decode($responseJson, true);
        } catch (\Exception $e) {
            $responseArr =  [];
        }

        // prepare return
        return $this->extractData($responseArr);
    }
}
