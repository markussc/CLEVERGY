<?php

namespace App\Utils\Connectors;

use Doctrine\ORM\EntityManagerInterface;
use Nesk\Puphpeteer\Puppeteer;
use App\Entity\Settings;
use App\Entity\WemDataStore;
use ModbusTcpClient\Utils\Types;

/**
 * Connector to retrieve data from the WEM Portal (Weishaupt Energy Manager)
 * Note: requires the following prerequisites installed on the system. Ubuntu: <code>composer require nesk/puphpeteer; npm install @nesk/puphpeteer</code>
 *
 * @author Markus Schafroth
 */
class WemConnector extends ModbusTcpConnector
{
    protected $em;
    private $basePath;
    private $browser;
    private $page;
    private $username;
    private $password;
    private $status;
    const UNAUTHENTICATED = 0;
    const AUTHENTICATED = 1;
    const UNAVAILABLE = -1;
    const MODBUSTCP_OUTSIDETEMP = 30001;
    const MODBUSTCP_WARMWATER = 32102;
    const MODBUSTCP_PRETEMP = 33104;
    const MODBUSTCP_BACKTEMP = 33105;
    const MODBUSTCP_STORTEMP = 33108;
    const MODBUSTCP_SETDISTRTEMP = 31204;
    const MODBUSTCP_EFFDISTRTEMP = 31205;
    const MODBUSTCP_PPMODE = 40001;
    const MODBUSTCP_PPSTATUS = 33103;
    const MODBUSTCP_CPSTATUS = 41202;
    const MODBUSTCP_PPSOURCEIN = 30002;
    const MODBUSTCP_PPSOURCEOUT = 33106;
    const MODBUSTCP_HC1 = 41108;
    const MODBUSTCP_HC2 = 41208;
    const MODBUSTCP_MODE = 30006;

    public function __construct(EntityManagerInterface $em, Array $connectors)
    {
        $this->em = $em;
        if (array_key_exists('wem', $connectors)) {
            $this->basePath = "https://www.wemportal.com/Web/";
            $this->username = $connectors['wem']['username'];
            $this->password = $connectors['wem']['password'];
            $this->ip = $connectors['wem']['ip'];
            $this->port = $connectors['wem']['port'];
            parent::__construct();
        }
        $this->status = self::UNAUTHENTICATED;
    }

    public function getAllLatest()
    {
        return $this->em->getRepository(WemDataStore::class)->getLatest($this->username);
    }

    public function getAll()
    {
        // get analog, digital and integer values
        try {
            $setDistrTemp = $this->readTempModbusTcp(self::MODBUSTCP_SETDISTRTEMP);
            if ($setDistrTemp === 0.1) {
                $setDistrTemp = '---';
                $cpStatus = 'label.device.status.off';
            } else {
                $cpStatus = $this->readCpStatusModbusTcp();
            }
            $modbusTcpData = [
                'outsideTemp' => $this->readTempModbusTcp(self::MODBUSTCP_OUTSIDETEMP),
                'waterTemp' => $this->readTempModbusTcp(self::MODBUSTCP_WARMWATER),
                'ppSourceIn' => $this->readTempModbusTcp(self::MODBUSTCP_PPSOURCEIN),
                'ppSourceOut' => $this->readTempModbusTcp(self::MODBUSTCP_PPSOURCEOUT),
                'preTemp' => $this->readTempModbusTcp(self::MODBUSTCP_PRETEMP),
                'backTemp' => $this->readTempModbusTcp(self::MODBUSTCP_BACKTEMP),
                'setDistrTemp' => $setDistrTemp,
                'effDistrTemp' => $this->readTempModbusTcp(self::MODBUSTCP_EFFDISTRTEMP),
                'cpStatus' => $cpStatus,
                'ppMode' => $this->readPpModeModbusTcp(),
                'ppStatus' => $this->readPpStatusModbusTcp(),
                'storTemp' => $this->readTempModbusTcp(self::MODBUSTCP_STORTEMP),
            ];
            $modeData = ['mode' => $this->wemModeToString($this->em->getRepository(Settings::class)->getMode($this->getUsername()))];

            return array_merge($modbusTcpData, $modeData);
        } catch (\Exception $e) {
            return false;
        }
    }

    public function getUsername()
    {
        return $this->username;
    }

    public function executeCommand($type, $command)
    {
        try {
            switch ($type) {
                case 'hc1':
                    $this->setHeatCircle1($command);
                    break;
                case 'hc2':
                    $this->setHeatCircle2($command);
                    break;
                case 'ppPower':
                    $this->setPpPower($command);
                    break;
            }
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    private function wemModeToString($mode)
    {
        switch ($mode) {
            case Settings::MODE_AUTO:
                return 'label.pco.mode.auto';
            case Settings::MODE_MANUAL:
                return 'label.pco.mode.manual';
            case Settings::MODE_HOLIDAY:
                return 'label.pco.mode.holiday';
        }
        return 'undefined';
    }

    /*
     * authenticates with user credentials and reloads data from the device
     */
    private function authenticate()
    {
        // initialize
        $this->status = self::UNAVAILABLE;
        $puppeteer = new Puppeteer;
        $this->browser = $puppeteer->launch([
            'args' => [
                '--disable-gpu',
                '--disable-dev-shm-usage',
                '--disable-setuid-sandbox',
                '--no-sandbox',
            ]
        ]);
        $this->page = $this->browser->newPage();

        // authenticate
        $this->page->goto($this->basePath . 'Login.aspx');


        $this->page->evaluate(
            '(() => {
                    document.querySelector("#ctl00_content_tbxUserName").value = "' . $this->username.'";
                    document.querySelector("#ctl00_content_tbxPassword").value = "' . $this->password.'";
                })()'
        );
        $this->page->click("#ctl00_content_btnLogin");
        $this->page->waitForNavigation();

        $this->status = self::AUTHENTICATED;
    }

