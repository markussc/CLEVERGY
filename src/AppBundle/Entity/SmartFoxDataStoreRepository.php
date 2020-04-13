<?php

namespace AppBundle\Entity;

/**
 * SmartFoxDataStoreRepository
 */
class SmartFoxDataStoreRepository extends DataStoreBaseRepository
{
    public function getNetPowerAverage($ip, $minutes)
    {
        return $this->getAverage($ip, $minutes, 'power_io');
    }

    public function getPvPowerAverage($ip, $minutes)
    {
        return $this->getAverage($ip, $minutes, 'PvPower');
    }

    private function getAverage($ip, $minutes, $idx)
    {
        $date = new \DateTime();
        $interval = new \DateInterval("PT" . $minutes . "M");
        $interval->invert = 1;
        $date->add($interval);

        $qb = $this->createQueryBuilder('e')
            ->where('e.connectorId = :ip')
            ->andWhere('e.timestamp > :date')
            ->setParameters([
                'ip' => $ip,
                'date' => $date
            ]);
        $results = $qb->getQuery()->getResult();
        $avgPower = 0;
        $avgIndex = 0;
        foreach ($results as $res) {
            if ($idx === 'PvPower') {
                $newValue = $res->getData()[$idx][0];
            } else {
                $newValue = $res->getData()[$idx];
            }
            $avgPower += $newValue;
            $avgIndex++;
        }
        if ($avgIndex) {
            return ($avgPower / $avgIndex);
        } else {
            return 0;
        }
    }

    public function getEnergyToday($ip)
    {
        return $this->getEnergyInterval($ip, 'PvEnergy');
    }

    public function getEnergyInterval($ip, $parameter, $start = null, $end = null)
    {
        if ($start === null) {
            $start = new \DateTime('today'); // today at midnight (00:00)
        }
        if ($end === null) {
            $end = new \DateTime('now');
        }

        $qbStart = $this->createQueryBuilder('e')
            ->where('e.connectorId = :ip')
            ->andWhere('e.timestamp >= :start')
            ->andWhere('e.timestamp < :end')
            ->setParameter('ip', $ip)
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->orderBy('e.timestamp', 'asc')
            ->setMaxResults(1);
        $startEnergy = $qbStart->getQuery()->getResult();

        $qbEnd = $this->createQueryBuilder('e')
            ->where('e.connectorId = :ip')
            ->andWhere('e.timestamp <= :end')
            ->andWhere('e.timestamp > :start')
            ->setParameter('ip', $ip)
            ->setParameter('end', $end)
            ->setParameter('start', $start)
            ->orderBy('e.timestamp', 'desc')
            ->setMaxResults(1);
        $endEnergy = $qbEnd->getQuery()->getResult();

        if (!count($startEnergy) || !count($endEnergy)) {
            return 0;
        }

        if ($parameter === 'PvEnergy') {
            return $endEnergy[0]->getData()[$parameter][0] - $startEnergy[0]->getData()[$parameter][0];
        } else {
            return $endEnergy[0]->getData()[$parameter] - $startEnergy[0]->getData()[$parameter];
        }
    }
}
