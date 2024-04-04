<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use App\Utils\LogicProcessor;

/**
 * Retrieves data from connectors and stores it into the database
 *
 */
// the name of the command is what users type after "php bin/console"
#[AsCommand(name: 'oshans:data:update')]
class DataUpdateCommand extends Command
{
    public function __construct(LogicProcessor $logic)
    {
        $this->logic = $logic;

        parent::__construct();
    }

    protected function configure(): void
    {
        $this
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
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->logic->execute();

        return 0;
    }
}
