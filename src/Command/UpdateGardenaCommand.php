<?php

namespace App\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use App\Utils\Connectors\GardenaConnector;

class UpdateGardenaCommand extends ContainerAwareCommand
{
    // the name of the command (the part after "bin/console")
    protected static $defaultName = 'oshans:gardena:update';

    protected function configure()
    {
        $this
            ->setDescription('Get list of gardena devices from the user account')
        ;
    }

    /**
     * Sends configuration data to a device
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return boolean
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->getContainer()->get(GardenaConnector::class)->updateDevices();
    }
}
