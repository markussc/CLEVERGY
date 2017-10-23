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
        $responseXml = $this->browser->get($this->basePath . '/usr-cgi/xml.cgi?|A|1|127')->getContent();
        $ob= simplexml_load_string($responseXml);
        $json  = json_encode($ob);
        $responseArr = json_decode($json, true);

        // find and return the outside temperature
        return [
            'outsideTemp' => $responseArr['PCO']['ANALOG']['VARIABLE'][0]['VALUE'],
            'waterTemp' => $responseArr['PCO']['ANALOG']['VARIABLE'][2]['VALUE'],
        ];
    }
}