    private function getSpecialistDefault()
    {
        $data = [];
        if ($this->status !== self::AUTHENTICATED) {
            $this->authenticate();
        }

        // click "Anlagen" button in top navigation
        $this->page->click("#ctl00_RMTopMenu a.rmLink");
        $this->page->waitForSelector("#ctl00_SubMenuControl1_subMenu");
        // click "Fachmann" in sub navigation
        $this->page->click("#ctl00_SubMenuControl1_subMenu li:nth-of-type(4)>a");
        $this->page->waitForSelector("#ctl00_rdMain_C_controlExtension_rptDisplayContent_ctl02_ctl00_rpbGroupData_i0_rptGroupContent_ctl00_ctl00_lwSimpleData_ctrl15_ctl00_lblValue");

        return $data;
    }

    public function close()
    {
        if ($this->browser !== null) {
            $this->browser->close();
            $this->browser = null;
        }

        $this->status = self::UNAUTHENTICATED;
    }

    /*
     * $value: 0 - 150 in steps of 5; default: 75
     */
    private function setHeatCircle1($value = 75)
    {
        $this->writeBytesFc3ModbusTcp(self::MODBUSTCP_HC1, $value);
    }

    /*
     * $value: 0 - 150 in steps of 5; default: 75
     */
    private function setHeatCircle2($value = 75)
    {
        $this->writeBytesFc3ModbusTcp(self::MODBUSTCP_HC2, $value);
    }

    /*
     * $value: 1 - 100 in steps of 1; default: 100
     */
    private function setPpPower($value = 100)
    {
        if ($this->status === self::UNAVAILABLE) {
            return;
        }
        if ($this->status === self::UNAUTHENTICATED) {
           $this->authenticate();
        }
        sleep(5);
        $this->getSpecialistDefault();
        // go to Wärmepumpe section and readout the current rwndrnd value (ASP.NET protection system)
        $rwndrnd = (explode("=", $this->page->evaluate('document.querySelector(".rwWindowContent > iframe:nth-child(1)").src'))[1]);
        sleep(5);
        $this->page->goto($this->basePath . 'UControls/Weishaupt/DataDisplay/WwpsParameterDetails.aspx?entityvalue=6400180700000000' . $this->toHex($this->getPpLevel()) . '400007AB0300110104&readdata=True&rwndrnd=' . $rwndrnd);
        $this->page->waitForSelector("#ctl00_DialogContent_ddlNewValue");
        $this->page->evaluate(
            '(() => {
                    document.querySelector("#ctl00_DialogContent_ddlNewValue").value = "' . $value . '";
                })()'
        );
        sleep(5);
        $this->page->click("#ctl00_DialogContent_BtnSave");
        $this->storePpLevel($value);
    }

    /*
     * read ppMode via ModbusTCP
     */
    private function readPpModeModbusTcp()
    {
        $ppModes = [
            0 => 'label.pco.ppmode.auto',
            1 => 'label.pco.ppmode.auto',
            2 => 'label.pco.ppmode.cool',
            3 => 'label.pco.ppmode.summer',
            4 => 'label.pco.ppmode.standby',
            5 => 'label.pco.ppmode.2nd',
        ];
        $bytes = $this->readBytesFc3ModbusTcp(self::MODBUSTCP_PPMODE);
        $ppModeInt = Types::parseUInt16(Types::byteArrayToByte($bytes));

        return $ppModes[$ppModeInt];
    }

    /*
     * read ppStatus via ModbusTCP
     */
    private function readPpStatusModbusTcp()
    {
        $ppStatus = $this->readUint16ModbusTcp(self::MODBUSTCP_PPSTATUS);

        return $ppStatus;
    }

    /*
     * read cpStatus via ModbusTCP
     */
    private function readCpStatusModbusTcp()
    {
        $ppModes = [
            0 => 'label.device.status.off',
            1 => 'label.device.status.on',
            2 => 'label.device.status.on',
        ];
        $bytes = $this->readBytesFc3ModbusTcp(self::MODBUSTCP_CPSTATUS);
        $ppModeInt = Types::parseUInt16(Types::byteArrayToByte($bytes));

        $mode = $this->readUint16ModbusTcp(self::MODBUSTCP_MODE);
        if ($mode == 20 || $mode == 21 || $mode == 25) { // 20: Warmwasserbetrieb, 21: Legionellenschutz, 25: Sommerbetrieb; cp is never active
            $ppModeInt = 0;
        }

        return $ppModes[$ppModeInt];
    }

    private function storePpLevel($ppLevel)
    {
        $device = $this->em->getRepository(Settings::class)->findOneByConnectorId($this->getUsername());
        if (!$device) {
            $device = new Settings();
            $device->setConnectorId($this->getUsername());
            $this->em->persist($device);
        }
        $config = $device->getConfig();
        if(!$config) {
            $config = [];
        }
        $config['ppLevel'] = $ppLevel;
        $device->setConfig($config);
        $this->em->flush();
    }

    private function getPpLevel()
    {
        $ppLevel = 100;
        $device = $this->em->getRepository(Settings::class)->findOneByConnectorId($this->getUsername());
        if ($device) {
            $config = $device->getConfig();
            if(is_array($config) && array_key_exists('ppLevel', $config)) {
                $ppLevel = $config['ppLevel'];
            }
        }

        return $ppLevel;
    }
}
