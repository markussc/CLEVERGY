<?php

namespace App\Utils\Connectors;

use App\Utils\Connectors\WeConnectIdConnector;
use App\Utils\Connectors\SmartFoxConnector;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Connector to retrieve data from electric cars
 *
 * @author Markus Schafroth
 */
class EcarConnector
{
    protected $em;
    protected $smartfox;
    protected $connectors;

    public function __construct(EntityManagerInterface $em, SmartFoxConnector $smartfox, Array $connectors)
    {
        $this->em = $em;
        $this->smartfox = $smartfox;
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
                    if (is_array($data) && !empty($data)) {
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

    public function getOneLatest($carId)
    {
        return $this->em->getRepository('App:EcarDataStore')->getLatest($carId);
    }

    public function checkHighPriority($switchDevice, $energyLowRate)
    {
        $priority = false;
        // get the current percentage for the car
        $latestEcar = $this->getOneLatest($switchDevice['carTimerData']['connectorId']);
        if (is_array($latestEcar) && array_key_exists('data', $latestEcar) && array_key_exists('soc', $latestEcar['data'])) {
            $currentPercent = $latestEcar['data']['soc'];
            $targetPercent = $switchDevice['carTimerData']['percent'];
            $capacity = $switchDevice['carTimerData']['capacity'];
            $chargingPower = 0.90 * $switchDevice['nominalPower']; // we expect 5% of charging losses
            $hourlyPercent = 100 / $capacity * $chargingPower / 1000;
            $percentDiff = $targetPercent - $currentPercent;
            $now = new \DateTime('now');
            $deadline = new \DateTime($switchDevice['carTimerData']['deadline']['date']);
            $diff = $now->diff($deadline);
            $hours = $diff->h;
            $hours = $hours + ($diff->days*24);
            if ($diff->invert) {
                $hours *= -1;
            }

            if ($hours >= 0 && $percentDiff > 0) {
                // the targetPercent and deadline are not reached yet
                // check if we need to start charging in order to reach the targetPercent until deadline
                $percentDuringDiff = $hourlyPercent * $hours;
                if  ($percentDuringDiff < $percentDiff) {
                    // we need to start immediately
                    $priority = true;
                } elseif (($hours < 24 && $percentDiff > $hourlyPercent*4) || ($energyLowRate && $hours < 48 && $percentDiff > $hourlyPercent*8) || $currentPercent < 30) {
                    // the deadline is within 24 hours from now and we need more than 8 hours charging left,
                    // or low rate and more than 8 hours charging left,
                    // or battery level below 30%
                    // in these cases we want to allow max half of the charging power from the grid
                    $switchState = $this->em->getRepository("App:MyStromDataStore")->getLatest($switchDevice['ip']);
                    $powerAverage = $this->em->getRepository("App:SmartFoxDataStore")->getNetPowerAverage($this->smartfox->getIp(), 15);
                    if ($switchState && $powerAverage < $switchDevice['nominalPower']/2) {
                        // currently charging, we want at least half of the charging power by self production
                        $priority = true;
                    }  elseif (!$switchState && $powerAverage < -1*$switchDevice['nominalPower']/2) {
                        // currently not charging, we want half of the charging power by self production after switching on
                        $priority = true;
                    }
                }
            }
            if ($energyLowRate && $currentPercent < 15) {
                $priority = true;
            }
        }

        return $priority;
    }

    function startCharging($id)
    {
        if ($this->carAvailable() && array_key_exists($id, $this->connectors['ecar']) && array_key_exists('type', $this->connectors['ecar'][$id])) {
            if ($this->connectors['ecar'][$id]['type'] == 'id3') {
                $weConnectId = new WeConnectIdConnector($this->connectors['ecar'][$id]);
                $data = $weConnectId->startCharging();
            }
        }
    }

    function stopCharging($id)
    {
        if ($this->carAvailable() && array_key_exists($id, $this->connectors['ecar']) && array_key_exists('type', $this->connectors['ecar'][$id])) {
            if ($this->connectors['ecar'][$id]['type'] == 'id3') {
                $weConnectId = new WeConnectIdConnector($this->connectors['ecar'][$id]);
                $data = $weConnectId->stopCharging();
            }
        }
    }
}
