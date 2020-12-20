<?php

namespace App\Repository;

/**
 * WemDataStoreRepository
 */
class WemDataStoreRepository extends DataStoreBaseRepository
{
    public function getLatest($username)
    {
        $qb = $this->createQueryBuilder('e')
            ->where('e.connectorId = :username')
            ->orderBy('e.timestamp', 'desc')
            ->setParameter('username', $username)
            ->setMaxResults(1);

        $latest = $qb->getQuery()->getResult();
        if (!count($latest)) {
            return 0;
        } else {
            return $latest[0]->getData();
        }
    }

    public function getLatestNotStatus($username, $notStatus)
    {
        $qb = $this->createQueryBuilder('e')
            ->where('e.connectorId = :username')
            ->andWhere('e.jsonValue NOT LIKE :status')
            ->orderBy('e.timestamp', 'desc')
            ->setParameter('username', $username)
            ->setParameter('status', '%"' . $notStatus . '"%')
            ->setMaxResults(1);
        return $qb->getQuery()->getResult();
    }
}
