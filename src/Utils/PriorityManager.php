<?php

namespace App\Utils;

use App\Utils\Connectors\EdiMaxConnector;
use App\Utils\Connectors\MyStromConnector;
use App\Utils\Connectors\ShellyConnector;
use App\Utils\Connectors\EcarConnector;

class PriorityManager
{
    private $connectors;

    public function __construct($connectors, EdiMaxConnector $edimax, MyStromConnector $mystrom, ShellyConnector $shelly, EcarConnector $ecar)
    {
        $this->connectors = $connectors;
        $this->edimax = $edimax;
        $this->mystrom = $mystrom;
        $this->shelly = $shelly;
        $this->ecar = $ecar;
    }

    // check if there is at least one device with priority >= $priority which is currently turned off but ready to be turned on (based on its switchOK check)
    // returns true if found a device, false if there is no waiting device
    public function checkWaitingDevice($priority, $maxNominalPower, $energyLowRate = false)
    {
        if (array_key_exists('edimax', $this->connectors) && is_array($this->connectors['edimax'])) {
            if($this->checkStartDevice($this->mystrom, $this->connectors['edimax'], $priority, $maxNominalPower)) {
                return true;
            }
        }
        if (array_key_exists('mystrom', $this->connectors) && is_array($this->connectors['mystrom'])) {
            if($this->checkStartDevice($this->mystrom, $this->connectors['mystrom'], $priority, $maxNominalPower, $energyLowRate)) {
                return true;
            }
        }
        if (array_key_exists('shelly', $this->connectors) && is_array($this->connectors['shelly'])) {
            if($this->checkStartDevice($this->shelly, $this->connectors['shelly'], $priority, $maxNominalPower)) {
                return true;
            }
        }
        return false;
    }

    // check if there is at least one device with priority <= priority which is currently turned on but ready to be turned off (based on its switchOK check)
    // returns true if found a device, false if there is no stopping-ready device
    public function checkStoppingDevice($priority)
    {
        if (array_key_exists('edimax', $this->connectors) && is_array($this->connectors['edimax'])) {
            if($this->checkStopDevice($this->mystrom, $this->connectors['edimax'], $priority)) {
                return true;
            }
        }
        if (array_key_exists('mystrom', $this->connectors) && is_array($this->connectors['mystrom'])) {
            if($this->checkStopDevice($this->mystrom, $this->connectors['mystrom'], $priority)) {
                return true;
            }
        }
        if (array_key_exists('shelly', $this->connectors) && is_array($this->connectors['shelly'])) {
            if($this->checkStopDevice($this->shelly, $this->connectors['shelly'], $priority)) {
                return true;
            }
        }
        return false;
    }

    private function checkStartDevice($conn, $devices, $priority, $maxNominalPower, $energyLowRate = false)
    {
        foreach ($devices as $deviceId => $dev) {
            if(array_key_exists("nominalPower", $dev)) {
                $nominalPower = $dev['nominalPower'];
                if (array_key_exists('type', $dev) && $dev['type'] == 'carTimer') {
                    $switchDevice = $this->mystrom->getConfig($dev['ip']);
                    if ($this->ecar->checkHighPriority($switchDevice, $energyLowRate)) {
                        $nominalPower = $dev['nominalPower'] / 2;
                    }
                }
                if (array_key_exists('priority', $dev) && intval($dev['priority']) >= intval($priority) && $nominalPower <= $maxNominalPower) {
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
        }
        return false;
    }

    private function checkStopDevice($conn, $devices, $priority)
    {
        foreach ($devices as $deviceId => $dev) {
            if (array_key_exists('priority', $dev) && intval($dev['priority']) <= intval($priority)) {
                // check if currently running
                $status = $conn->getStatus($dev);
                if (!$status['val']) {
                    // already stopped
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

