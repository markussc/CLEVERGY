<?php

namespace AppBundle\Utils\Connectors;

use Doctrine\ORM\EntityManager;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Connector to retrieve data from the MobileAlerts cloud
 * For information refer to www.mobile-alerts.eu
 *
 * @author Markus Schafroth
 */
class MobileAlertsConnector
{
    protected $em;
    protected $browser;
    protected $basePath;
    protected $connectors;

    public function __construct(EntityManager $em, \Buzz\Browser $browser, Array $connectors)
    {
        $this->em = $em;
        $this->browser = $browser;
        $this->connectors = $connectors;
    }

    public function getAlarms()
    {
        $alarms = [];
        if (array_key_exists('mobilealerts', $this->connectors) && is_array($this->connectors['mobilealerts']['sensors'])) {
            foreach ($this->connectors['mobilealerts']['sensors'] as $sensorId => $sensorConf) {
                if (array_key_exists(4, $sensorConf[0]) && $sensorConf[0][4] == 'contact') {
                    $data = $this->em->getRepository('AppBundle:MobileAlertsDataStore')->getLatest($sensorId);
                    if ($data[1]['value'] == 'label.device.status.open') {
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
        return $this->em->getRepository('AppBundle:Settings')->getMode('alarm');
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
                $data[$sensorId] = $this->em->getRepository('AppBundle:MobileAlertsDataStore')->getLatest($sensorId);
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
        return $this->getAllWeb();
    }

    /**
     * @return array
     * 
     * Retrieves the available data using the 
     */
    public function getAllWeb()
    {
        $data = [];
        $requestHeaders = ['Content-Length:0'];
        $this->basePath = 'http://measurements.mobile-alerts.eu/Home/SensorsOverview?phoneid=';
        try {
            $response = $this->browser->post($this->basePath . $this->connectors['mobilealerts']['phoneid'], $requestHeaders);
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
            $unit = '';
            if ($label == 'ID') {
                // next sensor
                $currentSensor = $value;
                $measurementCounter = 0;
            } elseif (array_key_exists($currentSensor, $this->connectors['mobilealerts']['sensors'])) {
                if ($this->validateDate($value)) {
                    // this is the timestamp
                    $data[$currentSensor][] = [
                        'label' => 'timestamp',
                        'value' => $value,
                        'datetime' => \DateTime::createFromFormat('d.m.Y H:i:s', $value),
                    ];
                } else {
                    // next measurement
                    $data[$currentSensor][] = $this->createStorageData($currentSensor, measurementCounter, $value);
                    $measurementCounter++;
                }
            }
        }

        return $data;
    }

    private function validateDate($date, $format = 'd.m.Y H:i:s') 
    {    
        $d = \DateTime::createFromFormat($format, $date);    
        return $d && $d->format($format) == $date; 
    }

    private function createStorageData($currentSensor, $measurementCounter, $value)
    {
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
        $data[$currentSensor][] = [
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
     * $ids : array of supported device IDs
     * Note: Only a limited set of sensors is available for this kind of data retrieval
     */
    private function getAllRest($ids = [])
    {
        // executes a post request containing deviceids + phoneid to the REST API
        //  curl -d deviceids=XXXXXXXXXXXX -d phoneid=XXXXXXXXXXXX http://www.data199.com:8080/api/pv1/device/lastmeasurement
        // available for sensors of types ID01, ID08, ID09, ID0B and ID0E 

        $this->basePath = "http://www.data199.com:8080/api/pv1/device/lastmeasurement";

        // request parameters
        $data = [
            'deviceids' => join(',', $ids),
            'phoneid' => $this->connectors['mobilealerts']['phoneid'],
        ];

        // header
        $headers = [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ];

        // post request
        $responseJson = $this->browser->post($this->basePath, $headers, http_build_query($data))->getContent();
        $responseArr = json_decode($responseJson, true);

        // prepare return
        $assocArr = [];
        if (array_key_exists('devices', $responseArr)) {
            foreach ($responseArr['devices'] as $device) {
                $assocArr[$device['deviceid']] = $device['measurement'];
                // TODO: use the nwe function createStorageData to create the array backwards compatible
            }
        }

        return $assocArr;
    }

    /**
     * @return array
     * 
     * Retrieves the available data using the iOS app API
     * 
     * Based on https://github.com/sarnau/MMMMobileAlerts
     * Note: This approach is currently not working properly.
     */
    private function getAllApp()
    {
        $this->basePath = 'http://www.data199.com:8080/api/v1/dashboard';
        
        // fixed request parameters
        $data = [
            'devicetoken' => 'empty',				// defaults to "empty"
            'vendorid' => '0ee51434-9987-43b7-88dd-02687bcb1771',	// iOS vendor UUID (returned by iOS, any UUID will do). Launch uuidgen from the terminal to generate a fresh one.
            'phoneid' => 'Unknown',					// Phone ID - probably generated by the server based on the vendorid (this string can be "Unknown" and it still works)
            'version' => '1.21',					// Info.plist CFBundleShortVersionString
            'build' => '248',						// Info.plist CFBundleVersion
            'executable' => 'Mobile Alerts',		// Info.plist CFBundleExecutable
            'bundle' => 'eu.mobile_alerts.mobilealerts',	// [[NSBundle mainBundle] bundleIdentifier]
            'lang' => 'en',                         // preferred language
        ];

        // user defined request parameters
        $data['timezoneoffset'] = 60;  // local offset to UTC time
        $data['timeampm'] = 'true';       // 12h vs 24h clock
        $data['usecelsius'] = 'true';     // Celcius vs Fahrenheit
        $data['usemm'] = 'true';          // mm va in
        $data['speedunit'] = 0;         // wind speed (0: m/s, 1: km/h, 2: mph, 3: kn)
        $data['timestamp'] = strftime('%s', time());     // current UTC timestamp

        // prepare the checksum
        $requestMD5 = http_build_query($data);

        $requestMD5 .= 'asdfaldfjadflxgeteeiorut0ß8vfdft34503580';	# SALT for the MD5
        $requestMD5 = str_replace('-', '', $requestMD5);
        $requestMD5 = str_replace(',', '', $requestMD5);
        $requestMD5 = str_replace('.', '', $requestMD5);
        $requestMD5 = strtolower($requestMD5);
        $data['requesttoken'] = md5($requestMD5);

        // add sensor IDs
        $data['deviceids'] = join(',', $this->connectors['mobilealerts']['sensors']);
 
        $headers = [
            'User-Agent' => 'remotemonitor/248 CFNetwork/758.2.8 Darwin/15.0.0',
            'Accept-Language' => 'en-us',
            'Content-Type' => 'application/x-www-form-urlencoded; charset=utf-8',
            'Host' => 'www.data199.com:8080',
        ];

        // post request
        $responseJson = $this->browser->post($this->basePath, $headers, http_build_query($data))->getContent();
        $responseArr = json_decode($responseJson, true);

        return $responseArr;
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
