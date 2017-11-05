<?php

namespace AppBundle\Entity;

use Doctrine\ORM\EntityRepository;

/**
 * SmartFoxDataStoreRepository
 */
class SmartFoxDataStoreRepository extends EntityRepository
{
    public function getLatest($ip)
    {
        $qb = $this->createQueryBuilder('e')
            ->where('e.connectorId = :ip')
            ->orderBy('e.timestamp', 'desc')
            ->setParameter('ip', $ip)
            ->setMaxResults(1);

        $latest = $qb->getQuery()->getResult();
        if (!count($latest)) {
            return 0;
        } else {
            return $latest[0]->getData();
        }
    }

    public function getNetPowerAverage($ip, $minutes)
    {
        dump("getNetPowerAverage");
        $date = new \DateTime();
        $interval = new \DateInterval("PT" . $minutes . "M");
        $interval->invert = 1;
        $date->add($interval);

        $qb = $this->createQueryBuilder('e')
            ->where('e.connectorId = :ip')
            ->andWhere('e.timestamp > :date')
            ->setParameters([
                'ip' => $ip,
                'date' => $date
            ]);
        $results = $qb->getQuery()->getResult();
        $avgPower = 0;
        $avgIndex = 0;
        foreach ($results as $res) {
            $avgPower += $res->getData()['power_io'];
            $avgIndex++;
        }
        if ($avgIndex) {
            return ($avgPower / $avgIndex);
        } else {
            return 0;
        }
    }
}
