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
        $latest = $this->em->getRepository('AppBundle:SmartFoxDataStore')->getLatest($this->ip);
        if ($latest && count($latest)) {
            $latest['energyToday'] = $this->em->getRepository('AppBundle:SmartFoxDataStore')->getEnergyToday($this->ip);
        }

        return $latest;
    }

    public function getAll($calculatedData = false)
    {
        $responseJson = $this->browser->get($this->basePath . '/all')->getContent();
        $responseArr = json_decode($responseJson, true);

        // if requested, add calculated data
        if ($calculatedData) {
            $responseArr['energyToday'] = $this->em->getRepository('AppBundle:SmartFoxDataStore')->getEnergyToday($this->ip);
        }

        return $responseArr;
    }

    public function getIp()
    {
        return $this->ip;
    }
}
