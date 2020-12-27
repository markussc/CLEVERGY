<?php

namespace App\Utils\Connectors;

use Doctrine\ORM\EntityManager;

use HeadlessChromium\BrowserFactory;
use Nesk\Puphpeteer\Puppeteer;

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
    private $page;
    private $username;
    private $password;

    public function __construct(EntityManager $em, Array $connectors)
    {
        if (array_key_exists('wem', $connectors)) {
            $this->em = $em;
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
            return array_merge($defaultData, $systemData); // prepared for further separate queries to detail pages
        } catch (\Exception $e) {
          return false;
        }
    }

    public function getUsername()
    {
        return $this->username;
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

    /*
     * authenticates with user credentials and reloads data from the device
     */
    private function authenticate()
    {
        // initialize
        $puppeteer = new Puppeteer;
        $browser = $puppeteer->launch();
        $this->page = $browser->newPage();

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
        if ($this->page === null) {
           $this->authenticate();
        }
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
        // click second navigation button (Betriebsart)
        $this->page->waitForSelector("#ctl00_rdMain_C_controlExtension_iconMenu_rmMenuLayer");
        $this->page->click("#ctl00_rdMain_C_controlExtension_iconMenu_rmMenuLayer a:not(.rmSelected)");
        $this->page->waitForSelector("#ctl00_rdMain_C_controlExtension_rptDisplayContent_ctl00_ctl00_rpbGroupData_i0_rptGroupContent_ctl00_ctl00_lwSimpleData_ctrl0_ctl00_imgbtnEdit");
        $data['ppMode'] = $this->ppModeToString($this->page->evaluate('document.querySelector("#ctl00_rdMain_C_controlExtension_rptDisplayContent_ctl00_ctl00_rpbGroupData_i0_rptGroupContent_ctl00_ctl00_lwSimpleData_ctrl0_ctl00_lblValue").innerHTML'));

        return $data;
    }
}
