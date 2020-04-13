<?php

namespace AppBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use AppBundle\Entity\SmartFoxDataStore;

class DataArchiveCommand extends Command
{
    // the name of the command (the part after "bin/console")
    protected static $defaultName = 'oshans:data:archive';

    public function __construct(EntityManagerInterface $em)
    {
        $this->em = $em;

        parent::__construct();
    }

    protected function configure()
    {
        $this
            ->setDescription('Archive data which is older than the year before')
            ->setHelp('This command moves all archiveable data to the archive which is older than the year before. Not archiveable data will be deleted.');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $smartFoxData = $this->em->getRepository(SmartFoxDataStore::class)->getArchiveable(500);

        foreach ($smartFoxData as $smartFox) {
            $this->em->remove($smartFox);
            $this->em->flush();
        }
    }
}