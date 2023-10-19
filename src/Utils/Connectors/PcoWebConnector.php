<?php

namespace App\Utils\Connectors;

use App\Entity\Settings;
use App\Entity\PcoWebDataStore;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use ModbusTcpClient\Utils\Types;

/**
 * Connector to retrieve data from the PCO Web device
 * For information refer to www.careluk.com
 *
 * @author Markus Schafroth
 */
class PcoWebConnector extends ModbusTcpConnector
{
    protected $em;
    protected $client;
    protected $basePath;
    protected $ip;
    protected $connectors;
    const MODE_SUMMER = 0;
    const MODE_AUTO = 1;
    const MODE_HOLIDAY = 2;
    const MODE_PARTY = 3;
    const MODE_2ND = 4;
    const MODE_COOL = 5;
    const MODBUSTCP_OUTSIDETEMP = 1;
    const MODBUSTCP_WARMWATER = 3;
    const MODBUSTCP_WARMWATER_HYST = 5045;
    const MODBUSTCP_WARMWATER_SET = 5047;
    const MODBUSTCP_PRETEMP = 5;
    const MODBUSTCP_BACKTEMP = 2;
    const MODBUSTCP_STORTEMP = 10;
    const MODBUSTCP_SETDISTRTEMP = 54;
    const MODBUSTCP_EFFDISTRTEMP = 9;
    const MODBUSTCP_PPMODE = 5015;
    const MODBUSTCP_PPSTATUS = 41;
    const MODBUSTCP_PPSTATUS_MSG = 103;
    const MODBUSTCP_CPSTATUS = 51;
    const MODBUSTCP_PPSOURCEIN = 6;
    const MODBUSTCP_PPSOURCEOUT = 7;
    const MODBUSTCP_HC1 = 41108;
    const MODBUSTCP_HC2 = 41208;
    const MODBUSTCP_MODE = 12;

    public function __construct(EntityManagerInterface $em, HttpClientInterface $client, Array $connectors)
    {
        $this->em = $em;
        $this->client = $client;
        $this->ip = null;
        $this->connectors = $connectors;
        if (array_key_exists('pcoweb', $this->connectors)) {
            $this->ip = $this->connectors['pcoweb']['ip'];
            $this->port = 502;
            parent::__construct();
        }
        $this->basePath = 'http://' . $this->ip;
    }

    public function getAllLatest()
    {
        return $this->em->getRepository(PcoWebDataStore::class)->getLatest($this->ip);
    }

    public function getAll()
    {
        dump("GETTING PCOWEB");
        // get analog, digital and integer values
        if (true) {        
            $dataArr =  [
                'mode' => $this->pcowebModeToString($this->em->getRepository(Settings::class)->getMode($this->getIp())),
                'outsideTemp' => $this->readTempModbusTcp(self::MODBUSTCP_OUTSIDETEMP),
                'waterTemp' => $this->readTempModbusTcp(self::MODBUSTCP_WARMWATER),
                'setDistrTemp' => $this->readTempModbusTcp(self::MODBUSTCP_SETDISTRTEMP),
                'effDistrTemp' => $this->readTempModbusTcp(self::MODBUSTCP_EFFDISTRTEMP),
                'cpStatus' => $this->statusToString($this->readBoolModbusTcp(self::MODBUSTCP_CPSTATUS)),
                'ppStatus' => $this->statusToString($this->readBoolModbusTcp(self::MODBUSTCP_PPSTATUS)),
                'ppStatusMsg' => $this->ppStatusMsgToString($this->readUint16ModbusTcp(self::MODBUSTCP_PPSTATUS_MSG)),
                'ppMode' => $this->readPpModeModbusTcp(),
                'preTemp' => $this->readTempModbusTcp(self::MODBUSTCP_PRETEMP),
                'backTemp' => $this->readTempModbusTcp(self::MODBUSTCP_BACKTEMP),
                'hwHist' => $this->readUint16ModbusTcp(self::MODBUSTCP_WARMWATER_HYST),
                'storTemp' => $this->readTempModbusTcp(self::MODBUSTCP_STORTEMP),
                'ppSourceIn' => $this->readTempModbusTcp(self::MODBUSTCP_PPSOURCEIN),
                'ppSourceOut' => $this->readTempModbusTcp(self::MODBUSTCP_PPSOURCEOUT),
            ];

            // catch invalid responses
            if (!is_numeric($dataArr['waterTemp'])) {
                $dataArr = false;
            }
            return $dataArr;
        } else { //catch (\Exception $e) {
            dump("EXCEPTION!");
          return false;
        }
    }

    public function getIp()
    {
        return $this->ip;
    }

    public function getPower()
    {
        if (array_key_exists('pcoweb', $this->connectors) && array_key_exists('power', $this->connectors['pcoweb'])) {
            return $this->connectors['pcoweb']['power'];
        } else {
            return 3000;
        }
    }

    public function getConfiguredMinWaterTemp()
    {
        if (array_key_exists('min_water_temp', $this->connectors['pcoweb'])) {
            return floatval($this->connectors['pcoweb']['min_water_temp']);
        } else {
            return 30;
        }
    }

    public function executeCommand($type, $command)
    {
        switch ($type) {
            case 'mode':
                // mode must not be switched too frequently
                if ($this->switchOK()) {
                    $this->setMode($command);
                }
                break;
            case 'hwHysteresis':
                $this->setHotWaterHysteresis($command);
                break;
            case 'hc1':
                $this->setHeatCircle1($command);
                break;
            case 'hc2':
                $this->setHeatCircle2($command);
                break;
            case 'cpAutoMode':
                $this->setCpAutoMode($command);
                break;
            case 'waterTemp':
                $this->setWaterTemp($command);
                break;
        }
    }

