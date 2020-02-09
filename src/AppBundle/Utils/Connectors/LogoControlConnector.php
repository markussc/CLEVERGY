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
        $this->ip = null;
        if (array_key_exists('logocontrol', $connectors)) {
            $this->ip = $connectors['logocontrol']['ip'];
            $this->basePath = 'http://' . $this->ip .':8088';
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
        try {
        $url = $this->basePath . '/rest/devices';
        $response = $this->browser->get($url);
        $statusCode = $response->getStatusCode();
        if ($statusCode == 401) {
            $response = $this->browser->get($url)->getContent();
        } else {
            $response = $response->getContent();
        }

        $rawdata = json_decode($response, true);
        } catch (\Exception $e) {
          // do nothing
        }


        $data = [];
        if (isset($rawdata) && array_key_exists('logocontrol', $this->connectors)) {
            foreach($this->connectors['logocontrol']['sensors'] as $key=>$value) {
                $rawVal = $rawdata['Groups'][0]['Devices'][0]['Attributes'][$key]['Value'];
                if ($rawVal > 200) {
                    // handle int-overflow at 255
                    $cleanVal = $rawVal - 255;
                } else {
                    $cleanVal = $rawVal;
                }
                $data[$rawdata['Groups'][0]['Devices'][0]['Attributes'][$key]['Name']] = $cleanVal;
            }
        }

        return $data;
    }

    public function getIp()
    {
        return $this->ip;
    }
}
