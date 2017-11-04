<?php

namespace AppBundle\Utils\Connectors;

/**
 * Connector to retrieve data from the PCO Web device
 * For information refer to www.careluk.com
 *
 * @author Markus Schafroth
 */
class PcoWebConnector
{
    protected $browser;
    protected $basePath;

    public function __construct(\Buzz\Browser $browser, Array $connectors)
    {
        $this->browser = $browser;
        $this->basePath = 'http://' . $connectors['pcoweb']['ip'];
    }

    public function getAll()
    {
        // get analog values
        $responseXmlAnalog = $this->browser->get($this->basePath . '/usr-cgi/xml.cgi?|A|1|127')->getContent();
        $obAnalog = simplexml_load_string($responseXmlAnalog);
        $jsonAnalog  = json_encode($obAnalog);
        $responseArrAnalog = json_decode($jsonAnalog, true);

        // get digital values
        $responseXmlDigital = $this->browser->get($this->basePath . '/usr-cgi/xml.cgi?|D|1|127')->getContent();
        $obDigital = simplexml_load_string($responseXmlDigital);
        $jsonDigital  = json_encode($obDigital);
        $responseArrDigital = json_decode($jsonDigital, true);

        if ($responseArrDigital['PCO']['DIGITAL']['VARIABLE'][50]['VALUE']) {
            $cpStatus = 'label.device.status.on';
        } else {
            $cpStatus = 'label.device.status.off';
        }
        if ($responseArrDigital['PCO']['DIGITAL']['VARIABLE'][42]['VALUE']) {
            $ppStatus = 'label.device.status.on';
        } else {
            $ppStatus = 'label.device.status.off';
        }

        return [
            'outsideTemp' => $responseArrAnalog['PCO']['ANALOG']['VARIABLE'][0]['VALUE'],
            'waterTemp' => $responseArrAnalog['PCO']['ANALOG']['VARIABLE'][2]['VALUE'],
            'setDistrTemp' => $responseArrAnalog['PCO']['ANALOG']['VARIABLE'][53]['VALUE'],
            'effDistrTemp' => $responseArrAnalog['PCO']['ANALOG']['VARIABLE'][8]['VALUE'],
            'cpStatus' => $cpStatus,
            'ppStatus' => $ppStatus,
            'preTemp' => $responseArrAnalog['PCO']['ANALOG']['VARIABLE'][4]['VALUE'],
            'backTemp' => $responseArrAnalog['PCO']['ANALOG']['VARIABLE'][5]['VALUE'],
        ];
    }
}
