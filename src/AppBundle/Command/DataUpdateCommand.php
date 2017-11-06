<?php

namespace AppBundle\Command;

use AppBundle\Entity\EdiMaxDataStore;
use AppBundle\Entity\PcoWebDataStore;
use AppBundle\Entity\SmartFoxDataStore;
use AppBundle\Entity\MobileAlertsDataStore;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Retrieves data from connectors and stores it into the database
 *
 */
class DataUpdateCommand extends ContainerAwareCommand
{
    private $output; // OutputInterface

    protected function configure()
    {
        $this
            ->setName('oshans:data:update')
            ->setDescription('Retrieve data from connectors and store in database')
        ;
    }

    /**
     * Updates data from connectors and stores in database
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return boolean
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $em = $this->getContainer()->get('doctrine.orm.entity_manager');

        // edimax
        foreach ($this->getContainer()->get('AppBundle\Utils\Connectors\EdiMaxConnector')->getAll() as $edimax) {
            $edimaxEntity = new EdiMaxDataStore();
            $edimaxEntity->setTimestamp(new \DateTime('now'));
            $edimaxEntity->setConnectorId($edimax['ip']);
            $edimaxEntity->setData($edimax['status']['val']);
            $em->persist($edimaxEntity);
        }

        // smartfox
        $smartfox = $this->getContainer()->get('AppBundle\Utils\Connectors\SmartFoxConnector')->getAll();
        $smartfoxEntity = new SmartFoxDataStore();
        $smartfoxEntity->setTimestamp(new \DateTime('now'));
        $smartfoxEntity->setConnectorId($this->getContainer()->get('AppBundle\Utils\Connectors\SmartFoxConnector')->getIp());
        $smartfoxEntity->setData($smartfox);
        $em->persist($smartfoxEntity);

        // pcoweb
        $pcoweb = $this->getContainer()->get('AppBundle\Utils\Connectors\PcoWebConnector')->getAll();
        $pcowebEntity = new PcoWebDataStore();
        $pcowebEntity->setTimestamp(new \DateTime('now'));
        $pcowebEntity->setConnectorId($this->getContainer()->get('AppBundle\Utils\Connectors\PcoWebConnector')->getIp());
        $pcowebEntity->setData($pcoweb);
        $em->persist($pcowebEntity);

        // mobilealerts
        foreach ($this->getContainer()->get('AppBundle\Utils\Connectors\MobileAlertsConnector')->getAll() as $sensorId => $sensorData) {
            $mobilealertsEntity = new MobileAlertsDataStore();
            $mobilealertsEntity->setTimestamp(new \DateTime('now'));
            $mobilealertsEntity->setConnectorId($sensorId);
            $mobilealertsEntity->setData($sensorData);
            $em->persist($mobilealertsEntity);
        }

        // write to database
        $em->flush();

        // execute auto actions
        $this->autoActions();
    }

    /**
     * Based on the available values in the DB, decide whether any commands should be sent to attached devices
     */
    private function autoActions()
    {
        $em = $this->getContainer()->get('doctrine.orm.entity_manager');
        $avgPower = $em->getRepository('AppBundle:SmartFoxDataStore')->getNetPowerAverage($this->getContainer()->get('AppBundle\Utils\Connectors\SmartFoxConnector')->getIp(), 10);

        // get current net_power
        $smartfox = $this->getContainer()->get('AppBundle\Utils\Connectors\SmartFoxConnector')->getAllLatest();
        $netPower = $smartfox['power_io'];

        if ($netPower > 0) {
            if ($avgPower > 0) {
                // if current net_power positive and average over last 10 minutes positive as well: turn off the first found device
                foreach ($this->getContainer()->get('AppBundle\Utils\Connectors\EdiMaxConnector')->getAllLatest() as $deviceId => $edimax) {
                    // if a "forceOn" condition is set, check it (if true, try to turn it on and skip)
                    if ($this->forceOn($deviceId, $edimax)) {
                        continue;
                    }
                    // check if the device is on and allowed to be turned off
                    if ($edimax['status']['val'] && $this->getContainer()->get('AppBundle\Utils\Connectors\EdiMaxConnector')->switchOK($deviceId)) {
                        $this->getContainer()->get('AppBundle\Utils\Connectors\EdiMaxConnector')->executeCommand($deviceId, 0);
                        break;
                    }
                }
            }
        } else {
            // if curren net_power negative and average over last 10 minutes negative: turn on a device if its power consumption is less than the negative value (current and average)
            foreach ($this->getContainer()->get('AppBundle\Utils\Connectors\EdiMaxConnector')->getAllLatest() as $deviceId => $edimax) {
                // if a "forceOn" condition is set, check it (if true, try to turn it on and skip)
                if ($this->forceOn($deviceId, $edimax)) {
                    continue;
                }
                // check if the device is off, compare the required power with the current and average power over the last 10 minutes and check if the device is allowed to be turned on
                if (!$edimax['status']['val'] && $edimax['nominalPower'] < -1*$netPower && $edimax['nominalPower'] < -1*$avgPower && $this->getContainer()->get('AppBundle\Utils\Connectors\EdiMaxConnector')->switchOK($deviceId)) {
                    $this->getContainer()->get('AppBundle\Utils\Connectors\EdiMaxConnector')->executeCommand($deviceId, 1);
                    break;
                }
            }
        }
    }

    private function forceOn($deviceId, $edimax)
    {
        $forceOn = $this->getContainer()->get('AppBundle\Utils\ConditionChecker')->checkCondition($edimax);
        if ($forceOn && !$edimax['status']['val'] && $this->getContainer()->get('AppBundle\Utils\Connectors\EdiMaxConnector')->switchOK($deviceId)) {
            // force turn it on if we are allowed to
            $this->getContainer()->get('AppBundle\Utils\Connectors\EdiMaxConnector')->executeCommand($deviceId, 1);
            return true;
        } else {
            return false;
        }
    }
}
