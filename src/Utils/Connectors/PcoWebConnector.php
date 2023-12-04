<?php

namespace App\Utils\Connectors;

use App\Entity\Settings;
use App\Entity\PcoWebDataStore;
use Doctrine\ORM\EntityManagerInterface;
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
    const MODBUSTCP_WARMWATER_RESET = 136;
    const MODBUSTCP_PRETEMP = 5;
    const MODBUSTCP_BACKTEMP = 2;
    const MODBUSTCP_BACKSETTEMP = 53;
    const MODBUSTCP_STORTEMP = 10;
    const MODBUSTCP_SETDISTRTEMP = 54;
    const MODBUSTCP_EFFDISTRTEMP = 9;
    const MODBUSTCP_PPMODE = 5015;
    const MODBUSTCP_PPSTATUS = 41;
    const MODBUSTCP_PPSTATUS_MSG = 103;
    const MODBUSTCP_CPSTATUS = 51;
    const MODBUSTCP_PPSOURCEIN = 6;
    const MODBUSTCP_PPSOURCEOUT = 7;
    const MODBUSTCP_SELECT_HC2 = 5082;
    const MODBUSTCP_HC1 = 5036;
    const MODBUSTCP_HC2 = 5086;
    const MODBUSTCP_CPOPT = 131;
    const MODBUSTCP_CPOPTTEMP = 5166;
    const MODBUSTCP_MODE = 12;
    const MODBUSTCP_PP_ERROR = 105;

    public function __construct(EntityManagerInterface $em, Array $connectors)
    {
        $this->em = $em;
        $this->ip = null;
        $this->connectors = $connectors;
        if (array_key_exists('pcoweb', $this->connectors)) {
            $this->ip = $this->connectors['pcoweb']['ip'];
            $this->port = 502;
            parent::__construct();
        }
    }

    public function getAllLatest()
    {
        return $this->em->getRepository(PcoWebDataStore::class)->getLatest($this->ip);
    }

    public function getAll()
    {
        // get analog, digital and integer values
        try {
            $dataArr =  [
                'mode' => $this->pcowebModeToString($this->em->getRepository(Settings::class)->getMode($this->getIp())),
                'outsideTemp' => $this->readTempModbusTcp(self::MODBUSTCP_OUTSIDETEMP),
                'waterTemp' => $this->readTempModbusTcp(self::MODBUSTCP_WARMWATER),
                'setDistrTemp' => $this->readTempModbusTcp(self::MODBUSTCP_SETDISTRTEMP),
                'effDistrTemp' => $this->readTempModbusTcp(self::MODBUSTCP_EFFDISTRTEMP),
                'cpStatus' => $this->statusToString($this->readBoolModbusTcp(self::MODBUSTCP_CPSTATUS)),
                'ppStatus' => $this->statusToString($this->readBoolModbusTcp(self::MODBUSTCP_PPSTATUS)),
                'ppStatusMsg' => $this->ppStatusMsgToString($this->readUint16ModbusTcp(self::MODBUSTCP_PPSTATUS_MSG)),
                'ppError' => $this->ppErrorToString($this->readUint16ModbusTcp(self::MODBUSTCP_PP_ERROR)),
                'ppMode' => $this->readPpModeModbusTcp(),
                'preTemp' => $this->readTempModbusTcp(self::MODBUSTCP_PRETEMP),
                'backTemp' => $this->readTempModbusTcp(self::MODBUSTCP_BACKTEMP),
                'backSetTemp' => $this->readTempModbusTcp(self::MODBUSTCP_BACKSETTEMP),
                'hwHist' => $this->readUint16ModbusTcp(self::MODBUSTCP_WARMWATER_HYST),
                'storTemp' => $this->readTempModbusTcp(self::MODBUSTCP_STORTEMP),
                'ppSourceIn' => $this->readTempModbusTcp(self::MODBUSTCP_PPSOURCEIN),
                'ppSourceOut' => $this->readTempModbusTcp(self::MODBUSTCP_PPSOURCEOUT),
            ];
            // check for mapping
            if (array_key_exists('mapping', $this->connectors['pcoweb']) && is_array($this->connectors['pcoweb']['mapping'])) {
                if (array_key_exists('analog', $this->connectors['pcoweb']['mapping'])) {
                    foreach ($this->connectors['pcoweb']['mapping']['analog'] as $key => $val) {
                        $dataArr[$key] = $this->readTempModbusTcp(intval($val));
                    }
                }
                if (array_key_exists('digital', $this->connectors['pcoweb']['mapping'])) {
                    foreach ($this->connectors['pcoweb']['mapping']['digital'] as $key => $val) {
                        $dataArr[$key] = $this->readBoolModbusTcp(intval($val));
                    }
                }
                if (array_key_exists('integer', $this->connectors['pcoweb']['mapping'])) {
                    foreach ($this->connectors['pcoweb']['mapping']['integer'] as $key => $val) {
                        $dataArr[$key] = $this->readUint16ModbusTcp(intval($val));
                    }
                }
            }

            // catch invalid responses
            if (!is_numeric($dataArr['waterTemp'])) {
                $dataArr = false;
            }
            return $dataArr;
        } catch (\Exception $e) {
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
            case 'waterTempReset':
                $this->resetWaterTemp();
                break;
        }
    }

    public function normalizeSettings()
    {
        $this->setHotWaterHysteresis(10);
        $this->setWaterTemp(52);
        $this->setCpAutoMode();
        $this->setHeatCircle1(20);
        $this->setHeatCircle2(20);
    }

    private function setMode($mode)
    {
        $this->writeBytesFc3ModbusTcp(self::MODBUSTCP_PPMODE, $mode);
    }

    private function setHotWaterHysteresis($value)
    {
        $this->writeBytesFc3ModbusTcp(self::MODBUSTCP_WARMWATER_HYST, $value);
    }

    private function setHeatCircle1($value)
    {
        $this->writeBytesFc3ModbusTcp(self::MODBUSTCP_HC1, $value);
    }

    private function setHeatCircle2($value)
    {
        $this->writeBytesFc3ModbusTcp(self::MODBUSTCP_SELECT_HC2, 2);
        $this->writeBytesFc3ModbusTcp(self::MODBUSTCP_HC2, $value);
    }

    /*
     * Optimierung Heizungsumwälzpumpe
     * 0: Ja   --> means, that the pump is deactivated as much as possible
     * 1: Nein --> means, that the pump runs always
     */
    private function setCpAutoMode($value = null)
    {
        switch ($value) {
            case 0:
                // optimized
                $this->writeBoolModbusTcp(self::MODBUSTCP_CPOPT, false);
                $this->writeBytesFc3ModbusTcp(self::MODBUSTCP_CPOPTTEMP, 3);
                break;
            case 1:
                // not optimized (run always)
                $this->writeBoolModbusTcp(self::MODBUSTCP_CPOPT, true);
                $this->writeBytesFc3ModbusTcp(self::MODBUSTCP_CPOPTTEMP, 25);
                break;
            default:
                // set default behaviour (optimized)
                // optimized
                $this->writeBoolModbusTcp(self::MODBUSTCP_CPOPT, false);
                $this->writeBytesFc3ModbusTcp(self::MODBUSTCP_CPOPTTEMP, 18);
                break;
        }
    }

    private function setWaterTemp($value)
    {
        $this->writeBytesFc3ModbusTcp(self::MODBUSTCP_WARMWATER_SET, $value);
    }

    private function resetWaterTemp()
    {
        $this->writeBoolModbusTcp(self::MODBUSTCP_WARMWATER_RESET, true);
        dump("reset");
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

    public function ppErrorToString($ppError)
    {
        switch ($ppError) {
            case 0:
                return null;
            case 15:
                return "Sensorik";
            case 16:
                return "Niederdruck Sole";
            case 19:
                return "!Primärkreis";
            case 20:
                return "!Abtauen";
            case 21:
                return "!Niederdruck Sole";
            case 22:
                return "!Warmwasser";
            case 23:
                return "!Last Verdichter";
            case 24:
                return "!Codierung";
            case 25:
                return "!Niederdruck";
            case 26:
                return "!Frostschutz";
            case 28:
                return "!Hochdruck";
            case 29:
                return "!Temperatur Differenz";
            case 30:
                return "!Heissgasthermostat";
            case 31:
                return "!Durchfluss";
            default:
                "undefined";
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
