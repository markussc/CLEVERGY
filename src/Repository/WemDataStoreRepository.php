<?php

namespace App\Repository;

/**
 * WemDataStoreRepository
 */
class WemDataStoreRepository extends DataStoreBaseRepository
{
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
