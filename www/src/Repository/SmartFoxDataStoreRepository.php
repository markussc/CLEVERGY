<?php

namespace App\Repository;

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
                $newValue = array_sum($res->getData()[$idx]);
            } elseif ($idx === 'power_io' && array_key_exists('StoragePower', $res->getData())) {
                if ($res->getData()['StoragePower'] >= 0) {
                    // positive StoragePower means: battery charging
                    // we subtract the power currently consumed by the battery from the average NetPower as we prefer direct consumption
                    $newValue = $res->getData()['power_io'] - abs($res->getData()['StoragePower']);
                } else {
                    // negative StoragePower means: uncharging battery
                    // we add the power currently delivered from the battery to the average NetPower
                    $newValue = $res->getData()['power_io'] + abs($res->getData()['StoragePower']);
                }
            } elseif (array_key_exists($idx, $res->getData())) {
                $newValue = $res->getData()[$idx];
            } else {
                $newValue = 0;
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

    public function getMin($ip, $minutes, $idx)
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
        $minVal = null;
        foreach ($results as $res) {
            if ($idx === 'PvPower') {
                $newValue = array_sum($res->getData()[$idx]);
            } elseif ($idx === 'power_io' && array_key_exists('StoragePower', $res->getData())) {
                if ($res->getData()['StoragePower'] >= 0) {
                    // positive StoragePower means: battery charging
                    // we subtract the power currently consumed by the battery from the average NetPower as we prefer direct consumption
                    $newValue = $res->getData()['power_io'] - abs($res->getData()['StoragePower']);
                } else {
                    // negative StoragePower means: uncharging battery
                    // we add the power currently delivered from the battery to the average NetPower
                    $newValue = $res->getData()['power_io'] + abs($res->getData()['StoragePower']);
                }
            } elseif (array_key_exists($idx, $res->getData())) {
                $newValue = $res->getData()[$idx];
            } else {
                $newValue = 0;
            }
            if ($minVal == null) {
                $minVal = $newValue;
            } else {
                $minVal = min($minVal, $newValue);
            }
        }

        return $minVal;
    }

    public function getMax($ip, $minutes, $idx)
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
        $maxVal = null;
        foreach ($results as $res) {
            if ($idx === 'PvPower') {
                $newValue = array_sum($res->getData()[$idx]);
            } elseif ($idx === 'power_io' && array_key_exists('StoragePower', $res->getData())) {
                if ($res->getData()['StoragePower'] >= 0) {
                    // positive StoragePower means: battery charging
                    // we subtract the power currently consumed by the battery from the average NetPower as we prefer direct consumption
                    $newValue = $res->getData()['power_io'] - abs($res->getData()['StoragePower']);
                } else {
                    // negative StoragePower means: uncharging battery
                    // we add the power currently delivered from the battery to the average NetPower
                    $newValue = $res->getData()['power_io'] + abs($res->getData()['StoragePower']);
                }
            } elseif (array_key_exists($idx, $res->getData())) {
                $newValue = $res->getData()[$idx];
            } else {
                $newValue = 0;
            }
            if ($maxVal == null) {
                $maxVal = $newValue;
            } else {
                $maxVal = max($maxVal, $newValue);
            }
        }

        return $maxVal;
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
            return array_sum($endEnergy[0]->getData()[$parameter]) - array_sum($startEnergy[0]->getData()[$parameter]);
        } elseif ($parameter === 'PvEnergyAlt') {
            if (array_key_exists(1, $endEnergy[0]->getData()['PvEnergy'])) {
                if (!array_key_exists(1, $startEnergy[0]->getData()['PvEnergy'])) {
                    return $endEnergy[0]->getData()['PvEnergy'][1];
                } else {
                    return $endEnergy[0]->getData()['PvEnergy'][1] - $startEnergy[0]->getData()['PvEnergy'][1];
                }
            } else {
                return 0;
            }
        } elseif (array_key_exists($parameter, $endEnergy[0]->getData()) && array_key_exists($parameter, $startEnergy[0]->getData())) {
            return $endEnergy[0]->getData()[$parameter] - $startEnergy[0]->getData()[$parameter];
        } else {
            return 0;
        }
    }

    /*
     * lowRate = [start, end, days]   :  energy_low_rate parameter according to configuration. If set, only the low rate energy is calculated
     */
    public function getEnergyIntervalHighRate($ip, $parameter, $lowRate, $startInput = null, $endInput = null)
    {
        if (isset($lowRate['start']) && $lowRate['end']) {
            // copy the input datetime objects, as we are going to modify them
            $start = clone $startInput;
            $end = clone $endInput;
            if ($start === null) {
                $start = new \DateTime('today'); // today at midnight (00:00)
            }
            if ($end === null) {
                $end = new \DateTime('now');
            }
            if (!isset($lowRate['days'])) {
                $lowRate['days'] = [];
            }

            if ($start->format('dmY') == $end->format('dmY')) {
                // within one day
                if (in_array($start->format('N'), $lowRate['days'])) {
                    // within one full-low rate day
                    return 0;
                }
                foreach ($lowRate['days'] as $lowRateDay) {
                    // check if for the current lowRateDay a separate start hour is specified
                    $lowRateDayConfig = explode(",", $lowRateDay);
                    if (count($lowRateDayConfig) > 1 && $start->format('N') == $lowRateDayConfig[0]) {
                        //  set start hour to the one specified for this day
                        $lowRate['start'] = $lowRateDayConfig[1];
                    }
                }
                $startHour = $lowRate['end'];
                $startMinute = '00';
                if ($start->format('G') > $startHour) {
                    $startHour = $start->format('G');
                    $startMinute = $start->format('i');
                }
                $endHour = $lowRate['start'];
                $endMinute = '00';
                if ($end->format('G') < $endHour) {
                    $endHour = $end->format('G');
                    $endMinute = $end->format('i');
                }
                $start = \DateTime::createFromFormat('d-m-Y G:i', $start->format('d-m-Y').' '.$startHour.':'.$startMinute);
                $end = \DateTime::createFromFormat('d-m-Y G:i', $end->format('d-m-Y').' '.$endHour.':'.$endMinute);
                return $this->getEnergyInterval($ip, $parameter, $start, $end);
            } else {
                // over multiple days
                $energyCount = 0;
                $currentStart = $start;
                while ($currentStart < $end) {
                    $currentEnd = min(\DateTime::createFromFormat('d-m-Y H:i', $currentStart->format('d-m-Y 23:59')), $end);
                    $energyCount += $this->getEnergyIntervalHighRate($ip, $parameter, $lowRate, $currentStart, $currentEnd);
                    $currentStart->modify('+ 1 day');
                    $currentStart = \DateTime::createFromFormat('d-m-Y H:i', $currentStart->format('d-m-Y 00:00'));
                }
                return $energyCount;
            }
        } else {
            return 0;
        }
    }

    public function getEnergyMix($ip, $lowRate, $start, $end)
    {
        $in = $this->getEnergyInterval($ip, 'energy_in', $start, $end);
        if (isset($lowRate['start']) && $lowRate['end']) {
            $inHighrate = $this->getEnergyIntervalHighRate($ip, 'energy_in', $lowRate, $start, $end);
            $inNormal = 0;
        } else {
            $inHighrate = 0;
            $inNormal = $in;
        }
        $pv = $this->getEnergyInterval($ip, 'PvEnergy', $start, $end);
        $out = $this->getEnergyInterval($ip, 'energy_out', $start, $end);

        return [
            'pv' => ($pv-$out)/1000,
            'low' => ($in - $inHighrate - $inNormal)/1000,
            'high' => $inHighrate/1000,
            'normal' => $inNormal/1000,
        ];
    }

    public function getSolarPredictionTrainingData()
    {
        $date = max(new \DateTime('-90 day'), new \DateTime('2024-06-23')); // we take 3 months as input for our training

        $em = $this->getEntityManager();
        $connection = $em->getConnection();
        $sql = "SELECT `json_value` FROM `data_store` WHERE `timestamp` >= '" . $date->format("Y-m-d") . "' AND `discr_type` LIKE 'smartfoxdatastore' AND MINUTE(timestamp) % 5 = 0";
        $stmt = $connection->prepare($sql);
        $resultSet = $stmt->executeQuery();
        $entities = $resultSet->fetchAll();

        return $entities;
    }
}
