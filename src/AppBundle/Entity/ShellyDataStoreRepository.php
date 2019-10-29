<?php

namespace AppBundle\Entity;

use Doctrine\ORM\EntityRepository;

/**
 * ShellyDataStoreRepository
 */
class ShellyDataStoreRepository extends EntityRepository
{
    public function getLatest($connectorId, $status = -1)
    {
        $qb = $this->createQueryBuilder('e')
            ->where('e.connectorId = :connectorId')
            ->orderBy('e.timestamp', 'desc')
            ->setParameter('connectorId', $connectorId)
            ->setMaxResults(1);
        if ($status != -1) {
            $qb->andWhere('e.jsonValue LIKE :status')
               ->setParameter('status', '%"val":'.$status.'%');
            return $qb->getQuery()->getResult();
        }
        $latest = $qb->getQuery()->getResult();
        if (!count($latest)) {
            return 0;
        } else {
            return $latest[0]->getData();
        }
    }

    public function getActiveDuration($connectorId, $start, $end)
    {
        $qb = $this->createQueryBuilder('e')
            ->select('count(e.id)')
            ->where('e.connectorId = :connectorId')
            ->andWhere('e.timestamp >= :start')
            ->andWhere('e.timestamp <= :end')
            ->andWhere('e.jsonValue LIKE :status')
            ->setParameters([
                'connectorId' => $connectorId,
                'start' => $start,
                'end' => $end,
                'status' => '%"val":1%',
            ]);

        return $qb->getQuery()->getSingleScalarResult();
    }
}
