<?php

namespace AppBundle\Entity;

use Doctrine\ORM\EntityRepository;

/**
 * SettingsRepository
 */
class SettingsRepository extends EntityRepository
{
    public function getMode($connectorId)
    {
        $qb = $this->createQueryBuilder('s')
            ->where('s.connectorId = :connectorId')
            ->setParameter('connectorId', $connectorId)
            ->setMaxResults(1);
        $settings = $qb->getQuery()->getOneOrNullResult();
        if ($settings) {
            return ($settings->getMode());
        } else {
            return Settings::MODE_AUTO;
        }
    }
}
