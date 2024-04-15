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

    public function __construct(array $connectors, EntityManagerInterface $em)
    {
        $this->connectors = $connectors;
        $this->em = $em;
        $this->sd = new SolarData();
        $this->sd->setObserverPosition($connectors['openweathermap']['lat'], $connectors['openweathermap']['lon'], $connectors['openweathermap']['alt']);
    }

    public function getSolarPotentials(\DateTime $from = new \DateTime(), \DateTime $until = new \DateTime('+ 2 day'))
    {
        $weather = $this->em->getRepository(OpenWeatherMapDataLatest::class)->getLatest('5dayforecast')->getData();
        $solarPotentials = [];
        foreach ($weather['list'] as $entry) {
                $timestamp = new \DateTime();
                $timestamp->setTimestamp($entry['dt']);
                if ($timestamp <= $from || $timestamp >= $until) {
                    continue;
                }
                $sunPosition = $this->getSunPosition($timestamp, $entry['main']['temp']+273.15, $entry['main']['pressure']);
                if ($sunPosition[0] < 10) {
                    $sunPosition[0] = 0;
                }
                $sunClimate = [
                    'datetime' => $timestamp,
                    'sunPosition' => $sunPosition,
                    'cloudiness' => $entry['clouds']['all'],
                    'temperature' => $entry['main']['temp']+273.15,
                    'humidity' => $entry['main']['humidity'],
                ];
                $potentials = [];
                $pPotTot = 0;
                foreach ($this->connectors['smartfox']['pv']['panels'] as $pv) {
                    $pPot = $pv['pmax'] * sin(deg2rad($sunPosition[0])) * cos(deg2rad($sunPosition[0] - $pv['angle'])) * (1+cos(deg2rad($sunPosition[1] - $pv['orientation'])))/2 * (300-$sunClimate['cloudiness']-$sunClimate['humidity'])/300;
                    $potentials[] = [
                        'panel' => $pv,
                        'pPot' => $pPot,
                    ];
                    $pPotTot = $pPotTot + $pPot;
                }
                $pPotTot = min($pPotTot, $this->connectors['smartfox']['pv']['inverter']['pmax']);
                $solarPotentials[$entry['dt']] = array_merge($sunClimate, ['pvPotentials' => $potentials], ['pPotTot' => $pPotTot]);
        }

        return $solarPotentials;
    }

    /*
     * returns for a given datetime and (optional) temp and pressure:
     * e° : topocentric elevation angle (in degrees)
     * Φ° : topocentric azimuth angle, M for navigators and solar radiation users (in degrees)
     */
    public function getSunPosition(\DateTime $datetime = new \DateTime(), $temp = 20, $pressure = 1000)
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
