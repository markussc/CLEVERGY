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
}
