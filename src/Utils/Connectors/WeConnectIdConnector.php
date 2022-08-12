<?php

namespace App\Utils\Connectors;

/**
 * Connector to retrieve data from the WeConnectID API (Volkswagen)
 * Note: requires the following prerequisites installed on the system: https://pypi.org/project/weconnect-cli/
 *
 * @author Markus Schafroth
 */
class WeConnectIdConnector
{
    private $username;
    private $password;
    private $carId;
    private $energyLowRate;

    public function __construct(Array $config = [], $energyLowRate = false)
    {
        $this->energyLowRate = $energyLowRate;
        if (is_array($config) && array_key_exists('username', $config) && array_key_exists('password', $config) && array_key_exists('carId', $config)) {
            $this->username = $config['username'];
            $this->password = $config['password'];
            $this->carId = $config['carId'];
        }
    }

    /*
     * try to get as much data as possible from the overview page
     */
    public function getData()
    {
        $data = [];
        try {
            $chargingJson = shell_exec('weconnect-cli --interval 600 --username ' . $this->username . ' --password ' . $this->password . ' get /vehicles/' . $this->carId . '/domains/charging --format json');
            $readinessStatusJson = shell_exec('weconnect-cli --interval 600 --username ' . $this->username . ' --password ' . $this->password . ' get /vehicles/' . $this->carId . '/domains/readiness/readinessStatus --format json');

            $charging = json_decode($chargingJson, true);
            $readinessStatus = json_decode($readinessStatusJson, true);
            $data['soc'] = $charging['batteryStatus']['currentSOC_pct'];
            $data['range'] = $charging['batteryStatus']['cruisingRangeElectric_km'];
            $data['plugConnectionState'] = $charging['plugStatus']['plugConnectionState'];
            $data['chargePower_kW'] = $charging['chargingStatus']['chargePower_kW'];
            if (array_key_exists('connectionState', $readinessStatus)) {
                $data['isOnline'] = $readinessStatus['connectionState']['isOnline'];
                $data['isActive'] = $readinessStatus['connectionState']['isActive'];
            }
        } catch (\Exception $e) {
            // do nothing
        }

        return $data;
    }

    public function startCharging()
    {
        try {
            shell_exec('weconnect-cli --username ' . $this->username . ' --password ' . $this->password . ' set /vehicles/' . $this->carId . '/controls/charging start');
        } catch (\Exception $e) {
            // do nothing
        }
    }

    public function stopCharging()
    {
        try {
            shell_exec('weconnect-cli --username ' . $this->username . ' --password ' . $this->password . ' set /vehicles/' . $this->carId . '/controls/charging stop');
        } catch (\Exception $e) {
            // do nothing
        }
    }
}