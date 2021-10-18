<?php

namespace App\Utils;

use App\Utils\Connectors\EdiMaxConnector;
use App\Utils\Connectors\MyStromConnector;
use App\Utils\Connectors\ShellyConnector;

class PriorityManager
{
    private $connectors;

    public function __construct($connectors, EdiMaxConnector $edimax, MyStromConnector $mystrom, ShellyConnector $shelly)
    {
        $this->connectors = $connectors;
        $this->edimax = $edimax;
        $this->mystrom = $mystrom;
        $this->shelly = $shelly;
    }

    // check if there is at least one device with priority >= $priority which is currently turned off but ready to be turned on (based on its switchOK check)
    // returns the true if found a device, false if there is no waiting device
    public function checkWaitingDevice($priority, $maxNominalPower)
    {
        if (array_key_exists('edimax', $this->connectors) && is_array($this->connectors['edimax'])) {
            if($this->checkDevice($this->mystrom, $this->connectors['edimax'], $priority, $maxNominalPower)) {
                return true;
            }
        }
        if (array_key_exists('mystrom', $this->connectors) && is_array($this->connectors['mystrom'])) {
            if($this->checkDevice($this->mystrom, $this->connectors['mystrom'], $priority, $maxNominalPower)) {
                return true;
            }
        }
        if (array_key_exists('shelly', $this->connectors) && is_array($this->connectors['shelly'])) {
            if($this->checkDevice($this->shelly, $this->connectors['shelly'], $priority, $maxNominalPower)) {
                return true;
            }
        }
        return false;
    }

    private function checkDevice($conn, $devices, $priority, $maxNominalPower)
    {
        foreach ($devices as $deviceId => $dev) {
            if (array_key_exists('priority', $dev) && intval($dev['priority']) >= intval($priority) && array_key_exists("nominalPower", $dev) && $dev['nominalPower'] <= $maxNominalPower) {
                // check if currently off
                $status = $conn->getStatus($dev);
                if ($status['val']) {
                    // already running
                    continue;
                }
                if ($conn->switchOK($deviceId)) {
                    // switching is OK
                    return true;
                }
            }
        }
        return false;
    }
}

