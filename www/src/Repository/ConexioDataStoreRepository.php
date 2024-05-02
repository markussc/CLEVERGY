<?php

namespace App\Repository;

/**
 * ConexioDataStoreRepository
 */
class ConexioDataStoreRepository extends DataStoreBaseRepository
{
    public function getEnergyToday($ip)
    {
        $midnight = new \DateTime('today'); // today at midnight (00:00)
        $now = new \DateTime('now');
        return $this->getEnergyInterval($ip, $midnight, $now);
    }

    public function getEnergyInterval($ip, $start, $end)
    {
        $qbStart = $this->createQueryBuilder('e')
            ->where('e.connectorId = :ip')
            ->andWhere('e.timestamp >= :start')
            ->andWhere('e.timestamp < :end')
            ->setParameter('ip', $ip)
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->orderBy('e.timestamp', 'asc')
            ->setMaxResults(1);
        $startEnergy = $qbStart->getQuery()->getResult();

        $qbEnd = $this->createQueryBuilder('e')
            ->where('e.connectorId = :ip')
            ->andWhere('e.timestamp <= :end')
            ->andWhere('e.timestamp > :start')
            ->setParameter('ip', $ip)
            ->setParameter('end', $end)
            ->setParameter('start', $start)
            ->orderBy('e.timestamp', 'desc')
            ->setMaxResults(1);
        $endEnergy = $qbEnd->getQuery()->getResult();

        if (!count($startEnergy) || !count($endEnergy) || !array_key_exists('q', $startEnergy[0]->getData()) || !array_key_exists('q', $endEnergy[0]->getData())) {
            return 0;
        }

        return $endEnergy[0]->getData()['q'] - $startEnergy[0]->getData()['q'];
    }
}
