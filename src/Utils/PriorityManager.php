<?php

namespace App\Utils;

use App\Utils\Connectors\MyStromConnector;
use App\Utils\Connectors\ShellyConnector;
use App\Utils\Connectors\EcarConnector;
use App\Utils\ConditionChecker;

class PriorityManager
{
    private $connectors;
    private $condition;
    private $mystrom;
    private $shelly;
    private $ecar;

    public function __construct($connectors, MyStromConnector $mystrom, ShellyConnector $shelly, EcarConnector $ecar)
    {
        $this->connectors = $connectors;
        $this->mystrom = $mystrom;
        $this->shelly = $shelly;
        $this->ecar = $ecar;
    }

    // check if there is at least one device with priority >= $priority which is currently turned off but ready to be turned on (based on its switchOK and condition check)
    // returns true if found a device, false if there is no waiting device
    public function checkWaitingDevice(ConditionChecker $condition, $priority, $maxNominalPower, $energyLowRate = false)
    {
        if (array_key_exists('mystrom', $this->connectors) && is_array($this->connectors['mystrom'])) {
            if($this->checkStartDevice($condition, $this->mystrom, $this->connectors['mystrom'], $priority, $maxNominalPower, $energyLowRate)) {
                return true;
            }
        }
        if (array_key_exists('shelly', $this->connectors) && is_array($this->connectors['shelly'])) {
            if($this->checkStartDevice($condition, $this->shelly, $this->connectors['shelly'], $priority, $maxNominalPower, $energyLowRate)) {
                return true;
            }
        }
        return false;
    }

    // check if there is at least one device with priority <= priority which is currently turned on but ready to be turned off (based on its switchOK check)
    // returns true if found a device, false if there is no stopping-ready device
    public function checkStoppingDevice(ConditionChecker $condition, $priority)
    {
        if (array_key_exists('mystrom', $this->connectors) && is_array($this->connectors['mystrom'])) {
            if($this->checkStopDevice($condition, $this->mystrom, $this->connectors['mystrom'], $priority)) {
                return true;
            }
        }
        if (array_key_exists('shelly', $this->connectors) && is_array($this->connectors['shelly'])) {
            if($this->checkStopDevice($condition, $this->shelly, $this->connectors['shelly'], $priority)) {
                return true;
            }
        }
        return false;
    }

    private function checkStartDevice($condition, $conn, $devices, $priority, $maxNominalPower, $energyLowRate = false)
    {
        foreach ($devices as $deviceId => $dev) {
            if(array_key_exists("nominalPower", $dev)) {
                $nominalPower = $dev['nominalPower'];
                if (array_key_exists('type', $dev) && $dev['type'] == 'carTimer') {
                    $switchDevice = $conn->getConfig($conn->getId($dev));
                    $nominalPower = $switchDevice['nominalPower'];
                    if ($this->ecar->checkHighPriority($switchDevice, $energyLowRate)) {
                        $nominalPower = $switchDevice['nominalPower'] / 2;
                    }
                }
                if (array_key_exists('priority', $dev) && intval($dev['priority']) >= intval($priority) && $nominalPower <= $maxNominalPower) {
                    // check if currently off
                    $status = $conn->getStatus($dev);
                    if (isset($status['offline'])) {
                        // error while retrieving status; we assume the device is currently offline and could not be turned on anyway
                        continue;
                    }
                    if ($status['val']) {
                        // already running
                        continue;
                    }
                    if ($conn->switchOK($deviceId) &&!$condition->checkCondition($dev, 'forceOff')) {
                        // switching is OK and the device is not forcedOff
                        return true;
                    }
                }
            }
        }
        return false;
    }

    private function checkStopDevice(ConditionChecker $condition, $conn, $devices, $priority)
    {
        foreach ($devices as $deviceId => $dev) {
            if (array_key_exists('priority', $dev) && intval($dev['priority']) <= intval($priority)) {
                // check if currently running
                $status = $conn->getStatus($dev);
                if (isset($status['offline'])) {
                    // error while retrieving status; we assume the device is currently offline and could not be turned off anyway
                    continue;
                }
                if (!$status['val']) {
                    // already stopped
                    continue;
                }
                if ($conn->switchOK($deviceId) && !$condition->checkCondition($dev, 'forceOn')) {
                    // switching is OK and the device is not forcedOn
                    return true;
                }
            }
        }
        return false;
    }
}

