<?php

namespace App\Command;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

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
        $archiveClasses = [
            // with archive functionality
            \App\Entity\SmartFoxDataStore::class,
            \App\Entity\PcoWebDataStore::class,
            \App\Entity\MobileAlertsDataStore::class,
            \App\Entity\ConexioDataStore::class,
            \App\Entity\LogoControlDataStore::class,
            \App\Entity\OpenWeatherMapDataStore::class,
            \App\Entity\NetatmoDataStore::class,
            // without archive functionality
            \App\Entity\EdiMaxDataStore::class,
            \App\Entity\MyStromDataStore::class,
            \App\Entity\ShellyDataStore::class,
        ];
        foreach ($archiveClasses as $archiveClass) {
            $items = $this->em->getRepository($archiveClass)->getArchiveable(100);

            foreach ($items as $item) {
                $this->em->remove($item);
                $this->em->flush();
            }
        }
    }
}
