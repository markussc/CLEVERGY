<?php

namespace AppBundle\Utils\Connectors;

use Doctrine\ORM\EntityManager;

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
            'outsideTemp' => $responseArr['PCO']['ANALOG']['VARIABLE'][0]['VALUE'],
            'waterTemp' => $responseArr['PCO']['ANALOG']['VARIABLE'][2]['VALUE'],
            'setDistrTemp' => $responseArr['PCO']['ANALOG']['VARIABLE'][53]['VALUE'],
            'effDistrTemp' => $responseArr['PCO']['ANALOG']['VARIABLE'][8]['VALUE'],
            'cpStatus' => $this->statusToString($responseArr['PCO']['DIGITAL']['VARIABLE'][50]['VALUE']),
            'ppStatus' => $this->statusToString($responseArr['PCO']['DIGITAL']['VARIABLE'][42]['VALUE']),
            'ppMode' => $this->ppModeToString($responseArr['PCO']['INTEGER']['VARIABLE'][13]['VALUE']),
            'preTemp' => $responseArr['PCO']['ANALOG']['VARIABLE'][4]['VALUE'],
            'backTemp' => $responseArr['PCO']['ANALOG']['VARIABLE'][1]['VALUE'],
        ];
    }

    public function getIp()
    {
        return $this->ip;
    }

    public function setMode($mode)
    {
        // set mode
        $data['?script:var(0,3,14,0,4)'] = $mode;
 
        $headers = [
            'Content-Type' => 'application/x-www-form-urlencoded;',
        ];

        // post request
        $response = $this->browser->post($this->basePath . '/http/index/j_modus.html', $headers, http_build_query($data))->getContent();
    }

    private function ppModeToString($mode)
    {
        switch ($mode) {
            case self::MODE_SUMMER:
                return 'label.pco.ppmode.summer';
            case self::MODE_HOLIDAY:
                return 'label.pco.ppmode.holiday';
            case self::MODE_PARTY:
                return 'label.pco.ppmode.party';
            case self::MODE_2ND:
                return 'label.pco.ppmode.2nd';
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
}
