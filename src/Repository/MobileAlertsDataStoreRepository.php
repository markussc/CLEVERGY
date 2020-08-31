<?php

namespace App\Repository;

/**
 * MobileAlertsDataStoreRepository
 */
class MobileAlertsDataStoreRepository extends DataStoreBaseRepository
{
    public function getDiffLast60Min($id)
    {
        $before = new \DateTime();
        $interval = new \DateInterval("PT60M");
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
