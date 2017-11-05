<?php

namespace AppBundle\Utils\Connectors;

use Doctrine\ORM\EntityManager;

/**
 * Connector to retrieve data from the SmartFox device
 * For information regarding SmartFox refer to www.smartfox.at
 *
 * @author Markus Schafroth
 */
class SmartFoxConnector
{
    protected $em;
    protected $browser;
    protected $basePath;
    protected $ip;

    public function __construct(EntityManager $em, \Buzz\Browser $browser, Array $connectors)
    {
        $this->em = $em;
        $this->browser = $browser;
        $this->ip = $connectors['smartfox']['ip'];
        $this->basePath = 'http://' . $this->ip;
    }

    public function getAllLatest()
    {
        return $this->em->getRepository('AppBundle:SmartFoxDataStore')->getLatest($this->ip);
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
