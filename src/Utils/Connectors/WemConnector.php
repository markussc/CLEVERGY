<?php

namespace App\Utils\Connectors;

use Doctrine\ORM\EntityManager;

use HeadlessChromium\BrowserFactory;

/**
 * Connector to retrieve data from the WEM Portal (Weishaupt Energy Manager)
 * Note: requires chromium browser installed on the system. Ubuntu: sudo apt install chromium-browser; sudo snap install chromium
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
            return array_merge($defaultData); // prepared for further separate queries to detail pages
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

    /*
     * authenticates with user credentials and reloads data from the device
     */
    private function authenticate()
    {
        // initialize
        $browserFactory = new BrowserFactory('chromium-browser');
        $this->page = $browserFactory->createBrowser(['noSandbox' => true])->createPage();

        // authenticate
        $this->page->navigate($this->basePath . 'Login.aspx')->waitForNavigation();
        $this->page->evaluate(
            '(() => {
                    document.querySelector("#ctl00_content_tbxUserName").value = "' . $this->username.'";
                    document.querySelector("#ctl00_content_tbxPassword").value = "' . $this->password.'";
                    document.querySelector("#ctl00_content_btnLogin").click();
                })()'
        )->waitForPageReload();

        // click first navigation button (info)
        $this->page->evaluate('document.querySelector("#ctl00_rdMain_C_controlExtension_iconMenu_rmMenuLayer a").click();');

        // click update button
        $this->page->evaluate('document.querySelector("#ctl00_DeviceContextControl1_RefreshDeviceDataButton").click();');
    }

    private function getDefault()
    {
        if ($this->page === null) {
           $this->authenticate();
        }
        $data = [];
        $data['waterTemp'] = explode(' ', $this->page->evaluate('document.querySelector("#ctl00_rdMain_C_controlExtension_rptDisplayContent_ctl02_ctl00_rpbGroupData_i0_rptGroupContent_ctl00_ctl00_lwSimpleData_ctrl0_ctl00_lblValue").innerHTML')->getReturnValue())[0];
        $data['storTemp'] = explode(' ', $this->page->evaluate('document.querySelector("#ctl00_rdMain_C_controlExtension_rptDisplayContent_ctl02_ctl00_rpbGroupData_i0_rptGroupContent_ctl00_ctl00_lwSimpleData_ctrl3_ctl00_lblValue").innerHTML')->getReturnValue())[0];
        $data['outsideTemp'] = explode(' ', $this->page->evaluate('document.querySelector("#ctl00_rdMain_C_controlExtension_rptDisplayContent_ctl01_ctl00_rpbGroupData_i0_rptGroupContent_ctl00_ctl00_lwSimpleData_ctrl0_ctl00_lblValue").innerHTML')->getReturnValue())[0];
        $data['setDistrTemp'] = explode(' ', $this->page->evaluate('document.querySelector("#ctl00_rdMain_C_controlExtension_rptDisplayContent_ctl01_ctl00_rpbGroupData_i0_rptGroupContent_ctl00_ctl00_lwSimpleData_ctrl4_ctl00_lblValue").innerHTML')->getReturnValue())[0];
        $data['effDistrTemp'] = explode(' ', $this->page->evaluate('document.querySelector("#ctl00_rdMain_C_controlExtension_rptDisplayContent_ctl01_ctl00_rpbGroupData_i0_rptGroupContent_ctl00_ctl00_lwSimpleData_ctrl2_ctl00_lblValue").innerHTML')->getReturnValue())[0];
        $data['preTemp'] = explode(' ', $this->page->evaluate('document.querySelector("#ctl00_rdMain_C_controlExtension_rptDisplayContent_ctl00_ctl00_rpbGroupData_i0_rptGroupContent_ctl00_ctl00_lwSimpleData_ctrl2_ctl00_lblValue").innerHTML')->getReturnValue())[0];
        $data['backTemp'] = explode(' ', $this->page->evaluate('document.querySelector("#ctl00_rdMain_C_controlExtension_rptDisplayContent_ctl02_ctl00_rpbGroupData_i0_rptGroupContent_ctl00_ctl00_lwSimpleData_ctrl2_ctl00_lblValue").innerHTML')->getReturnValue())[0];
        $data['cpStatus'] = $this->statusToString(explode(' ', $this->page->evaluate('document.querySelector("#ctl00_rdMain_C_controlExtension_rptDisplayContent_ctl01_ctl00_rpbGroupData_i0_rptGroupContent_ctl00_ctl00_lwSimpleData_ctrl3_ctl00_lblValue").innerHTML')->getReturnValue())[0]);
        $data['ppStatus'] = $this->page->evaluate('document.querySelector("#ctl00_rdMain_C_controlExtension_rptDisplayContent_ctl02_ctl00_rpbGroupData_i0_rptGroupContent_ctl00_ctl00_lwSimpleData_ctrl1_ctl00_lblValue").innerHTML')->getReturnValue();

        return $data;
    }
}
