<?php

namespace App\Service;

use App\Entity\OpenWeatherMapDataLatest;
use Doctrine\ORM\EntityManagerInterface;
use SolarData\SolarData;

class SolarRadiationToolbox
{
    private $connectors;
    private $em;
    private $sd;
    private $solarPotentials;
    private $energyTotals;

    public function __construct(array $connectors, EntityManagerInterface $em)
    {
        $this->connectors = $connectors;
        $this->em = $em;
        $this->sd = new SolarData();
        $this->sd->setObserverPosition($connectors['openweathermap']['lat'], $connectors['openweathermap']['lon'], $connectors['openweathermap']['alt']);
        $this->solarPotentials = null;
        $this->energyTotals = null;
    }

    public function getSolarPotentials()
    {
        if ($this->solarPotentials === null) {
            $this->calculateSolarPotentials();
        }

        return $this->solarPotentials;
    }

    public function getEnergyTotals()
    {
        if ($this->energyTotals === null) {
            $this->calculateEnergyTotals();
        }

        return $this->energyTotals;
    }

    private function calculateSolarPotentials(\DateTime $from = new \DateTime('-15 minutes'), \DateTime $until = new \DateTime('+ 2 days'))
    {
        $weather = $this->em->getRepository(OpenWeatherMapDataLatest::class)->getLatest('onecallapi30')->getData();
        $this->solarPotentials = [];
        $entries = array_merge([$weather['current']], $weather['hourly']);
        $ePotTotCumulated = 0;
        $prevTimestamp = null;
        $prevPower = 0;
        foreach ($entries as $entry) {
            $timestamp = new \DateTime();
            $timestamp->setTimestamp($entry['dt']);
            if ($timestamp < $prevTimestamp || $timestamp <= $from || $timestamp >= $until) {
                continue;
            }
            $sunPosition = $this->getSunPosition($timestamp, $entry['temp']+273.15, $entry['pressure']);
            if ($sunPosition[0] < 10) {
                $sunPosition[0] = 0;
            }
            $sunClimate = [
                'datetime' => $timestamp,
                'sunPosition' => $sunPosition,
                'cloudiness' => $entry['clouds'],
                'temperature' => $entry['temp']-273.15,
                'humidity' => $entry['humidity'],
            ];
            $potentials = [];
            $pPotTot = 0;
            foreach ($this->connectors['smartfox']['pv']['panels'] as $pv) {
                $pPot = $pv['pmax'] * sin(deg2rad($sunPosition[0])) * cos(deg2rad($sunPosition[0] - $pv['angle'])) * (1+cos(deg2rad($sunPosition[1] - $pv['orientation'])))/2 * (300-$sunClimate['cloudiness']-$sunClimate['humidity'])/300;
                if ($sunClimate['temperature'] > 25) {
                    $pPot = $pPot - 1/3*($sunClimate['temperature'] - 25) * $pPot/100; // decrease by 1% per 3° above 25°C
                }
                $potentials[] = [
                    'panel' => $pv,
                    'pPot' => $pPot,
                ];
                $pPotTot = $pPotTot + $pPot;
            }
            $pPotTot = min($pPotTot, $this->connectors['smartfox']['pv']['inverter']['pmax']);
            if ($prevTimestamp !== null) {
                $timeInterval = ($timestamp->getTimestamp() - $prevTimestamp->getTimestamp())/3600; // interval in hours
            } else {
                $timeInterval = 0;
            }
            $ePotTot = ($prevPower + $pPotTot) / 2 * $timeInterval;
            $ePotTotCumulated = $ePotTotCumulated + $ePotTot;
            $prevTimestamp = $timestamp;
            $prevPower = $pPotTot;
            $this->solarPotentials[$entry['dt']] = array_merge($sunClimate, ['pvPotentials' => $potentials], ['pPotTot' => $pPotTot], ['ePotTot' => $ePotTot], ['ePotTotCumulated' => $ePotTotCumulated]);
        }

        return $this->solarPotentials;
    }

    private function calculateEnergyTotals()
    {
        if ($this->solarPotentials === null) {
            $this->calculateSolarPotentials();
        }
        $idx = 0;
        foreach ($this->solarPotentials as $pot) {
            if ($pot['datetime'] <= new \DateTime('+2 hour')) {
                $end1Hour = $idx;
            }
            if ($pot['datetime'] <= new \DateTime('tomorrow')) {
                $startNextDayIndex = $idx;
            }
            if ($pot['datetime'] <= new \DateTime('tomorrow +1 day')) {
                $endNextDayIndex = $idx;
            }
            $idx++;
        }

        $nextHourEnergyValues = array_slice($this->solarPotentials, 0, $end1Hour, false);
        $tomorrowEnergyValues = array_slice($this->solarPotentials, $startNextDayIndex, $endNextDayIndex-$startNextDayIndex, false);
        $this->energyTotals = [
            '1h'  => (reset($nextHourEnergyValues)['pPotTot'] + end($nextHourEnergyValues)['pPotTot'])/($end1Hour),
            'today' => array_slice($this->solarPotentials, $startNextDayIndex, 1, false)[0]['ePotTotCumulated'],
            'tomorrow'=> end($tomorrowEnergyValues)['ePotTotCumulated'] - reset($tomorrowEnergyValues)['ePotTotCumulated'],
            '48h' => end($this->solarPotentials)['ePotTotCumulated'],
        ];

        return $this->energyTotals;
    }

    /*
     * returns for a given datetime and (optional) temp and pressure:
     * e° : topocentric elevation angle (in degrees)
     * Φ° : topocentric azimuth angle, M for navigators and solar radiation users (in degrees)
     */
    private function getSunPosition(\DateTime $datetime = new \DateTime(), $temp = 20, $pressure = 1000)
    {
        $this->sd->setObserverDate($datetime->format('Y'), $datetime->format('m'), $datetime->format('d'));
        $this->sd->setObserverTime($datetime->format('H'), $datetime->format('i'), $datetime->format('s'));
        $this->sd->setObserverTimezone($datetime->format('Z')/3600);
        $this->sd->setObserverAtmosphericPressure($pressure);
        $this->sd->setObserverAtmosphericTemperature($temp);
        $sunPosition = $this->sd->calculate();

        return [$sunPosition->e°, $sunPosition->Φ°];
    }
}