    public function normalizeSettings()
    {
        $this->setHotWaterHysteresis(10);
        $this->setWaterTemp(52);
        $this->setCpAutoMode(1);
        $this->setHeatCircle1(20);
        $this->setHeatCircle2(20);
    }

    private function setMode($mode)
    {
        // set mode
        $data['?script:var(0,3,14,0,4)'] = $mode;
        $url = $this->basePath . '/http/index/j_modus.html';

        $this->postRequest($url, $data);
    }

    private function setHotWaterHysteresis($value)
    {
        // set mode
        $data['?script:var(0,3,44,2,15)'] = $value;
        $url = $this->basePath . '/http/index/j_settings_hotwater.html';

        $this->postRequest($url, $data);
    }

    private function setHeatCircle1($value)
    {
        $this->getRequest($this->basePath . '/usr-cgi/query.cgi?var|I|35|' . $value);
    }

    private function setHeatCircle2($value)
    {
        $this->getRequest($this->basePath . '/usr-cgi/query.cgi?var|I|85|' . $value);
    }

    /*
     * Optimierung Heizungsumwälzpumpe
     * 0: Ja   --> means, that the pump is deactivated as much as possible
     * 1: Nein --> means, that the pump runs always
     */
    private function setCpAutoMode($value)
    {
        // set mode binary
        $data['?script:var(0,1,131,0,1)'] = $value;
        $url = $this->basePath . '/http/index/j_settings_pumpcontrol.html';
        $this->postRequest($url, $data);

        // set mode temp based
        $tempval = 35; // always active
        if (!$value) {
            // we want optimization, i.e. not always active
            $tempval = -15;
        }
        $data['?script:var(0,3,165,-15,35)'] = $tempval;
        $this->postRequest($url, $data);
    }

    private function setWaterTemp($value)
    {
        // set mode
        $data['?script:var(0,3,46,30,85)'] = $value;
        $url = $this->basePath . '/http/index/j_hotwater.html';

        $this->postRequest($url, $data);
    }

    private function getRequest($url)
    {
        try {
            $response = $this->client->request('GET', $url)->getContent();
        } catch (\Exception $e) {
        // do nothing
        }
    }

    private function postRequest($url, $data)
    {
        // post request
        try {
            $response = $this->client->request(
                    'POST',
                    $url,
                    [
                        'body' => $data
                    ]
                )->getContent();
        } catch (\Exception $e) {
        // do nothing
        }
    }

    private function pcowebModeToString($mode)
    {
        switch ($mode) {
            case Settings::MODE_AUTO:
                return 'label.pco.mode.auto';
            case Settings::MODE_MANUAL:
                return 'label.pco.mode.manual';
            case Settings::MODE_HOLIDAY:
                return 'label.pco.mode.holiday';
            case Settings::MODE_WARMWATER:
                return 'label.pco.mode.warmwater';
        }
        return 'undefined';
    }

    private function statusToString($status)
    {
        if ($status) {
            return 'label.device.status.on';
        } else {
            return 'label.device.status.off';
        }
    }

    public function ppModeToInt($mode)
    {
        switch ($mode) {
            case 'label.pco.ppmode.summer':
                return self::MODE_SUMMER;
            case 'label.pco.ppmode.auto':
                return self::MODE_AUTO;
            case 'label.pco.ppmode.holiday':
                return self::MODE_HOLIDAY;
            case 'label.pco.ppmode.party':
                return self::MODE_PARTY;
            case 'label.pco.ppmode.2nd':
                return self::MODE_2ND;
            case 'label.pco.ppmode.cool':
                return self::MODE_COOL;
        }
        return -1;
    }

    public function ppStatusMsgToString($ppStatusMsg)
    {
        switch ($ppStatusMsg) {
            case 0:
                return "Aus";
            case 1:
                return "Aus";
            case 2:
                return "Heizen";
            case 3:
                return "Schwimmbad";
            case 4:
                return "Warmwasser";
            case 5:
                return "Kühlen";
            case 30:
                return "Sperre";
            default:
                return "andere";
        }
    }

    public function switchOK()
    {
        // get current status
        $currentStatus = $this->getAllLatest()['ppMode'];

        // get latest timestamp with opposite status
        $oldStatus = $this->em->getRepository(PcoWebDataStore::class)->getLatestNotStatus($this->getIp(), $currentStatus);
        if (count($oldStatus) == 1) {
            $oldTimestamp = $oldStatus[0]->getTimestamp();

            // calculate time diff
            $now = new \DateTime('now');
            $diff = ($now->getTimestamp() - $oldTimestamp->getTimestamp())/60; // diff in minutes
            if ($diff > 10) {
                return true;
            }
        } elseif (!$oldStatus) {
            // status has never been switched yet
            return true;
        }

        return false;
    }

    /*
     * read ppMode via ModbusTCP
     */
    private function readPpModeModbusTcp()
    {
        $ppModes = [
            0 => 'label.pco.ppmode.summer',
            1 => 'label.pco.ppmode.auto',
            2 => 'label.pco.ppmode.holiday',
            3 => 'label.pco.ppmode.party',
            4 => 'label.pco.ppmode.2nd',
            5 => 'label.pco.ppmode.cool',
        ];
        $bytes = $this->readBytesFc3ModbusTcp(self::MODBUSTCP_PPMODE);
        $ppModeInt = Types::parseUInt16(Types::byteArrayToByte($bytes));

        return $ppModes[$ppModeInt];
    }
}
