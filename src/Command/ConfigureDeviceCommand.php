<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use App\Utils\LogicProcessor;

// the name of the command is what users type after "php bin/console"
#[AsCommand(name: 'oshans:devices:configure')]
class ConfigureDeviceCommand extends Command
{

    public function __construct(LogicProcessor $logic)
    {
        $this->logic = $logic;

        parent::__construct();
    }

    protected function configure(): void
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
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->logic->configureDevices();

        return 0;
    }
}
