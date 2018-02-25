<?php

namespace AppBundle\Entity;

use Doctrine\ORM\EntityRepository;

/**
 * SmartFoxDataStoreRepository
 */
class SmartFoxDataStoreRepository extends EntityRepository
{
    public function getLatest($ip)
    {
        $qb = $this->createQueryBuilder('e')
            ->where('e.connectorId = :ip')
            ->orderBy('e.timestamp', 'desc')
            ->setParameter('ip', $ip)
            ->setMaxResults(1);

        $latest = $qb->getQuery()->getResult();
        if (!count($latest)) {
            return 0;
        } else {
            return $latest[0]->getData();
        }
    }

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

    public function getHistoryLast24h($ip)
    {
        $start = new \DateTime();
        $interval = new \DateInterval("PT24H");
        $interval->invert = 1;
        $start->add($interval);

        $end = new \DateTime();

        return $this->getHistory($ip, $start, $end);
    }

    public function getHistory($ip, \DateTime $start, \DateTime $end = null)
    {
        $qb = $this->createQueryBuilder('e')
            ->where('e.connectorId = :ip')
            ->andWhere('e.timestamp >= :start')
            ->setParameter('start', $start)
            ->setParameter('ip', $ip)
            ->orderBy('e.timestamp', 'asc');
        if ($end) {
            $qb->andWhere('e.timestamp <= :end')
                ->setParameter('end', $end);
        }

        return $qb->getQuery()->getResult();
    }

    public function getEnergyToday($ip)
    {
        $midnight = new \DateTime('today'); // today at midnight (00:00)
        $now = new \DateTime('now');
        return $this->getEnergyInterval($ip, 'PvEnergy', $midnight, $now);
    }

    public function getEnergyInterval($ip, $parameter, $start, $end)
    {
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
