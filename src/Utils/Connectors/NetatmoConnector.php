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
        $this->browser = $browser;
        $this->config = $connectors['netatmo'];
        $this->client = $client;
        $this->baseUrl = 'https://api.netatmo.com';
        $this->tokenEndpoint = $this->baseUrl.'/oauth2/token';
        $this->clientId = $this->config['clientId'];
        $this->clientSecret = $this->config['clientSecret'];
        $this->username = $this->config['username'];
        $this->password = $this->config['password'];
        $this->setToken();
    }

    /*
     * Retrieve data from a weatherstation
     *
     * @return Json : response according to https://dev.netatmo.com/apidocumentation/weather
     */
    public function getStationsData($deviceId)
    {
        $url = $this->baseUrl.'/getstationsdata?device_id='.$deviceId;

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
                        'grant_type' => 'client_credentials',
                        'client_id' => $this->clientId,
                        'client_secret' => $this->clientSecret,
                        'username' => $this->username,
                        'password' => $this->password,
                        'scope' => 'read station read_thremostat'
                    ],
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
