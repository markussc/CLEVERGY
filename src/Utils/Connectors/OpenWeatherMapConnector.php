<?php

namespace App\Utils\Connectors;

use App\Entity\OpenWeatherMapDataStore;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 *
 * @author Markus Schafroth
 */
class OpenWeatherMapConnector
{
    protected $em;
    protected $client;
    protected $connectors;

    public function __construct(EntityManagerInterface $em, HttpClientInterface $client, Array $connectors)
    {
        $this->em = $em;
        $this->client = $client;
        $this->config = $connectors['openweathermap'];
    }

    public function getAllLatest()
    {
        return [
            'cloudsNextDaylight' => $this->getRelevantCloudsNextDaylightPeriod(),
            'currentClouds' => $this->getCurrentClouds(),
            'currentMain' => $this->getCurrentMain(),
            'currentCode' => $this->getCurrentCode(),
            'dayNight' => $this->getDayNight(),
        ];
    }

    public function saveCurrentWeatherToDb($force = false): void
    {
        $id = 'current';
        $latest = $this->em->getRepository(OpenWeatherMapDataStore::class)->getLatest($id);
        // calculate time diff
        $now = new \DateTime('now');
        if ($latest) {
            $diff = ($now->getTimestamp() - $latest->getTimestamp()->getTimestamp())/60; // diff in minutes
        } else {
            $diff = 20;
        }
        // we want to store a new forecast not more frequently than every 10 minutes
        if ($force || $diff > 15) {
            $dataJson = $this->client->request('GET', 'http://api.openweathermap.org/data/2.5/weather?lat=' . $this->config['lat'] . '&lon=' . $this->config['lon'] . '&appid=' . $this->config['api_key'])->getContent();
            $dataArr = json_decode($dataJson, true);
            $forecast = new OpenWeatherMapDataStore();
            $forecast->setTimestamp(new \DateTime());
            $forecast->setConnectorId($id);
            $forecast->setData($dataArr);
            $this->em->persist($forecast);
            $this->em->flush();
        }
        return;
    }

    public function save5DayForecastToDb($force = false): void
    {
        $id = '5dayforecast';
        $latest = $this->em->getRepository(OpenWeatherMapDataStore::class)->getLatest($id);
        // calculate time diff
        $now = new \DateTime('now');
        if ($latest) {
            $diff = ($now->getTimestamp() - $latest->getTimestamp()->getTimestamp())/60; // diff in minutes
        } else {
            $diff = 20;
        }
        // we want to store a new forecast not more frequently than every 10 minutes
        if ($force || $diff > 15) {
            $dataJson = $this->client->request('GET', 'http://api.openweathermap.org/data/2.5/forecast?lat=' . $this->config['lat'] . '&lon=' . $this->config['lon'] . '&appid=' . $this->config['api_key'])->getContent();
            $dataArr = json_decode($dataJson, true);
            $forecast = new OpenWeatherMapDataStore();
            $forecast->setTimestamp(new \DateTime());
            $forecast->setConnectorId($id);
            $forecast->setData($dataArr);
            $this->em->persist($forecast);
            $this->em->flush();
        }
        return;
    }

    public function getRelevantCloudsNextDaylightPeriod()
    {
        $forecast = $this->em->getRepository(OpenWeatherMapDataStore::class)->getLatest('5dayforecast');
        if ($forecast) {
            $forecastData = $forecast->getData();
        } else {
            return 100;
        }
        $clouds = 0;
        $counter = 0;
        if (isset($forecastData['list'])) {
            foreach ($forecastData['list'] as $elem) {
                $dateTime = new \DateTime();
                $dateTime->setTimestamp($elem['dt']);
                $now = new \DateTime('now');
                if ($now->format('H') < 16) {
                    $relevantDay = new \DateTime('now');
                } else {
                    $relevantDay = new \DateTime('tomorrow');
                }
                // we are interested in the hours between 10 and 16 only of the same and the next day
                if ($dateTime->format('d') == $relevantDay->format('d') && $dateTime->format('H') > 9 && $dateTime->format('H') < 17) {
                    $clouds += $elem['clouds']['all'];
                    $counter++;
                }
            }
        }
        if ($counter) {
            $cloudiness = $clouds / $counter;
        } else {
            $cloudiness = 100;
        }

        return (int)$cloudiness;
    }

