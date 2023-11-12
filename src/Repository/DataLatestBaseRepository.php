<?php

namespace App\Repository;

use Doctrine\ORM\EntityRepository;

/**
 * DataLatestBaseRepository
 */
class DataLatestBaseRepository extends EntityRepository
{
    public function getLatest($ip)
    {
        $qb = $this->createQueryBuilder('e')
            ->where('e.connectorId = :ip')
            ->orderBy('e.timestamp', 'desc')
            ->setParameter('ip', $ip)
            ->setMaxResults(1);

        return $qb->getQuery()->getOneOrNullResult();
    }
}
