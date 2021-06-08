<?php

namespace App\Repository;

/**
 * ShellyDataStoreRepository
 */
class ShellyDataStoreRepository extends DataStoreBaseRepository
{
    public function getLatest($connectorId, $status = -1, $roller = false)
    {
        $qb = $this->createQueryBuilder('e')
            ->where('e.connectorId = :connectorId')
            ->orderBy('e.timestamp', 'desc')
            ->setParameter('connectorId', $connectorId)
            ->setMaxResults(1);
        if ($status != -1) {
            if ($roller) {
                $like = 'NOT LIKE';
                $orNor = 'and';
            } else {
                $like = 'LIKE';
                $orNor = 'or';
            }
            $qb->andWhere('e.jsonValue '.$like.' :status ' . $orNor . ' e.jsonValue '.$like.' :status2')
               ->setParameter('status', '%"val":'.$status.'%')
                ->setParameter('status2', '%"val": '.$status.'%');
            return $qb->getQuery()->getResult();
        }
        $latest = $qb->getQuery()->getResult();
        if (!count($latest)) {
            return 0;
        } else {
            return $latest[0];
        }
    }

    public function getActiveDuration($connectorId, $start = null, $end = null)
    {
        if ($start === null) {
            $start = new \DateTime('today');
        }
        if ($end === null) {
            $end = new \DateTime('now');
        }

        $qb = $this->createQueryBuilder('e')
            ->select('count(e.id)')
            ->where('e.connectorId = :connectorId')
            ->andWhere('e.timestamp >= :start')
            ->andWhere('e.timestamp <= :end')
            ->andWhere('e.jsonValue LIKE :status or e.jsonValue LIKE :status2')
            ->setParameters([
                'connectorId' => $connectorId,
                'start' => $start,
                'end' => $end,
                'status' => '%"val":1%',
                'status2' => '%"val": 1%',
            ]);

        return $qb->getQuery()->getSingleScalarResult();
    }

    public function getConsumption($connectorId, $start = null, $end = null)
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
                'ip' => $connectorId,
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
