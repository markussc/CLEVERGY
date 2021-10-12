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

    public function __construct(Array $config = [])
    {
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
            $output = shell_exec('weconnect-cli --username ' . $this->username . ' --password ' . $this->password . ' get /vehicles/' . $this->carId . '/status/batteryStatus');
            // find cruisingRange
            $output = str_replace("\t", "", $output);
            $outputArr = explode("\n", $output);
            $data['soc'] = str_replace("%", "", str_replace("Current SoC: ", "", $outputArr[1]));
            $data['range'] = str_replace("km", "", str_replace("Range: ", "", $outputArr[2]));
        } catch (\Exception $e) {
            // do nothing
        }

        return $data;
    }
}