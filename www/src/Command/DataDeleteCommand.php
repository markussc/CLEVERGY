<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

// the name of the command is what users type after "php bin/console"
#[AsCommand(name: 'oshans:data:delete')]
class DataDeleteCommand extends Command
{
    private $em;
    

    public function __construct(EntityManagerInterface $em)
    {
        $this->em = $em;

        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Delete data which is older than the year before; delete logs older than a week')
            ->setHelp('This command deletes all data from the storage which is older than the year before.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // delete data
        $end = new \DateTime('first day of January last year');
        $qb = $this->em->createQueryBuilder()
            ->delete('App:DataStoreBase', 'ds')
            ->andWhere('ds.timestamp < :end')
            ->setParameter('end', $end);

        $resData = $qb->getQuery()->getResult();

        // delete logs
        $dtEnd = new \DateTime();
        $dtEnd->modify('-1 week');
        $qb = $this->em->createQueryBuilder()
            ->delete('App:CommandLog', 'cl')
            ->andWhere('cl.timestamp < :end')
            ->setParameter('end', $dtEnd);

        $resLog = $qb->getQuery()->getResult();

        return 0;
    }
}
