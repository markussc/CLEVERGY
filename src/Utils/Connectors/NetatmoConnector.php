<?php

namespace App\Utils\Connectors;

use App\Entity\NetatmoDataStore;
use App\Entity\Settings;
use Doctrine\ORM\EntityManagerInterface;
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
    private $host;
    private $session_cookie_path;

    public function __construct(EntityManagerInterface $em, HttpClientInterface $client, Array $connectors, $host, $session_cookie_path)
    {
        $this->em = $em;
        $this->connectors = $connectors;
        if ($this->getAvailable()) {
            $this->config = $connectors['netatmo'];
            $this->client = $client;
            $this->baseUrl = 'https://api.netatmo.com';
            $this->clientId = $this->config['clientid'];
            $this->clientSecret = $this->config['clientsecret'];
            $this->host = $host;
            $this->session_cookie_path = $session_cookie_path;
            $this->authenticate();
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

    public function requiresUserAuthentication()
    {
        $requiresAuth = false;
        if ($this->getAvailable()) {
            $requiresAuth = true;
            $settings = $this->em->getRepository(Settings::class)->findOneByConnectorId("netatmo");
            if ($settings) {
                $config = $settings->getConfig();
                if (array_key_exists('state', $config) && $config['state'] !== 'unauthenticated') {
                    $requiresAuth = false;
                }
            }
        }

        return $requiresAuth;
    }

    public function getUserAuthenticationLink()
    {
        return $this->baseUrl . '/oauth2/authorize?scope=read_station&state=auth&client_id=' . $this->clientId . '&redirect_uri=https://' . $this->host . $this->session_cookie_path . 'extauth/netatmo_code';
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
            $insideTemp = min($tmp);
        } else {
            $insideTemp = 20;
        }

        return $insideTemp;
    }

    public function getLowBat()
    {
        $lowBat = [];
        if ($this->getAvailable()) {
            foreach ($this->getAllLatest()->getModulesData() as $data) {
                if (is_array($data) && array_key_exists('battery', $data) && $data['battery'] <= 25) {
                    $lowBat[] = $data['name'];
                }
            }
        }

        return $lowBat;
    }

    public function getAllLatest()
    {
        return $this->em->getRepository(NetatmoDataStore::class)->getLatest($this->config['deviceid']);
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

        $content = null;
        if ($this->token) {
            try {
                $response = $this->client->request(
                    'GET',
                    $url,
                    ['auth_bearer' => $this->token]
                );
                if ($response->getStatusCode() === Response::HTTP_OK) {
                    $content = $response->getContent();
                } elseif ($response->getStatusCode() === Response::HTTP_FORBIDDEN) {
                    $this->reauthenticate();
                }
            } catch (\Exception $e) {
                // do nothing
            }
        }

        return $content;
    }

    public function storeAuthConfig($state, $code, $accessToken, $refreshToken)
    {
        $settings = $this->em->getRepository(Settings::class)->findOneByConnectorId("netatmo");
        if (!$settings) {
            $settings = new Settings();
            $settings->setConnectorId("netatmo");
            $this->em->persist($settings);
        }
        $settings->setConfig([
            'state' => $state,
            'code' => $code,
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
        ]);
        $this->em->flush();
    }

    /*
     * Manages the authentication workflog according to https://dev.netatmo.com/apidocumentation/oauth
     * Documentation refer to https://dev.netatmo.com/apidocumentation/
     */
    private function authenticate()
    {
        $settings = $this->em->getRepository(Settings::class)->findOneByConnectorId("netatmo");
        if ($settings) {
            $config = $settings->getConfig();
            if ($config['access_token']) {
                $this->token = $config['access_token'];
            } elseif ($config['refresh_token']) {
                $this->refreshAccessToken($config['refresh_token']);
            } elseif ($config['code']) {
                $this->getAccessToken('authorize', $config['code']);
            }
        } else {
            // create a new settings entry
            $this->storeAuthConfig('unauthenticated', null, null, null);
        }
    }

    private function reauthenticate()
    {
        $this->token = null;
        $settings = $this->em->getRepository(Settings::class)->findOneByConnectorId("netatmo");
        if ($settings) {
            $config = $settings->getConfig();
            if ($config['refresh_token']) {
                $this->refreshAccessToken($config['refresh_token']);
            }
        }
    }

    private function getAccessToken($state, $code)
    {
        $url = $this->baseUrl . '/oauth2/token';
        try {
            $response = $this->client->request(
                'POST',
                $url,
                [
                    'body' => [
                        'grant_type' => 'authorization_code',
                        'client_id' => $this->clientId,
                        'client_secret' => $this->clientSecret,
                        'code' => $code,
                        'redirect_uri' => 'https://' . $this->host . $this->session_cookie_path . 'extauth/netatmo_code',
                        'scope' => 'read_station',
                    ]
                ]
            );
            if ($response->getStatusCode() === Response::HTTP_OK) {
                $content = json_decode($response->getContent(), true);
                $this->token = $content['access_token'];
                $this->storeAuthConfig('authenticated', null, $this->token, $content['refresh_token']);
            } else {
                $this->token = null;
                $content = $response->getContent(false);
                if (is_array($content) && array_key_exists('refresh_token')) {
                    $this->storeAuthConfig('refreshing', null, null, $content['refresh_token']);
                    $this->refreshAccessToken($content['refresh_token']);
                } else {
                    $this->storeAuthConfig('unauthenticated', null, null, null);
                }
            }
        } catch (\Exception $e) {
            $this->token = null;
        }
    }

    private function refreshAccessToken($refreshToken)
    {
        $url = $this->baseUrl . '/oauth2/token';
        try {
            $response = $this->client->request(
                'POST',
                $url,
                [
                    'body' => [
                        'grant_type' => 'refresh_token',
                        'client_id' => $this->clientId,
                        'client_secret' => $this->clientSecret,
                        'refresh_token' => $refreshToken,
                    ]
                ]
            );
            if ($response->getStatusCode() === Response::HTTP_OK) {
                $content = json_decode($response->getContent(), true);
                $this->token = $content['access_token'];
                $this->storeAuthConfig('authenticated', null, $this->token, $content['refresh_token']);
            } else {
                $this->token = null;
                $this->storeAuthConfig('unauthenticated', null, null, null);
            }
        } catch (\Exception $e) {
            $this->token = null;
        }
    }
}
