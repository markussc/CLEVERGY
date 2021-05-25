<?php

namespace App\Utils\Connectors;

use App\Entity\Settings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Connector to retrieve data from Gardena (Husqvarna) API
 * For information refer to https://developer.husqvarnagroup.cloud/apis/GARDENA+smart+system+API
 *
 * @author Markus Schafroth
 */
class GardenaConnector
{
    protected $em;
    private $client;
    private $clientId;
    private $username;
    private $password;
    private $baseUrl;
    private $token;
    protected $connectors;

    public function __construct(EntityManagerInterface $em, HttpClientInterface $client, Array $connectors)
    {
        $this->em = $em;
        $this->client = $client;
        $this->connectors = $connectors;
        $this->token = null;
        $this->clientId = null;
        $this->username = null;
        $this->password = null;
        $this->baseUrl = 'https://api.smart.gardena.dev/v1';
        $this->authEndpoint = 'https://api.authentication.husqvarnagroup.dev/v1';
        if (array_key_exists('gardena', $connectors)) {
            $this->clientId = $connectors['gardena']['clientId'];
            $this->username = $connectors['gardena']['username'];
            $this->password = $connectors['gardena']['password'];
        }
    }

    public function availableDevices()
    {
        return $this->em->getRepository('App:Settings')->findByType('gardena');
    }

    public function updateDevices()
    {
        $devices = $this->getDevices();
        foreach ($devices as $device) {
            $settings = $this->em->getRepository('App:Settings')->findOneByConnectorId($device['id']);
            if (!$settings) {
                $settings = new Settings();
                $settings->setConnectorId($device['id']);
            }
            $settings->setType('gardena');
            $settings->setConfig(['name' => $device['name']]);
            $this->em->persist($settings);
            $settings->setMode(Settings::MODE_AUTO);
        }
        $this->em->flush();
    }

    public function executeCommand($deviceId, $command)
    {
        return $this->sendValveCommand($deviceId, $command);
    }

    /*
     * send watering or stop command to device
     * $serviceId: corresponds to the id stored in settings table
     * $seconds: number of seconds the watering should last (if 0: stop immediately); must be a multiple of 60 !
     */
    private function sendValveCommand($serviceId, $seconds)
    {
        if ($seconds > 0) {
            $attributes = [
                "command" => "START_SECONDS_TO_OVERRIDE",
                "seconds" => $seconds,
            ];
        } else {
             $attributes = [
                "command" => "STOP_UNTIL_NEXT_TASK",
            ];
        }

        try {
            $response = $this->client->request(
                'PUT',
                $this->baseUrl . '/command/' . $serviceId,
                [
                    'auth_bearer' => $this->getToken(),
                    'headers' => [
                        'Authorization-Provider' => 'husqvarna',
                        'X-Api-Key' => $this->clientId,
                        'Content-Type' => 'application/vnd.api+json'
                    ],
                    'body' =>json_encode([
                        "data" => [
                            "id" => uniqid(),
                            "type" => "VALVE_CONTROL",
                            "attributes" => $attributes
                        ]
                    ])
                ]
            );
        } catch (Exception $ex) {
            return false;
        }

        return true;
    }

    private function getToken()
    {
        if (!$this->token) {
            $this->setToken();
        }

        return $this->token;
    }

    /*
     * Sets the bearer token for the usage of the API using the clientId, username and password
     */
    private function setToken()
    {
        try {
            $response = $this->client->request(
                'POST',
                $this->authEndpoint . '/oauth2/token',
                [
                    'body' => [
                        'grant_type' => 'password',
                        'client_id' => $this->clientId,
                        'username' => $this->username,
                        'password' => $this->password,
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

    /*
     * Get locations associated with the user
     */
    private function getLocations()
    {
        $locations = [];
        try {
            $response = $this->client->request(
                'GET',
                $this->baseUrl . '/locations',
                [
                    'auth_bearer' => $this->getToken(),
                    'headers' => [
                        'Authorization-Provider' => 'husqvarna',
                        'X-Api-Key' => $this->clientId
                    ]
                ]
            );
            if ($response->getStatusCode() === Response::HTTP_OK) {
                $content = json_decode($response->getContent(), true);
                $locations = $content['data'];
            }
        } catch (Exception $ex) {
            // do nothing
        }

        return $locations;
    }

    /*
     * Get location data for a specific location
     */
    private function getLocation($locationId)
    {
        $data = [];
        try {
            $response = $this->client->request(
                'GET',
                $this->baseUrl . '/locations/' . $locationId,
                [
                    'auth_bearer' => $this->getToken(),
                    'headers' => [
                        'Authorization-Provider' => 'husqvarna',
                        'X-Api-Key' => $this->clientId
                    ]
                ]
            );
            if ($response->getStatusCode() === Response::HTTP_OK) {
                return json_decode($response->getContent(), true);
            }
        } catch (Exception $ex) {
            // do nothing
        }

        return $data;
    }

    /*
     * Get state of devices/services of all associated locations
     */
    private function getDevices()
    {
        $devices = [];
        $locations = $this->getLocations();
        foreach ($locations as $location) {
            if (is_array($location) && array_key_exists('id', $location)) {
                $locationStates = $this->getLocation($location['id']);
                if (array_key_exists('included', $locationStates)) {
                    foreach ($locationStates['included'] as $included) {
                        if ($included['type'] == 'VALVE') {
                            $device['id'] = $included['id'];
                            $device['name'] = $included['attributes']['name']['value'];
                            $devices[] = $device;
                        }
                    }
                }
            }
        }

        return $devices;
    }
}
