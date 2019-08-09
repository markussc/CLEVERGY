<?php

namespace AppBundle\Utils\Connectors;

use Doctrine\ORM\EntityManager;

/**
 * Connector to retrieve data from the Siemens Logo7 and Logo8 modules, using LogoControl as intermediate webservice (see www.frickelzeugs.de/logocontrol/ )
 *
 * @author Markus Schafroth
 */
class LogoControlConnector
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
        if (array_key_exists('logocontrol', $connectors)) {
            $this->basePath = 'http://' . $connectors['logocontrol']['ip'] .':8088';
        } else {
            $this->basePath = '';
        }
    }

    /**
     * Reads the latest available data from the database
     * @return array
     */
    public function getAllLatest()
    {
        $latest = [];
        if (array_key_exists('logocontrol', $this->connectors)) {
            $ip = $this->connectors['logocontrol']['ip'];
            $latest = $this->em->getRepository('AppBundle:LogoControlDataStore')->getLatest($ip);
        }

        return $latest;
    }

    /**
     * @return array
     * 
     * Retrieves the available data using the webservice
     */
    public function getAll()
    {
        $url = $this->basePath . '/rest/devices';
        $response = $this->browser->get($url);
        $statusCode = $response->getStatusCode();
        if ($statusCode == 401) {
            $response = $this->browser->get($url)->getContent();
        } else {
            $response = $response->getContent();
        }

        $rawdata = json_decode($response, true);

        $data = [];
        if (array_key_exists('logocontrol', $this->connectors)) {
            foreach($this->connectors['logocontrol']['sensors'] as $key=>$value) {
                $data[$rawdata['Groups'][0]['Devices'][0]['Attributes'][$key]['Name']] = $rawdata['Groups'][0]['Devices'][0]['Attributes'][$key]['Value'];
            }
        }

        return $data;
    }

    public function getIp()
    {
        return $this->connectors['logocontrol']['ip'];
    }
}