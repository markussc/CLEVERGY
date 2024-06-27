<?php

namespace App\Service;

use App\Entity\OpenWeatherMapDataLatest;
use App\Entity\SmartFoxDataStore;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use SolarData\SolarData;

class SolarRadiationToolbox
{
    private $connectors;
    private $em;
    private $client;
    private $pythonHost;
    private $sd;
    private $solarPotentials;
    private $energyTotals;

    public function __construct(array $connectors, EntityManagerInterface $em, HttpClientInterface $client, string $python_host)
    {
        $this->connectors = $connectors;
        $this->em = $em;
        $this->client = $client;
        $this->pythonHost = $python_host;
        $this->sd = new SolarData();
        $this->sd->setObserverPosition($connectors['openweathermap']['lat'], $connectors['openweathermap']['lon'], $connectors['openweathermap']['alt']);
        $this->solarPotentials = null;
        $this->energyTotals = null;
    }

    public function setSolarPotentials(array $solarPotentials)
    {
        $this->solarPotentials = $solarPotentials;

        return $this;
    }

    public function getSolarPotentials()
    {
        if ($this->solarPotentials === null) {
            if ($this->predictSolarPotentials() === null) {
                // if the prediction fails, fall back to calculation
                $this->calculateSolarPotentials();
            }
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

    /*
     * max power (in Watts) which will be reached during today
     */
    public function getTodayMaxPower()
    {
        if ($this->solarPotentials === null) {
            $this->getSolarPotentials();
        }
        $tomorrow = new \DateTime('tomorrow');
        $maxPower = 0;
        foreach ($this->solarPotentials as $potential) {
            if ($potential['datetime']->getTimestamp() < $tomorrow->getTimestamp()) {
                $maxPower = max($maxPower, $potential['pPotTot']);
            }
        }

        return $maxPower * 1000;
    }

    /*
     * seconds until the power level (in Watts) will be reached
     */
    public function getWaitingTimeUntilPower(int $power)
    {
        if ($this->solarPotentials === null) {
            $this->getSolarPotentials();
        }
        $now = new \DateTime();
        $waitingTime = null;
        foreach ($this->solarPotentials as $potential) {
            if ($potential['pPotTot']*1000 >= $power) {
                $waitingTime = max(0, $potential['datetime']->getTimestamp() - $now->getTimestamp());
                break;
            }
        }

        return $waitingTime;
    }

    /*
     * check if a energy request for the current day can be supplied with given maxPower and an assumed baseLoad for other equipment (base load can lead to negative energy consumption from requestor, if maxDeliveryPower is != 0)
     * $maxConsumptionPower: kW (how much power the requestor will consume at max)
     * $maxDeliveryPower: kW (how much power the requester will deliver at max to support baseLoad)
     * $baseLoad: kW
     */
    public function checkEnergyRequest($maxConsumptionPower, $maxDeliveryPower, $baseLoad)
    {
        if ($this->solarPotentials === null) {
            $this->getSolarPotentials();
        }
        $now = new \DateTime();
        $now = $now->getTimestamp();
        $tomorrow = new \DateTime('tomorrow');
        $tomorrow = $tomorrow->getTimestamp();
        $energyBalance = 0;
        foreach ($this->solarPotentials as $timestamp => $potential) {
            if ($timestamp < $now || $timestamp > $tomorrow) {
                continue;
            }
            $tDiff = ($timestamp - $now)/3600; // time delta in hours
            $pDiff = max(-1*$maxDeliveryPower, min($maxConsumptionPower, $potential['pPotTot'] - $baseLoad)); // respect limits of requestor
            $energyBalance = $energyBalance + $tDiff * $pDiff;
            $now = $timestamp;
        }

        return $energyBalance;
    }

    public function trainSolarPotentialModel()
    {
        $trainingSet = [];
        $smartFoxEntries = $this->em->getRepository(SmartFoxDataStore::class)->getSolarPredictionTrainingData();
        foreach ($smartFoxEntries as $entry) {
            $e = json_decode($entry['json_value'], true);
            if (array_key_exists('pvEnergyPrognosis', $e)) {
                $prog = reset($e['pvEnergyPrognosis']); // this is the first (current) weather data
                $trainingSet[] = [
                    'sunElevation' => $prog['sunPosition'][0],
                    'sunAzimuth' => $prog['sunPosition'][1],
                    'cloudiness' => $prog['cloudiness'],
                    'rain' => array_key_exists('rain', $prog) ? $prog['rain'] : 0,
                    'snow' => array_key_exists('snow', $prog) ? $prog['snow'] : 0,
                    'power' => array_sum($e['PvPower'])/1000, // effective value in kW
                ];
            }
        }

        $this->trainPrediction($trainingSet);
    }

    private function calculateSolarPotentials(\DateTime $from = new \DateTime('-15 minutes'), \DateTime $until = new \DateTime('+ 2 days'))
    {
        $this->solarPotentials = [];
        $weatherEntity = $this->em->getRepository(OpenWeatherMapDataLatest::class)->getLatest('onecallapi30');
        if ($weatherEntity) {
            $weather = $weatherEntity->getData();
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
                if ($sunPosition[0] < 0) {
                    $corrFact = 0;
                } else {
                    $corrFact = 1;
                }
                $sunClimate = [
                    'datetime' => $timestamp,
                    'sunPosition' => $sunPosition,
                    'cloudiness' => $entry['clouds'],
                    'temperature' => $entry['temp']-273.15,
                    'humidity' => $entry['humidity'],
                    'rain' => array_key_exists('rain', $entry) && array_key_exists('1h', $entry['rain']) ? $entry['rain']['1h'] : 0,
                    'snow' => array_key_exists('snow', $entry) && array_key_exists('1h', $entry['snow']) ? $entry['snow']['1h'] : 0,
                ];
                $potentials = [];
                $pPotTot = 0;
                foreach ($this->connectors['smartfox']['pv']['panels'] as $pv) {
                    $pPot = $pv['pmax'] * sin(deg2rad($sunPosition[0])) * $corrFact * (2+abs(sin(deg2rad($sunPosition[0] - $pv['angle'])) * cos(deg2rad($sunPosition[1] - $pv['orientation']))))/3 * (110-$sunClimate['cloudiness'])/110 * (100-min(100, 5*($sunClimate['rain'] + $sunClimate['snow'])))/100;
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
        }

        return $this->solarPotentials;
    }

    private function predictSolarPotentials(\DateTime $from = new \DateTime('-15 minutes'), \DateTime $until = new \DateTime('+ 2 days'))
    {
        $this->solarPotentials = [];
        $weatherEntity = $this->em->getRepository(OpenWeatherMapDataLatest::class)->getLatest('onecallapi30');
        if ($weatherEntity) {
            $weather = $weatherEntity->getData();
            $entries = array_merge([$weather['current']], $weather['hourly']);
            $prevTimestamp = null;
            $sunClimate = [];
            $predictInput = [];
            foreach ($entries as $entry) {
                $timestamp = new \DateTime();
                $timestamp->setTimestamp($entry['dt']);
                if ($timestamp < $prevTimestamp || $timestamp <= $from || $timestamp >= $until) {
                    continue;
                }
                $sunPosition = $this->getSunPosition($timestamp, $entry['temp']+273.15, $entry['pressure']);
                $sunClimate[] = [
                    'datetime' => $timestamp,
                    'sunPosition' => $sunPosition,
                    'cloudiness' => $entry['clouds'],
                    'humidity' => $entry['humidity'],
                    'rain' => array_key_exists('rain', $entry) && array_key_exists('1h', $entry['rain']) ? $entry['rain']['1h'] : 0,
                    'snow' => array_key_exists('snow', $entry) && array_key_exists('1h', $entry['snow']) ? $entry['snow']['1h'] : 0,
                ];
                $predictInput[] = [
                    $sunPosition[0], // sunElevation
                    $sunPosition[1], // sunAzimuth
                    $entry['clouds'], // cloudiness
                    $entry['temp']-273.15, // temperature
                    array_key_exists('rain', $entry) && array_key_exists('1h', $entry['rain']) ? $entry['rain']['1h'] : 0,
                    array_key_exists('snow', $entry) && array_key_exists('1h', $entry['snow']) ? $entry['snow']['1h'] : 0,
                ];
            }
            // call the prediction API
            $predictions = $this->getPrediction($predictInput);
            if ($predictions === null || !is_array($predictions)) {
                // break if any of the predictions fails
                return null;
            }
            $ePotTotCumulated = 0;
            $prevTimestamp = null;
            $prevPower = 0;
            $idx = 0;
            foreach ($entries as $entry) {
                $timestamp = new \DateTime();
                $timestamp->setTimestamp($entry['dt']);
                if ($timestamp < $prevTimestamp || $timestamp <= $from || $timestamp >= $until) {
                    continue;
                }
                $pPotTot = max(0, $predictions[$idx]); // negative values are discarded
                $pPotTot = min($pPotTot, $this->connectors['smartfox']['pv']['inverter']['pmax']); // values larger than inverter capacity are limited
                if ($sunClimate[$idx]['sunPosition'][0] < 0) {
                    // sun is below horizon
                    $pPotTot = 0;
                }
                if ($prevTimestamp !== null) {
                    $timeInterval = ($timestamp->getTimestamp() - $prevTimestamp->getTimestamp())/3600; // interval in hours
                } else {
                    $timeInterval = 0;
                }
                $ePotTot = ($prevPower + $pPotTot) / 2 * $timeInterval;
                $ePotTotCumulated = $ePotTotCumulated + $ePotTot;
                $prevTimestamp = $timestamp;
                $prevPower = $pPotTot;
                $this->solarPotentials[$entry['dt']] = array_merge($sunClimate[$idx], ['pPotTot' => $pPotTot], ['ePotTot' => $ePotTot], ['ePotTotCumulated' => $ePotTotCumulated]);
                $idx++;
            }
        }

        return $this->solarPotentials;
    }

    private function calculateEnergyTotals()
    {
        if ($this->solarPotentials === null) {
            $this->getSolarPotentials();
        }
        $idx = 0;
        $end1Hour = 0;
        $startNextDayIndex = 0;
        $endNextDayIndex = 0;
        foreach ($this->solarPotentials as $pot) {
            if (is_array($pot['datetime'])) {
                $pot['datetime'] = new \DateTime($pot['datetime']['date'] . " " . $pot['datetime']['timezone']);
            }
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

    private function getPrediction(array $input)
    {
        try {
            $url = $this->pythonHost . '/solrad/p_prediction';
            $response = $this->client->request(
                'POST', $url,
                [
                    'json' => $input,
                ]
            );
            $content = $response->getContent();
            $rawdata = json_decode($content, false);
        } catch (\Exception $e) {
           $rawdata = null;
        }

        return $rawdata;
    }

    private function trainPrediction(array $input)
    {
        try {
            $url = $this->pythonHost . '/solrad/p_training';
            $response = $this->client->request(
                'POST', $url,
                [
                    'json' => $input,
                ]
            );
            $content = $response->getContent();
            $rawdata = json_decode($content, false);
        } catch (\Exception $e) {
           $rawdata = null;
        }

        return $rawdata;
    }
}
