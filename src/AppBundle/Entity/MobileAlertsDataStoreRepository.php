<?php

namespace AppBundle\Entity;

use Doctrine\ORM\EntityRepository;

/**
 * MobileAlertsDataStoreRepository
 */
class MobileAlertsDataStoreRepository extends EntityRepository
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
            return 0;
        } else {
            return $latest[0]->getData();
        }
    }

    public function getHistoryLast24h($id)
    {
        $start = new \DateTime();
        $interval = new \DateInterval("PT24H");
        $interval->invert = 1;
        $start->add($interval);

        $end = new \DateTime();

        return $this->getHistory($id, $start, $end);
    }

    public function getHistory($id, \DateTime $start, \DateTime $end = null)
    {
        $qb = $this->createQueryBuilder('e')
            ->where('e.connectorId = :id')
            ->andWhere('e.timestamp >= :start')
            ->setParameter('start', $start)
            ->setParameter('id', $id)
            ->orderBy('e.timestamp', 'asc');
        if ($end) {
            $qb->andWhere('e.timestamp <= :end')
                ->setParameter('end', $end);
        }

        return $qb->getQuery()->getResult();
    }
}
