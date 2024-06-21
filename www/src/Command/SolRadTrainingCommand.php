<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use App\Service\SolarRadiationToolbox;

// the name of the command is what users type after "php bin/console"
#[AsCommand(name: 'oshans:solrad:training')]
class SolRadTrainingCommand extends Command
{
    private $solrad;

    public function __construct(SolarRadiationToolbox $solrad)
    {
        $this->solrad = $solrad;

        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Train the Solar Radiation Prediction algorithm with available historical data')
        ;
    }

    /**
     * Sends training data to the Clevergy.fi Python API
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return boolean
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // forcibly update the devices by requesting information from the gardena API
        $this->solrad->trainSolarPotentialModel();

        return 0;
    }
}
