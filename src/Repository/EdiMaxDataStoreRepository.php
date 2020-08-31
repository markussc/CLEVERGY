<?php

namespace App\Repository;

/**
 * EdiMaxDataStoreRepository
 */
class EdiMaxDataStoreRepository extends DataStoreBaseRepository
{
    public function getLatest($ip, $status = -1)
    {
        $qb = $this->createQueryBuilder('e')
            ->where('e.connectorId = :ip')
            ->orderBy('e.timestamp', 'desc')
            ->setParameter('ip', $ip)
            ->setMaxResults(1);
        if ($status != -1) {
            $qb->andWhere('e.boolValue = :status')
               ->setParameter('status', $status);
            return $qb->getQuery()->getResult();
        }
        $latest = $qb->getQuery()->getResult();
        if (!count($latest)) {
            return 0;
        } else {
            return $latest[0]->getData();
        }
    }

    public function getActiveDuration($ip, $start = null, $end = null)
    {
        if ($start === null) {
            $start = new \DateTime('today');
        }
        if ($end === null) {
            $end = new \DateTime('now');
        }

        $qb = $this->createQueryBuilder('e')
            ->select('count(e.id)')
            ->where('e.connectorId = :ip')
            ->andWhere('e.timestamp >= :start')
            ->andWhere('e.timestamp <= :end')
            ->andWhere('e.boolValue = 1')
            ->setParameters([
                'ip' => $ip,
                'start' => $start,
                'end' => $end
            ]);

        return $qb->getQuery()->getSingleScalarResult();
    }
}
