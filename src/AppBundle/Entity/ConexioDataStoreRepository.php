<?php

namespace AppBundle\Entity;

use Doctrine\ORM\EntityRepository;

/**
 * ConexioDataStoreRepository
 */
class ConexioDataStoreRepository extends EntityRepository
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

    public function getHistoryLast24h($ip)
    {
        $start = new \DateTime();
        $interval = new \DateInterval("PT24H");
        $interval->invert = 1;
        $start->add($interval);

        $end = new \DateTime();

        return $this->getHistory($ip, $start, $end);
    }

    public function getHistory($ip, \DateTime $start, \DateTime $end = null)
    {
        $qb = $this->createQueryBuilder('e')
            ->where('e.connectorId = :ip')
            ->andWhere('e.timestamp >= :start')
            ->setParameter('start', $start)
            ->setParameter('ip', $ip)
            ->orderBy('e.timestamp', 'asc');
        if ($end) {
            $qb->andWhere('e.timestamp <= :end')
                ->setParameter('end', $end);
        }

        return $qb->getQuery()->getResult();
    }

    public function getEnergyToday($ip)
    {
        $today = new \DateTime('today'); // today at midnight (00:00)
        $qbMidnight = $this->createQueryBuilder('e')
            ->where('e.connectorId = :ip')
            ->andWhere('e.timestamp < :today')
            ->setParameter('ip', $ip)
            ->setParameter('today', $today)
            ->orderBy('e.timestamp', 'desc')
            ->setMaxResults(1);
        $midnight = $qbMidnight->getQuery()->getResult();

        $qbNow = $this->createQueryBuilder('e')
            ->where('e.connectorId = :ip')
            ->andWhere('e.timestamp >= :today')
            ->setParameter('ip', $ip)
            ->setParameter('today', $today)
            ->orderBy('e.timestamp', 'desc')
            ->setMaxResults(1);
        $now = $qbNow->getQuery()->getResult();

        if (!count($midnight)) {
            $midnight = $now;
        }

        return $now[0]->getData()['q'] - $midnight[0]->getData()['q'];
    }
}
