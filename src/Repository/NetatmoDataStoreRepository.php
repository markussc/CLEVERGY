<?php

namespace App\Repository;

/**
 * NetatmoDataStoreRepository
 */
class NetatmoDataStoreRepository extends DataStoreBaseRepository
{
    public function getLatest($id)
    {
        $qb = $this->createQueryBuilder('e')
            ->where('e.connectorId = :id')
            ->orderBy('e.timestamp', 'desc')
            ->setParameter('id', $id)
            ->setMaxResults(1);

        $latest = $qb->getQuery()->getResult();
        if (!count($latest)) {
            return null;
        } else {
            return $latest[0];
        }
    }
}
