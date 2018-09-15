<?php

namespace AppBundle\Utils\Connectors;

use Doctrine\ORM\EntityManager;
use AppBundle\Entity\Settings;

/**
 * Connector to retrieve data from the PCO Web device
 * For information refer to www.careluk.com
 *
 * @author Markus Schafroth
 */
class PcoWebConnector
{
    const MODE_SUMMER = 0;
    const MODE_AUTO = 1;
    const MODE_HOLIDAY = 2;
    const MODE_PARTY = 3;
    const MODE_2ND = 4;
    protected $em;
    protected $browser;
    protected $basePath;
    protected $ip;

    public function __construct(EntityManager $em, \Buzz\Browser $browser, Array $connectors)
    {
        $this->em = $em;
        $this->browser = $browser;
        $this->ip = $connectors['pcoweb']['ip'];
        $this->basePath = 'http://' . $this->ip;
    }

    public function getAllLatest()
    {
        return $this->em->getRepository('AppBundle:PcoWebDataStore')->getLatest($this->ip);
    }

    public function getAll()
    {
        // get analog, digital and integer values
        $responseXml = $this->browser->get($this->basePath . '/usr-cgi/xml.cgi?|A|1|127|D|1|127|I|1|127')->getContent();
        $ob = simplexml_load_string($responseXml);
        $json  = json_encode($ob);
        $responseArr = json_decode($json, true);

        return [
            'mode' => $this->pcowebModeToString($this->em->getRepository('AppBundle:Settings')->getMode($this->getIp())),
            'outsideTemp' => $responseArr['PCO']['ANALOG']['VARIABLE'][0]['VALUE'],
            'waterTemp' => $responseArr['PCO']['ANALOG']['VARIABLE'][2]['VALUE'],
            'setDistrTemp' => $responseArr['PCO']['ANALOG']['VARIABLE'][53]['VALUE'],
            'effDistrTemp' => $responseArr['PCO']['ANALOG']['VARIABLE'][8]['VALUE'],
            'cpStatus' => $this->statusToString($responseArr['PCO']['DIGITAL']['VARIABLE'][50]['VALUE']),
            'ppStatus' => $this->statusToString($responseArr['PCO']['DIGITAL']['VARIABLE'][42]['VALUE']),
            'ppStatusMsg' => $this->ppStatusMsgToString($responseArr['PCO']['ANALOG']['VARIABLE'][102]['VALUE']*10),
            'ppMode' => $this->ppModeToString($responseArr['PCO']['INTEGER']['VARIABLE'][13]['VALUE']),
            'preTemp' => $responseArr['PCO']['ANALOG']['VARIABLE'][4]['VALUE'],
            'backTemp' => $responseArr['PCO']['ANALOG']['VARIABLE'][1]['VALUE'],
        ];
    }

    public function getIp()
    {
        return $this->ip;
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
        }
        
    }

    private function setMode($mode)
    {
        // set mode
        $data['?script:var(0,3,14,0,4)'] = $mode;
 
        $headers = [
            'Content-Type' => 'application/x-www-form-urlencoded;',
        ];

        // post request
        $response = $this->browser->post($this->basePath . '/http/index/j_modus.html', $headers, http_build_query($data))->getContent();
    }

    private function setHotWaterHysteresis($value)
    {
        // set mode
        $data['?script:var(0,3,44,2,15)'] = $value;
 
        $headers = [
            'Content-Type' => 'application/x-www-form-urlencoded;',
        ];

        // post request
        $response = $this->browser->post($this->basePath . '/http/index/j_settings_hotwater.html', $headers, http_build_query($data))->getContent();
    }

    private function setHeatCircle1($value)
    {
        $response = $this->browser->get($this->basePath . '/usr-cgi/query.cgi?var|I|35|' . $value);
    }

    private function ppModeToString($mode)
    {
        switch ($mode) {
            case self::MODE_SUMMER:
                return 'label.pco.ppmode.summer';
            case self::MODE_AUTO:
                return 'label.pco.ppmode.auto';
            case self::MODE_HOLIDAY:
                return 'label.pco.ppmode.holiday';
            case self::MODE_PARTY:
                return 'label.pco.ppmode.party';
            case self::MODE_2ND:
                return 'label.pco.ppmode.2nd';
        }
        return 'undefined';
    }

    private function pcowebModeToString($mode)
    {
        switch ($mode) {
            case Settings::MODE_AUTO:
                return 'label.pco.mode.auto';
            case Settings::MODE_MANUAL:
                return 'label.pco.mode.manual';
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
        $oldStatus = $this->em->getRepository('AppBundle:PcoWebDataStore')->getLatestNotStatus($this->getIp(), $currentStatus);

        if (count($oldStatus) == 1) {
            $oldTimestamp = $oldStatus[0]->getTimestamp();

            // calculate time diff
            $now = new \DateTime('now');
            $diff = ($now->getTimestamp() - $oldTimestamp->getTimestamp())/60; // diff in minutes
            if ($diff > 10) {
                return true;
            }
        }

        return false;
    }
}
