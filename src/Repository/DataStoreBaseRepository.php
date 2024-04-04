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
        return $this->getLatestByType($ip, 2);
    }

    /*
     * types:
     * 0 : full entity returned
     * 1  : basic value returned
     * 2  : extended value returned; if not available, basic value returned (default)
     */
    public function getLatestByType($ip, $type = 0)
    {
        $class = static::class;
        $entityClass = str_replace("DataStoreRepository", "DataLatest", str_replace("App\Repository", "App\Entity", $class));
        $latest = null;
        if (class_exists($entityClass)) {
            $latest = $this->getEntityManager()->getRepository($entityClass)->getLatest($ip);
        }
        if ($latest === null) {
            $qb = $this->createQueryBuilder('e')
                ->where('e.connectorId = :ip')
                ->orderBy('e.timestamp', 'desc')
                ->setParameter('ip', $ip)
                ->setMaxResults(1);

            $latest = $qb->getQuery()->getOneOrNullResult();
        }
        if ($latest == null) {
            return 0;
        } else {
            if ($type >= 1) {
                $retVal = $latest->getData();
                if ($type == 2 && method_exists($latest, 'getExtendedData') && $latest->getExtendedData()) {
                    $retVal = $latest->getExtendedData();
                }
            } else {
                $retVal = $latest;
            }
            return $retVal;
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
}