    public function getMinTempNextNightPeriod()
    {
        $forecast = $this->em->getRepository(OpenWeatherMapDataStore::class)->getLatest('5dayforecast');
        if ($forecast) {
            $forecastData = $forecast->getData();
        } else {
            return null;
        }
        $minTemp = null;
        if (isset($forecastData['list'])) {
            foreach ($forecastData['list'] as $elem) {
                $dateTime = new \DateTime();
                $dateTime->setTimestamp($elem['dt']);
                $today = new \DateTime('now');
                $tomorrow = new \DateTime('tomorrow');
                // we are interested in the hours between 20 and 8 only from today to tomorrow
                if (($dateTime->format('d') == $today->format('d') && $dateTime->format('H') > 20) || ($dateTime->format('d') == $tomorrow->format('d') && $dateTime->format('H') < 8)) {
                    if ($minTemp === null) {
                        $minTemp = $elem['main']['temp']-273.15;
                    } else {
                        $minTemp = min($minTemp, $elem['main']['temp']-273.15);
                    }
                }
            }
        }

        return $minTemp;
    }

    public function getMaxTempNextDaylightPeriod()
    {
        $forecast = $this->em->getRepository(OpenWeatherMapDataStore::class)->getLatest('5dayforecast');
        if ($forecast) {
            $forecastData = $forecast->getData();
        } else {
            return null;
        }
        $maxTemp = null;
        if (isset($forecastData['list'])) {
            foreach ($forecastData['list'] as $elem) {
                $dateTime = new \DateTime();
                $dateTime->setTimestamp($elem['dt']);
                $tomorrow = new \DateTime('tomorrow');
                // we are interested in the hours between 20 and 8 only from today to tomorrow
                if ($dateTime->format('d') == $tomorrow->format('d') && $dateTime->format('H') > 8 && $dateTime->format('H') < 20) {
                    if ($maxTemp === null) {
                        $maxTemp = $elem['main']['temp']-273.15;
                    } else {
                        $maxTemp = max($maxTemp, $elem['main']['temp']-273.15);
                    }
                }
            }
        }

        return $maxTemp;
    }

    private function getCurrentClouds()
    {
        $current = $this->em->getRepository(OpenWeatherMapDataStore::class)->getLatest('current');
        if ($current) {
            $currentData = $current->getData();
            if (isset($currentData['clouds']['all'])) {
                return $currentData['clouds']['all'];
            }
        }

        // default
        return 50;
    }

    public function getCurrentMain()
    {
        $current = $this->em->getRepository(OpenWeatherMapDataStore::class)->getLatest('current');
        if ($current) {
            $currentData = $current->getData();
            if (isset($currentData['weather'][0]['main'])) {
                return $currentData['weather'][0]['main'];
            }
        }

        // default
        return "clear";
    }

    public function getCurrentCode()
    {
        $current = $this->em->getRepository(OpenWeatherMapDataStore::class)->getLatest('current');
        if ($current) {
            $currentData = $current->getData();
            if (isset($currentData['weather'][0]['icon'])) {
                return $currentData['weather'][0]['id'];
            }
        }

        // default
        return "clear";
    }

    public function getDayNight()
    {
        $current = $this->em->getRepository(OpenWeatherMapDataStore::class)->getLatest('current');
        if ($current) {
            $currentData = $current->getData();
            if (isset($currentData['sys']['sunrise']) && isset($currentData['sys']['sunset'])) {
                $now = time();
                if ($now < $currentData['sys']['sunrise'] || $now > $currentData['sys']['sunset']) {
                    return "n";
                }
            }
        }

        // default
        return "d";
    }
}
