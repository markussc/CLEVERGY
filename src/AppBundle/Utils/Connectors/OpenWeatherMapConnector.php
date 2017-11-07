<?php

namespace AppBundle\Utils\Connectors;

use AppBundle\Entity\OpenWeatherMapDataStore;
use Doctrine\ORM\EntityManager;

/**
 *
 * @author Markus Schafroth
 */
class OpenWeatherMapConnector
{
    protected $em;
    protected $browser;
    protected $connectors;

    public function __construct(EntityManager $em, \Buzz\Browser $browser, Array $connectors)
    {
        $this->em = $em;
        $this->browser = $browser;
        $this->config = $connectors['openweathermap'];
    }

    public function save5DayForecastToDb($force = false)
    {
        $id = '5dayforecast';
        $latest = $this->em->getRepository('AppBundle:OpenWeatherMapDataStore')->getLatest($id);
        // calculate time diff
        $now = new \DateTime('now');
        if ($latest) {
            $diff = $latest->getTimestamp()->diff($now)->format('%i');
        } else {
            $diff = 20;
        }
        // we want to store a new forecast not more frequently than every 10 minutes
        if ($force || $diff > 15) {
            $dataJson = $this->browser->get('http://api.openweathermap.org/data/2.5/forecast?lat=' . $this->config['lat'] . '&lon=' . $this->config['lon'] . '&appid=' . $this->config['api_key'])->getContent();
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
        $forecast = $this->em->getRepository('AppBundle:OpenWeatherMapDataStore')->getLatest('5dayforecast');
        if ($forecast) {
            $forecastData = $forecast->getData();
        } else {
            return 100;
        }
        $clouds = 0;
        $counter = 0;
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
        if ($counter) {
            $cloudiness = $clouds / $counter;
        } else {
            $cloudiness = 100;
        }

        return $cloudiness;
    }
}
