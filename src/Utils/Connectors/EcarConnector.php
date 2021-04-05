<?php

namespace App\Utils\Connectors;

use Doctrine\ORM\EntityManagerInterface;
use App\Entity\Settings;

/**
 * Connector to retrieve data from electric cars
 *
 * @author Markus Schafroth
 */
class EcarConnector
{
    protected $em;
    protected $browser;
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
    public function getAllLatest()
    {
        $results = [];
        if ($this->carAvailable()) {
            foreach ($this->connectors['ecar'] as $device) {
                $results[] = [
                    'name' => $device['name'],
                    'battery' => 'label.ecar.battery.unknown',
                    'location' => 'label.ecar.location.unknown',
                    'status' => 'label.ecar.location.unknown',
                ];
            }
        }

        return $results;
    }
}
