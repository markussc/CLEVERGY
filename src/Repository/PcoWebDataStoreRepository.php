<?php

namespace App\Repository;

/**
 * PcoWebDataStoreRepository
 */
class PcoWebDataStoreRepository extends DataStoreBaseRepository
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

    public function getLatestNotStatus($ip, $notStatus)
    {
        $qb = $this->createQueryBuilder('e')
            ->where('e.connectorId = :ip')
            ->andWhere('e.jsonValue NOT LIKE :status')
            ->orderBy('e.timestamp', 'desc')
            ->setParameter('ip', $ip)
            ->setParameter('status', '%"' . $notStatus . '"%')
            ->setMaxResults(1);
        return $qb->getQuery()->getResult();
    }
}
