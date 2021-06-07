<?php

namespace App\Utils\Connectors;

use App\Utils\Connectors\WeConnectIdConnector;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Connector to retrieve data from electric cars
 *
 * @author Markus Schafroth
 */
class EcarConnector
{
    protected $em;
    protected $connectors;

    public function __construct(EntityManagerInterface $em, Array $connectors)
    {
        $this->em = $em;
        $this->connectors = $connectors;
    }

    public function carAvailable()
    {
        if (array_key_exists('ecar', $this->connectors) && is_array($this->connectors['ecar'])) {
            return true;
        }

        return false;
    }

    /**
     * Reads the latest available data from the database
     * @return array
     */
    public function getAll()
    {
        $results = [];
        if ($this->carAvailable()) {
            foreach ($this->connectors['ecar'] as $device) {
                if ($device['type'] == 'id3') {
                    $weConnectId = new WeConnectIdConnector($device);
                    $data = $weConnectId->getData();
                    if (is_array($data) && array_key_exists('properties', $data) && $data['properties'][1]['value'] != '') {
                        $results[] = [
                            'name' => $device['name'],
                            'carId' => $device['carId'],
                            'data' => $data,
                        ];
                    }
                }
            }
        }

        return $results;
    }

    /**
     * Reads the latest available data from the database
     * @return array
     */
    public function getAllLatest()
    {
        $latest = [];
        if (array_key_exists('ecar', $this->connectors)) {
            foreach ($this->connectors['ecar'] as $ecar) {
                $data = $this->em->getRepository('App:EcarDataStore')->getLatest($ecar['carId']);
                if ($data) {
                    $latest[] = $data;
                }
            }
        }

        return $latest;
    }
}
