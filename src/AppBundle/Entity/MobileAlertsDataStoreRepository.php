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

    public function getDiffLast15Min($id)
    {
        $last = $this->getLatest($id);
        $before = new \DateTime();
        $interval = new \DateInterval("PT15M");
        $interval->invert = 1;
        $before->add($interval);
        $history = $this->getHistory($id, $before, new \DateTime());
        if (count($history) > 0) {
            return $history[count($history)-1]->getData()['1']['value'] - $history[0]->getData()['1']['value'];
        } else {
            return 0;
        }
    }
}
