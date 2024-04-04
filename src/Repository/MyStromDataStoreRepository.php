<?php

namespace App\Repository;

/**
 * MyStromDataStoreRepository
 */
class MyStromDataStoreRepository extends DataStoreBaseRepository
{
    public function getLatest($ip, $status = -1, $extended = false)
    {
        if ($status == -1) {
            return parent::getLatestWithOptions($ip, $extended);
        }
        $qb = $this->createQueryBuilder('e')
            ->where('e.connectorId = :ip')
            ->orderBy('e.timestamp', 'desc')
            ->setParameter('ip', $ip)
            ->setMaxResults(1);
        if ($status != -1) {
            $qb->andWhere('e.boolValue = :status')
               ->setParameter('status', $status);
            return $qb->getQuery()->getResult();
        }
        $latest = $qb->getQuery()->getResult();
        if (!count($latest)) {
            return 0;
        } else {
            if ($extended && $latest[0]->getExtendedData()) {
                return $latest[0]->getExtendedData();
            } else {
                return $latest[0]->getData();
            }
        }
    }

    public function getLatestExtended($ip, $status = -1)
    {
        return $this->getLatest($ip, $status, true);
    }

    public function getActiveDuration($ip, $start = null, $end = null)
    {
        if ($start === null) {
            $start = new \DateTime('today');
        }
        if ($end === null) {
            $end = new \DateTime('now');
        }

        $qb = $this->createQueryBuilder('e')
            ->select('count(e.id)')
            ->where('e.connectorId = :ip')
            ->andWhere('e.timestamp >= :start')
            ->andWhere('e.timestamp <= :end')
            ->andWhere('e.boolValue = 1')
            ->setParameters([
                'ip' => $ip,
                'start' => $start,
                'end' => $end
            ]);

        return $qb->getQuery()->getSingleScalarResult();
    }

    public function getConsumption($ip, $start = null, $end = null)
    {
        if ($start === null) {
            $start = new \DateTime('today');
        }
        if ($end === null) {
            $end = new \DateTime('now');
        }

        $qb = $this->createQueryBuilder('e')
            ->select('e.jsonValue')
            ->where('e.connectorId = :ip')
            ->andWhere('e.timestamp >= :start')
            ->andWhere('e.timestamp <= :end')
            ->andWhere('e.jsonValue LIKE \'%power%\'')
            ->setParameters([
                'ip' => $ip,
                'start' => $start,
                'end' => $end
            ]);
        $entries = $qb->getQuery()->getResult();
        $consumption = 0;
        foreach ($entries as $entry) {
            if (is_array($entry) && array_key_exists('jsonValue', $entry) && array_key_exists('power', $entry['jsonValue'])) {
                $consumption += $entry['jsonValue']['power']*60;
            }
        }

        return $consumption/3600/1000;
    }
}
