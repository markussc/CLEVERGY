<?php

namespace AppBundle\Command;

use AppBundle\Entity\EdiMaxDataStore;
use AppBundle\Entity\SmartFoxDataStore;
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

        // write to database
        $em->flush();
    }
}
