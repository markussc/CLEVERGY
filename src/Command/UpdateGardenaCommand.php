<?php

namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use App\Utils\Connectors\GardenaConnector;

class UpdateGardenaCommand extends Command
{
    // the name of the command (the part after "bin/console")
    protected static $defaultName = 'oshans:gardena:update';

    public function __construct(GardenaConnector $gardena)
    {
        $this->gardena = $gardena;

        parent::__construct();
    }

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
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // forcibly update the devices by requesting information from the gardena API
        $this->gardena->updateDevices(true);

        return 0;
    }
}
