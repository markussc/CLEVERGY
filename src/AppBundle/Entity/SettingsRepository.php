<?php

namespace AppBundle\Entity;

use Doctrine\ORM\EntityRepository;

/**
 * SettingsRepository
 */
class SettingsRepository extends EntityRepository
{
    public function getModePcoWeb()
    {
        $qb = $this->createQueryBuilder('s')
            ->setMaxResults(1);
        $settings = $qb->getQuery()->getSingleResult();
        return ($settings->getMode() & Settings::MODE_MANUAL_PCOWEB);
    }
}
