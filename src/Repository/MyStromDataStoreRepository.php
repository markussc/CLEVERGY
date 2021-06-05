<?php

namespace App\Repository;

/**
 * MyStromDataStoreRepository
 */
class MyStromDataStoreRepository extends DataStoreBaseRepository
{
    public function getLatest($ip, $status = -1, $extended = false)
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
            if ($latest[0]->getExtendedData()) {
                return $latest[0]->getExtendedData();
            } else {
                return $latest[0]->getData();
            }
        }
    }

    public function getLatestExtended($ip, $status = -1)
    {
        return $this->getLatest($ip, $status, true);
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
