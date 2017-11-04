<?php

namespace AppBundle\Utils\Connectors;

/**
 * Connector to retrieve data from the SmartFox device
 * For information regarding SmartFox refer to www.smartfox.at
 *
 * @author Markus Schafroth
 */
class SmartFoxConnector
{
    protected $browser;
    protected $basePath;
    protected $ip;

    public function __construct(\Buzz\Browser $browser, Array $connectors)
    {
        $this->browser = $browser;
        $this->ip = $connectors['smartfox']['ip'];
        $this->basePath = 'http://' . $this->ip;
    }
    public function getPower()
    {
        $responseJson = $this->browser->get($this->basePath . '/power')->getContent();
        $responseArr = json_decode($responseJson, true);

        return $responseArr;
    }

    public function getAll()
    {
        $responseJson = $this->browser->get($this->basePath . '/all')->getContent();
        $responseArr = json_decode($responseJson, true);

        return $responseArr;
    }

    public function getIp()
    {
        return $this->ip;
    }
}
