<?php

namespace App\Utils\Connectors;

use App\Entity\TaCmiDataStore;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Connector to retrieve data from the TA Technische Alternative CMI API (see https://www.ta.co.at/download/datei/17511763-cmi-json-api/ )
 *
 * @author Markus Schafroth
 */
class TaCmiConnector
{
    protected $em;
    protected $client;
    protected $basePath;
    protected $connectors;

    public function __construct(EntityManagerInterface $em, HttpClientInterface $client, Array $connectors)
    {
        $this->em = $em;
        $this->client = $client;
        $this->connectors = $connectors;
        $this->ip = null;
        $this->basePath = '';
        $this->query = '';
        if (array_key_exists('tacmi', $connectors)) {
            $this->ip = $connectors['tacmi']['ip'];
            $this->query = $connectors['tacmi']['query'];
            $this->basePath = 'http://' . $this->ip . '/INCLUDE/api.cgi?';
        }
    }

    /**
     * Reads the latest available data from the database
     * @return array
     */
    public function getAllLatest()
    {
        $latest = [];
        if (array_key_exists('tacmi', $this->connectors)) {
            $ip = $this->connectors['tacmi']['ip'];
            $latest = $this->em->getRepository(TaCmiDataStore::class)->getLatest($ip);
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
            $url = $this->basePath . $this->query;
            $response = $this->client->request('GET', $url, [
                'auth_basic' => [$this->connectors['tacmi']['username'], $this->connectors['tacmi']['password']]
            ]);
            $content = $response->getContent();
            $rawdata = json_decode($content, true);
        } catch (\Exception $e) {
          // do nothing
        }


        $data = [];
        if (isset($rawdata) && is_array($rawdata) && array_key_exists('Data', $rawdata) && array_key_exists('tacmi', $this->connectors)) {
            foreach ($this->connectors['tacmi']['sensors'] as $key => $sensor) {
                $i = 0;
                foreach ($rawdata['Data'] as $category) {
                    foreach ($category as $value) {
                        if ($i == $key) {
                            $data[$sensor[0]] = $value['Value']['Value'];
                        }
                        $i++;
                    }
                }
            }
        }

        return $data;
    }

    public function getIp()
    {
        return $this->ip;
    }
}
