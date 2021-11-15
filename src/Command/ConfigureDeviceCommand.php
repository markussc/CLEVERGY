<?php

namespace App\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use App\Utils\LogicProcessor;

class ConfigureDeviceCommand extends ContainerAwareCommand
{
    // the name of the command (the part after "bin/console")
    protected static $defaultName = 'oshans:devices:configure';

    protected function configure()
    {
        $this
            ->setDescription('Sends configuration data to all configurable devices');
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
        $this->getContainer()->get(LogicProcessor::class)->configureDevices();
    }
}
