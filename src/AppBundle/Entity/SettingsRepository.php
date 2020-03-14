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
        $settings = $qb->getQuery()->getResult();
        if (count($settings)) {
            return ($settings[0]->getMode());
        } else {
            return Settings::MODE_AUTO;
        }
    }
}
