<?php

namespace App\Utils\Connectors;

use Doctrine\ORM\EntityManager;

use HeadlessChromium\BrowserFactory;
use Nesk\Puphpeteer\Puppeteer;
use App\Entity\Settings;

/**
 * Connector to retrieve data from the WEM Portal (Weishaupt Energy Manager)
 * Note: requires the following prerequisites installed on the system. Ubuntu: <code>composer require nesk/puphpeteer; npm install @nesk/puphpeteer</code>
 *
 * @author Markus Schafroth
 */
class WemConnector
{
    protected $em;
    private $basePath;
    private $browser;
    private $page;
    private $username;
    private $password;

    public function __construct(EntityManager $em, Array $connectors)
    {
        $this->em = $em;
        if (array_key_exists('wem', $connectors)) {
            $this->basePath = "https://www.wemportal.com/Web/";
            $this->username = $connectors['wem']['username'];
            $this->password = $connectors['wem']['password'];
        }
    }

    public function getAllLatest()
    {
        return $this->em->getRepository('App:WemDataStore')->getLatest($this->username);
    }

    public function getAll()
    {
        // get analog, digital and integer values
        try {
            $defaultData = $this->getDefault();
            $systemData = $this->getSystemMode();
            $specialistDefaultData = $this->getSpecialistDefault();
            $modeData = ['mode' => $this->wemModeToString($this->em->getRepository('App:Settings')->getMode($this->getUsername()))];
            $this->browser->close();
            $this->browser = null;
            return array_merge($defaultData, $systemData, $specialistDefaultData, $modeData);
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
                case 'hc1hysteresis':
                    $this->setHeatCircle1Hyseteresis($command);
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

    private function statusToString($status)
    {
        if ($status === 'Ein') {
            return 'label.device.status.on';
        } else {
            return 'label.device.status.off';
        }
    }

    private function ppModeToString($mode)
    {
        switch ($mode) {
            case 'Sommer':
                return 'label.pco.ppmode.summer';
            case 'Heizen':
                return 'label.pco.ppmode.auto';
            case 'Standby':
                return 'label.pco.ppmode.holiday';
            case '2. WEZ':
                return 'label.pco.ppmode.2nd';
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
        $puppeteer = new Puppeteer;
        $this->browser = $puppeteer->launch();
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

        // click first navigation button (info)
        $this->page->click("#ctl00_rdMain_C_controlExtension_iconMenu_rmMenuLayer a")[0];

        // click update button
        $this->page->waitForSelector("#ctl00_DeviceContextControl1_RefreshDeviceDataButton");
        $this->page->click("#ctl00_DeviceContextControl1_RefreshDeviceDataButton");
    }

    private function getDefault()
    {
        if ($this->browser === null) {
           $this->authenticate();
        }
        $this->page->goto($this->basePath . 'Default.aspx');
        $this->page->waitForSelector("#ctl00_rdMain_C_controlExtension_iconMenu_rmMenuLayer");
        $data = [];
        $data['waterTemp'] = explode(' ', $this->page->evaluate('document.querySelector("#ctl00_rdMain_C_controlExtension_rptDisplayContent_ctl02_ctl00_rpbGroupData_i0_rptGroupContent_ctl00_ctl00_lwSimpleData_ctrl0_ctl00_lblValue").innerHTML'))[0];
        $data['storTemp'] = explode(' ', $this->page->evaluate('document.querySelector("#ctl00_rdMain_C_controlExtension_rptDisplayContent_ctl02_ctl00_rpbGroupData_i0_rptGroupContent_ctl00_ctl00_lwSimpleData_ctrl3_ctl00_lblValue").innerHTML'))[0];
        $data['outsideTemp'] = explode(' ', $this->page->evaluate('document.querySelector("#ctl00_rdMain_C_controlExtension_rptDisplayContent_ctl01_ctl00_rpbGroupData_i0_rptGroupContent_ctl00_ctl00_lwSimpleData_ctrl0_ctl00_lblValue").innerHTML'))[0];
        $data['setDistrTemp'] = explode(' ', $this->page->evaluate('document.querySelector("#ctl00_rdMain_C_controlExtension_rptDisplayContent_ctl01_ctl00_rpbGroupData_i0_rptGroupContent_ctl00_ctl00_lwSimpleData_ctrl4_ctl00_lblValue").innerHTML'))[0];
        $data['effDistrTemp'] = explode(' ', $this->page->evaluate('document.querySelector("#ctl00_rdMain_C_controlExtension_rptDisplayContent_ctl01_ctl00_rpbGroupData_i0_rptGroupContent_ctl00_ctl00_lwSimpleData_ctrl2_ctl00_lblValue").innerHTML'))[0];
        $data['preTemp'] = explode(' ', $this->page->evaluate('document.querySelector("#ctl00_rdMain_C_controlExtension_rptDisplayContent_ctl00_ctl00_rpbGroupData_i0_rptGroupContent_ctl00_ctl00_lwSimpleData_ctrl2_ctl00_lblValue").innerHTML'))[0];
        $data['backTemp'] = explode(' ', $this->page->evaluate('document.querySelector("#ctl00_rdMain_C_controlExtension_rptDisplayContent_ctl02_ctl00_rpbGroupData_i0_rptGroupContent_ctl00_ctl00_lwSimpleData_ctrl2_ctl00_lblValue").innerHTML'))[0];
        $data['cpStatus'] = $this->statusToString(explode(' ', $this->page->evaluate('document.querySelector("#ctl00_rdMain_C_controlExtension_rptDisplayContent_ctl01_ctl00_rpbGroupData_i0_rptGroupContent_ctl00_ctl00_lwSimpleData_ctrl3_ctl00_lblValue").innerHTML'))[0]);
        $data['ppStatus'] = $this->page->evaluate('document.querySelector("#ctl00_rdMain_C_controlExtension_rptDisplayContent_ctl02_ctl00_rpbGroupData_i0_rptGroupContent_ctl00_ctl00_lwSimpleData_ctrl1_ctl00_lblValue").innerHTML');

        return $data;
    }

    private function getSystemMode()
    {
        if ($this->page === null) {
           $this->authenticate();
        }
        $data = [];
        $this->page->goto($this->basePath . 'Default.aspx');
        // click second navigation button (Betriebsart)
        $this->page->waitForSelector("#ctl00_rdMain_C_controlExtension_iconMenu_rmMenuLayer");
        $this->page->click("#ctl00_rdMain_C_controlExtension_iconMenu_rmMenuLayer a:not(.rmSelected)");
        $this->page->waitForSelector("#ctl00_rdMain_C_controlExtension_rptDisplayContent_ctl00_ctl00_rpbGroupData_i0_rptGroupContent_ctl00_ctl00_lwSimpleData_ctrl0_ctl00_imgbtnEdit");
        $data['ppMode'] = $this->ppModeToString($this->page->evaluate('document.querySelector("#ctl00_rdMain_C_controlExtension_rptDisplayContent_ctl00_ctl00_rpbGroupData_i0_rptGroupContent_ctl00_ctl00_lwSimpleData_ctrl0_ctl00_lblValue").innerHTML'));

        return $data;
    }

    private function getSpecialistDefault()
    {
        if ($this->page === null) {
           $this->authenticate();
        }
        $data = [];
        $this->page->goto($this->basePath . 'Default.aspx');
        // click "Anlagen" button in top navigation
        $this->page->click("#ctl00_RMTopMenu a.rmLink");
        $this->page->waitForSelector("#ctl00_SubMenuControl1_subMenu");
        // click "Fachmann" in sub navigation
        $this->page->click("#ctl00_SubMenuControl1_subMenu li:nth-of-type(4)>a");
        $this->page->waitForSelector("#ctl00_rdMain_C_controlExtension_rptDisplayContent_ctl02_ctl00_rpbGroupData_i0_rptGroupContent_ctl00_ctl00_lwSimpleData_ctrl15_ctl00_lblValue");
        $data['ppSourceIn'] = explode(' ', $this->page->evaluate('document.querySelector("#ctl00_rdMain_C_controlExtension_rptDisplayContent_ctl02_ctl00_rpbGroupData_i0_rptGroupContent_ctl00_ctl00_lwSimpleData_ctrl14_ctl00_lblValue").innerHTML'))[0];
        $data['ppSourceOut'] = explode(' ', $this->page->evaluate('document.querySelector("#ctl00_rdMain_C_controlExtension_rptDisplayContent_ctl02_ctl00_rpbGroupData_i0_rptGroupContent_ctl00_ctl00_lwSimpleData_ctrl22_ctl00_lblValue").innerHTML'))[0];

        return $data;
    }

    /*
     * $value: 0 - 150 in steps of 5; default: 75
     */
    private function setHeatCircle1($value = 75)
    {
        if ($this->page === null) {
           $this->authenticate();
        }
        $this->getDefault();
        $this->page->goto($this->basePath . 'UControls/Weishaupt/DataDisplay/WwpsParameterDetails.aspx?entityvalue=32001A00000000000080004CFC0200110004&readdata=False');
        $this->page->waitForSelector("#ctl00_DialogContent_ddlNewValue");
        $this->page->evaluate(
            '(() => {
                    document.querySelector("#ctl00_DialogContent_ddlNewValue").value = "' . $value . '";
                })()'
        );
        $this->page->click("#ctl00_DialogContent_BtnSave");
    }

    /*
     * $value: value in Kelvin
     */
    private function setHeatCircle1Hyseteresis($value = 3)
    {
        $value = 10 * $value;
        if ($this->page === null) {
           $this->authenticate();
        }
        $this->getDefault();
        $this->page->goto($this->basePath . 'UControls/Weishaupt/DataDisplay/WwpsParameterDetails.aspx?entityvalue=640012030000000032400074240300110104&readdata=True');
        $this->page->waitForSelector("#ctl00_DialogContent_ddlNewValue");
        $this->page->evaluate(
            '(() => {
                    document.querySelector("#ctl00_DialogContent_ddlNewValue").value = "' . $value . '";
                })()'
        );
        $this->page->click("#ctl00_DialogContent_BtnSave");
    }

    /*
     * $value: 0 - 150 in steps of 5; default: 75
     */
    private function setHeatCircle2($value = 75)
    {
        if ($this->page === null) {
           $this->authenticate();
        }
        $this->getDefault();
        $this->page->goto($this->basePath . 'UControls/Weishaupt/DataDisplay/WwpsParameterDetails.aspx?entityvalue=33002200000000000080004CFC0200110004&readdata=False');
        $this->page->waitForSelector("#ctl00_DialogContent_ddlNewValue");
        $this->page->evaluate(
            '(() => {
                    document.querySelector("#ctl00_DialogContent_ddlNewValue").value = "' . $value . '";
                })()'
        );
        $this->page->click("#ctl00_DialogContent_BtnSave");
    }

    /*
     * $value: 1 - 100 in steps of 1; default: 100
     */
    private function setPpPower($value = 100)
    {
        if ($this->page === null) {
           $this->authenticate();
        }
        $this->getDefault();
        $this->page->goto($this->basePath . 'UControls/Weishaupt/DataDisplay/WwpsParameterDetails.aspx?entityvalue=64001205000000001D400074240300110104&readdata=True');
        $this->page->waitForSelector("#ctl00_DialogContent_ddlNewValue");
        $this->page->evaluate(
            '(() => {
                    document.querySelector("#ctl00_DialogContent_ddlNewValue").value = "' . $value . '";
                })()'
        );
        $this->page->click("#ctl00_DialogContent_BtnSave");
    }
}
