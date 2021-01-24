<?php

namespace App\Utils\Connectors;

use Doctrine\ORM\EntityManager;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 *
 * @author Markus Schafroth
 */
class NetatmoConnector
{
    protected $em;
    protected $connectors;
    private $client;
    private $clientId;
    private $clientSecret;
    private $baseUrl;
    private $token;

    public function __construct(EntityManager $em, HttpClientInterface $client, Array $connectors)
    {
        $this->em = $em;
        $this->connectors = $connectors;
        if ($this->getAvailable()) {
            $this->config = $connectors['netatmo'];
            $this->client = $client;
            $this->baseUrl = 'https://api.netatmo.com';
            $this->tokenEndpoint = $this->baseUrl.'/oauth2/token';
            $this->clientId = $this->config['clientid'];
            $this->clientSecret = $this->config['clientsecret'];
            $this->username = $this->config['username'];
            $this->password = $this->config['password'];
            $this->setToken();
        }
    }

    public function getAvailable()
    {
        if (array_key_exists('netatmo', $this->connectors)) {
            return true;
        } else {
            return false;
        }
    }

    public function getId()
    {
        $id = null;
        if ($this->getAvailable()) {
            $id = $this->config['deviceid'];
        }

        return $id;
    }

    public function getCurrentMinInsideTemp()
    {
        $tmp = [];
        if (($sensor = $this->getLatestByLocation("inside")) !== null) {
            if (array_key_exists("temp", $sensor)) {
                $tmp[] = $sensor["temp"];
            }
        }
        if (($sensor = $this->getLatestByLocation("firstfloor")) !== null) {
            if (array_key_exists("temp", $sensor)) {
                $tmp[] = $sensor["temp"];
            }
        }
        if (($sensor = $this->getLatestByLocation("secondfloor")) !== null) {
            if (array_key_exists("temp", $sensor)) {
                $tmp[] = $sensor["temp"];
            }
        }

        if (count($tmp) > 0) {
            $insideTemp = array_sum($tmp)/count($tmp);
        } else {
            $insideTemp = 20;
        }

        return $insideTemp;
    }

    public function getAllLatest()
    {
        return $this->em->getRepository('App:NetatmoDataStore')->getLatest($this->config['deviceid']);
    }

    public function getAll()
    {
        $result = $this->getStationsData($this->config['deviceid']);

        return $result;
    }

    public function getLatestByLocation($location)
    {
        $latest = $this->getAllLatest();
        $latestLocation = null;
        // check station
        if (array_key_exists('location', $this->config) && $this->config['location'] == $location) {
            $latestLocation = $latest->getStationData();
        }

        // check modules
        if (!$latestLocation && array_key_exists('modules', $this->config)) {
            foreach ($this->config['modules'] as $module) {
                if (array_key_exists('location', $module) && $module['location'] == $location) {
                    $latestLocation = $latest->getModuleData($module['deviceid']);
                }
            }
        }

        return $latestLocation;
    }

    /*
     * Retrieve data from a weatherstation
     *
     * @return Json : response according to https://dev.netatmo.com/apidocumentation/weather
     */
    public function getStationsData($deviceId)
    {
        $url = $this->baseUrl.'/api/getstationsdata?device_id='.$deviceId;

        try {
            $response = $this->client->request(
                'GET',
                $url,
                ['auth_bearer' => $this->token]
            );
            $content = $response->getContent();
        } catch (\Exception $e) {
            $content = null;
        }

        return $content;
    }

    /*
     * Sets the bearer token for the usage of the API using the client_credentials grant type, based on the available credentials
     * Documentation refer to https://dev.netatmo.com/apidocumentation/
     */
    private function setToken()
    {
        try {
            $response = $this->client->request(
                'POST',
                $this->tokenEndpoint,
                [
                    'body' => [
                        'grant_type' => 'password',
                        'client_id' => $this->clientId,
                        'client_secret' => $this->clientSecret,
                        'username' => $this->username,
                        'password' => $this->password,
                    ]
                ]
            );
            if ($response->getStatusCode() === Response::HTTP_OK) {
                $content = json_decode($response->getContent());
                $this->token = $content->access_token;
            }
        } catch (\Exception $e) {
            $this->token = null;
        }
    }
}
