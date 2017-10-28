<?php

namespace AppBundle\Utils\Connectors;

use RobStiles\EdiPlug\EdiPlug;

/**
 * Connector to retrieve data from EdiMax devices
 * For information refer to www.edimax.com
 *
 * @author Markus Schafroth
 */
class EdiMaxConnector
{
    protected $browser;
    protected $connectors;

    public function __construct(\Buzz\Browser $browser, Array $connectors)
    {
        $this->browser = $browser;
        $this->connectors = $connectors;
    }

    public function getAllStati()
    {
        $results = [];
        foreach ($this->connectors['edimax'] as $device) {
            $status = $this->getStatus($device);
            $results[] = [
                'name' => $device['name'],
                'status' => $status,
            ];
        }
        return $results;
    }

    public function executeCommand($deviceId, $command)
    {
        switch ($command) {
            case 1:
                // turn it on
                return $this->setOn($this->connectors['edimax'][$deviceId]);
                break;
            case 0:
                // turn it off
                return $this->setOff($this->connectors['edimax'][$deviceId]);
                break;
        }
        // no known command
        return false;
    }

    private function getStatus($device)
    {
        $r = $this->queryEdiMax($device, 'status');
        if (!empty($r) && array_key_exists('CMD', $r) && array_key_exists('Device.System.Power.State', $r['CMD']) && $r['CMD']['Device.System.Power.State'] == 'ON') {
            return [
                'label' => 'label.device.status.on',
                'val' => 1,
            ];
        } else {
            return [
                'label' => 'label.device.status.off',
                'val' => 0,
            ];
        }
    }

    private function setOn($device)
    {
        $r = $this->queryEdiMax($device, 'on');
        if (!empty($r) AND array_key_exists('CMD', $r) AND $r['CMD'] == 'OK') {
            return true;
        } else {
            return false;
        }
    }

    private function setOff($device)
    {
        $r = $this->queryEdiMax($device, 'off');
        if (!empty($r) AND array_key_exists('CMD', $r) AND $r['CMD'] == 'OK') {
            return true;
        } else {
            return false;
        }
    }

    private function queryEdiMax($device, $cmd)
    {
        switch ($cmd) {
            case 'status':
                $xmlRequest = '<?xml version="1.0" encoding="UTF8"?><SMARTPLUG id="edimax"><CMD id="get"><Device.System.Power.State></Device.System.Power.State></CMD></SMARTPLUG>';
                break;
            case 'on':
                $xmlRequest = '<?xml version="1.0" encoding="utf-8"?><SMARTPLUG id="edimax"><CMD id="setup"><Device.System.Power.State>ON</Device.System.Power.State></CMD></SMARTPLUG>';
                break;
            case 'off';
                $xmlRequest =  '<?xml version="1.0" encoding="utf-8"?><SMARTPLUG id="edimax"><CMD id="setup"><Device.System.Power.State>OFF</Device.System.Power.State></CMD></SMARTPLUG>';
                break;
            default:
                $xmlRequest = '';
        }
        $data =  [
            'xmlRequest' => $xmlRequest,
        ];
        
        $headers = [
            'Content-Type' => 'application/xml',
            'Content-Length' => strlen($data['xmlRequest'])
        ];

        $this->browser->addListener(new \Buzz\Listener\DigestAuthListener($device['username'], $device['password']));
        $url = 'http://' . $device['ip'] . ':10000/smartplug.cgi';
        $response = $this->browser->post($url, $headers, 'xmlRequest='.$data['xmlRequest']);

        $statusCode = $response->getStatusCode();
        if ($statusCode == 401) {
            $responseXml = $this->browser->post($url, $headers, 'xmlRequest='.$data['xmlRequest'])->getContent();
        } else {
            $responseXml = $response->getContent();
        }

        $ob = simplexml_load_string($responseXml);
        $json  = json_encode($ob);
        return json_decode($json, true);
    }
}
