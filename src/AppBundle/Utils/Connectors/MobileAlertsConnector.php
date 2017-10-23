<?php

namespace AppBundle\Utils\Connectors;

/**
 * Connector to retrieve data from the MobileAlerts cloud
 * For information refer to www.mobile-alerts.eu
 *
 * @author Markus Schafroth
 */
class MobileAlertsConnector
{
    protected $browser;
    protected $basePath;
    protected $connectors;

    public function __construct(\Buzz\Browser $browser, Array $connectors)
    {
        $this->browser = $browser;
        $this->basePath = 'https://www.data199.com/api/pv1/device/lastmeasurement?deviceids=';
        $this->connectors = $connectors;
    }

    public function getAll()
    {
        $data = array(
            'deviceids' => join(',', $this->connectors['mobilealerts']['sensors'])
        ); // Build your payload

        $headers = array(
            'Content-Type' => 'application/json',
        );
        $responseJson = $this->browser->post($this->basePath, $headers, json_encode($data))->getContent();
        $responseArr = json_decode($responseJson, true);

        return $responseArr;
    }
}
