<?php

namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use App\Utils\LogicProcessor;

/**
 * Retrieves data from connectors and stores it into the database
 *
 */
class DataUpdateCommand extends Command
{
    // the name of the command (the part after "bin/console")
    protected static $defaultName = 'oshans:data:update';

    public function __construct(LogicProcessor $logic)
    {
        $this->logic = $logic;

        parent::__construct();
    }

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
        $this->logic->execute();

        return 0;
    }
}
