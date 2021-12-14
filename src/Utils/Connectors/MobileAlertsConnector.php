<?php

namespace App\Utils\Connectors;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DomCrawler\Crawler;
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
                    $data = $this->em->getRepository('App:MobileAlertsDataStore')->getLatest($sensorId);
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
        return $this->em->getRepository('App:Settings')->getMode('alarm');
    }

    public function getCurrentMinInsideTemp()
    {
        $latest = $this->getAllLatest();
        $tmp = [];
        foreach  ($latest as $device) {
            foreach ($device as $sensor) {
                if (array_key_exists("usage", $sensor) &&
                        (   $sensor['usage'] == "insidetemp" ||
                            $sensor['usage'] == "firstfloortemp" ||
                            $sensor['usage'] == "secondfloortemp")) {
                    $tmp[] = floatval($sensor['value']);
                }
            }
        }
        if (count($tmp) > 0) {
            $insideTemp = min($tmp);
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
                $data[$sensorId] = $this->em->getRepository('App:MobileAlertsDataStore')->getLatest($sensorId);
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
        $rest = $this->getAllRest();
        $web = $this->getAllWeb();
        $all = array_merge($rest, $web);

        return $all;
    }

    /**
     * @return array
     * 
     * Retrieves the available data using the 
     */
    public function getAllWeb()
    {
        $data = [];
        $this->basePath = 'https://measurements.mobile-alerts.eu/Home/SensorsOverview?phoneid=';
        try {
            $response = $this->client->request('GET', $this->basePath . $this->connectors['mobilealerts']['phoneid']);
        } catch (\Exception $e) {
            return $data;
        }
        $crawler = new Crawler();
        $crawler->addContent($response->getContent());
        $sensorComponents = $crawler->filter('.sensor-component');

        $currentSensor = '';
        $measurementCounter = 0;
        foreach ($sensorComponents as $sensorComponent) {
            $cr = new Crawler($sensorComponent);
            $label = $cr->filter('h5')->text();
            $value = $cr->filter('h4')->text();
            if ($label == 'ID') {
                // next sensor
                $currentSensor = $value;
                $measurementCounter = 0;
            } elseif (!$this->checkProSensor($currentSensor) && array_key_exists($currentSensor, $this->connectors['mobilealerts']['sensors'])) {
                if ($this->validateDate($value)) {
                    // this is the timestamp
                    $data[$currentSensor][] = [
                        'label' => 'timestamp',
                        'value' => $value,
                        'datetime' => \DateTime::createFromFormat('d.m.Y H:i:s', $value),
                    ];
                } else {
                    // next measurement
                    $data[$currentSensor][] = $this->createStorageData($currentSensor, $measurementCounter, $value);
                    $measurementCounter++;
                }
            }
        }

        return $data;
    }

    private function checkProSensor($id)
    {
        $proSensors = [
            '01', # MA10120
            '08', # MA10650
            '09', # MA10320
            '0B', # MA10660
            '0E', # MA10241
        ];
        foreach ($proSensors as $proSensor) {
            if (strpos($id, $proSensor) === 0) {
                return true;
            }
        }

        return false;
    }

    private function validateDate($date, $format = 'd.m.Y H:i:s') 
    {    
        $d = \DateTime::createFromFormat($format, $date);    
        return $d && $d->format($format) == $date; 
    }

    private function createStorageData($currentSensor, $measurementCounter, $value)
    {
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
                $value = str_replace('Geschlossen', 'label.device.status.closed', $value);
                $value = str_replace('Offen', 'label.device.status.open', $value);
            } else {
                $value = str_replace('Geschlossen', 'label.device.status.open', $value);
                $value = str_replace('Offen', 'label.device.status.closed', $value);
            }
        } else {
            $value = preg_replace("/[^0-9,.,-]/", "", str_replace(',', '.', $value));
            $unit = $this->connectors['mobilealerts']['sensors'][$currentSensor][$measurementCounter][1];
        }
        $data = [
            'label' => $this->connectors['mobilealerts']['sensors'][$currentSensor][$measurementCounter][0],
            'value' => $value,
            'unit' => $unit,
            'dashboard' => $dashboard,
            'usage' => $usage,
        ];

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
        //curl -d deviceids=XXXXXXXXXXXX -d phoneid=XXXXXXXXXXXX http://www.data199.com:8080/api/pv1/device/lastmeasurement
        // available for sensors of types ID01, ID08, ID09, ID0B and ID0E 

        $this->basePath = "https://www.data199.com/api/pv1/device/lastmeasurement";

        // get proSensors only
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
        $assocArr = [];
        if (array_key_exists('devices', $responseArr)) {
            foreach ($responseArr['devices'] as $device) {
                $id = $device['deviceid'];
                if (!array_key_exists('measurement', $device)) {
                    // no new measurement data available, we do not want to store the entry
                    continue;
                }
                $datetime = new \DateTime('@'.$device['measurement']['ts']);
                $assocArr[$id][] =  [
                    'label' => 'timestamp',
                    'value' => $datetime->format('d.m.Y H:i:s'), // we do not need this value to be stored human readable here
                    'datetime' => $datetime,
                ];
                // use the function createStorageData to create the array backwards compatible
                $type = substr($id, 0, 2);
                switch ($type) {
                    case '01':
                        $assocArr[$id][] = $this->createStorageData($id, 0, $device['measurement']['t1']);
                        $assocArr[$id][] = $this->createStorageData($id, 1, $device['measurement']['t2']);
                        break;
                    case '08':
                        $assocArr[$id][] = $this->createStorageData($id, 0, $device['measurement']['r']);
                        break;
                    case '09':
                        $assocArr[$id][] = $this->createStorageData($id, 0, $device['measurement']['t1']);
                        $assocArr[$id][] = $this->createStorageData($id, 1, $device['measurement']['t2']);
                        $assocArr[$id][] = $this->createStorageData($id, 2, $device['measurement']['h']);
                        break;
                    case '0B':
                        $assocArr[$id][] = $this->createStorageData($id, 0, $device['measurement']['ws']);
                        $assocArr[$id][] = $this->createStorageData($id, 1, $device['measurement']['wg']);
                        $assocArr[$id][] = $this->createStorageData($id, 2, $device['measurement']['wd']);
                        break;
                    case '0E':
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
}
