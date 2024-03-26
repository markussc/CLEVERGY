<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use App\Utils\Connectors\GardenaConnector;

// the name of the command is what users type after "php bin/console"
#[AsCommand(name: 'oshans:gardena:update')]
class UpdateGardenaCommand extends Command
{
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
