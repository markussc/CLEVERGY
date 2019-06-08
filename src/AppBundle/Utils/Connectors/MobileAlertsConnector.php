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

    /**
     * Reads the latest available data from the database
     * @return array
     */
    public function getAllLatest()
    {
        $data = [];
        if (is_array($this->connectors['mobilealerts']['sensors'])) {
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
        $requestHeaders = ['Content-Length:0'];
        $this->basePath = 'http://measurements.mobile-alerts.eu/Home/SensorsOverview?phoneid=';
        $response = $this->browser->post($this->basePath . $this->connectors['mobilealerts']['phoneid'], $requestHeaders);

        $crawler = new Crawler();
        $crawler->addContent($response->getContent());
        $sensorComponents = $crawler->filter('.sensor-component');

        $data = [];
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
                    if (array_key_exists(3, $this->connectors['mobilealerts']['sensors'][$currentSensor][$measurementCounter]) && $this->connectors['mobilealerts']['sensors'][$currentSensor][$measurementCounter][3] === "dashboard") {
                        $dashboard = true;
                    } else {
                        $dashboard = false;
                    }
                    $data[$currentSensor][] = [
                        'label' => $this->connectors['mobilealerts']['sensors'][$currentSensor][$measurementCounter][0],
                        'value' => preg_replace("/[^0-9,.,-]/", "", str_replace(',', '.', $value)),
                        'unit' => $this->connectors['mobilealerts']['sensors'][$currentSensor][$measurementCounter][1],
                        'dashboard' => $dashboard
                    ];
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

    /**
     * 
     * @return array
     * 
     * Retrieves the available data using the official REST API
     * 
     * Note: Only a limited set of sensors is available for this kind of data retrieval
     */
    private function getAllRest()
    {
        // TODO: set the base path correctly here
        $this->basePath = "unknown";

        // request parameters
        $data = [
            'deviceids' => join(',', $this->connectors['mobilealerts']['sensors'])
        ];

        // header
        $headers = [
            'Content-Type' => 'application/json',
        ];

        // post request
        $responseJson = $this->browser->post($this->basePath, $headers, json_encode($data))->getContent();
        $responseArr = json_decode($responseJson, true);

        return $responseArr;
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
}
