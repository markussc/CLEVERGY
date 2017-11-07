<?php

namespace AppBundle\Entity;

use Doctrine\ORM\EntityRepository;

/**
 * OpenWeatherMapDataStoreRepository
 */
class OpenWeatherMapDataStoreRepository extends EntityRepository
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
            return null;
        } else {
            return $latest[0];
        }
    }
}
