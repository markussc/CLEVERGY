<?php

namespace App\Utils\Connectors;

/**
 * Connector to retrieve data from the WeConnectID API (Volkswagen)
 * Note: requires the following prerequisites installed on the system: https://pypi.org/project/weconnect-cli/
 *
 * @author Mara Schafroth
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
        if (is_array($config) && array_key_exists('carId', $config)) {
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
            $dataJson = shell_exec('carconnectivity-cli get / --format json');

            $dataArr = json_decode($dataJson, true);
            $data['soc'] = $dataArr['garage'][$this->carId]['drives']['primary']['level']['val'];
            $data['capacity'] = $dataArr['garage'][$this->carId]['drives']['primary']['battery']['available_capacity']['val']; // currently the value from the config file is used
            $data['range'] = null; // currently not available in the data set
            $data['plugConnectionState'] = $dataArr['garage'][$this->carId]['charging']['state']['val']; // on / off
            $data['chargePower_kW'] = $dataArr['garage'][$this->carId]['charging']['power']['val'];
            $data['isOnline'] = $dataArr['connectors']['vw_eu_data_act']['connection_state']['val'] == 'connected' ? true : false; // connected // disconnected
            $data['isActive'] = null; // currently not available in the data set
        } catch (\Exception $e) {
            // do nothing
        }

        return $data;
    }

    public function startCharging(): void
    {
        try {
            //shell_exec('weconnect-cli --username ' . $this->username . ' --password ' . $this->password . ' set /vehicles/' . $this->carId . '/controls/charging start');
            // do nothing as controls are not currently available
        } catch (\Exception $e) {
            // do nothing
        }
    }

    public function stopCharging(): void
    {
        try {
            //shell_exec('weconnect-cli --username ' . $this->username . ' --password ' . $this->password . ' set /vehicles/' . $this->carId . '/controls/charging stop');
            // do nothing as controls are not currently available
        } catch (\Exception $e) {
            // do nothing
        }
    }
}