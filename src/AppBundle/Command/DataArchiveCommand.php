<?php

namespace AppBundle\Command;

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
            \AppBundle\Entity\SmartFoxDataStore::class,
            \AppBundle\Entity\PcoWebDataStore::class,
            \AppBundle\Entity\MobileAlertsDataStore::class,
            \AppBundle\Entity\ConexioDataStore::class,
            \AppBundle\Entity\LogoControlDataStore::class,
            \AppBundle\Entity\OpenWeatherMapDataStore::class,
            // without archive functionality
            \AppBundle\Entity\EdiMaxDataStore::class,
            \AppBundle\Entity\MyStromDataStore::class,
            \AppBundle\Entity\ShellyDataStore::class,
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
