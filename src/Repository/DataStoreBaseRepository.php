<?php

namespace App\Repository;

use Doctrine\ORM\EntityRepository;

/**
 * DataStoreBaseRepository
 */
class DataStoreBaseRepository extends EntityRepository
{
    public function getLatest($ip)
    {
        $qb = $this->createQueryBuilder('e')
            ->where('e.connectorId = :ip')
            ->orderBy('e.timestamp', 'desc')
            ->setParameter('ip', $ip)
            ->setMaxResults(1);

        $latest = $qb->getQuery()->getOneOrNullResult();
        if ($latest == null) {
            return 0;
        } else {
            return $latest->getData();
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

    public function getArchiveable($maxResults = 100)
    {
        $start = new \DateTime('first day of January 2000');
        $end = new \DateTime('first day of January last year');
        $qb = $this->createQueryBuilder('e')
            ->where('e.timestamp >= :start')
            ->andWhere('e.timestamp < :end')
            ->orderBy('e.timestamp', 'asc')
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->setMaxResults($maxResults);

        return $qb->getQuery()->getResult();
    }
}
