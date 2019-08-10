<?php

namespace AppBundle\Utils;

use AppBundle\Utils\Connectors\EdiMaxConnector;
use AppBundle\Utils\Connectors\MobileAlertsConnector;
use AppBundle\Utils\Connectors\OpenWeatherMapConnector;
use AppBundle\Utils\Connectors\MyStromConnector;
use AppBundle\Utils\Connectors\ShellyConnector;
use AppBundle\Utils\Connectors\PcoWebConnector;
use Doctrine\Common\Persistence\ObjectManager;

/**
 *
 * @author Markus Schafroth
 */
class ConditionChecker
{
    protected $em;
    protected $edimax;
    protected $mobilealerts;
    protected $openweathermap;
    protected $mystrom;
    protected $shelly;
    protected $pcoWeb;
    protected $energyLowRate;

    public function __construct(ObjectManager $em, EdiMaxConnector $edimax, MobileAlertsConnector $mobilealerts, OpenWeatherMapConnector $openweathermap, MyStromConnector $mystrom, ShellyConnector $shelly, PcoWebConnector $pcoweb, $energyLowRate)
    {
        $this->em = $em;
        $this->edimax = $edimax;
        $this->mobilealerts = $mobilealerts;
        $this->openweathermap = $openweathermap;
        $this->mystrom = $mystrom;
        $this->shelly = $shelly;
        $this->pcoweb = $pcoweb;
        $this->energyLowRate = $energyLowRate;
    }

    public function checkCondition($device, $type='forceOn')
    {
        $conf = $this->edimax->getConfig($device['ip']);
        if (null === $conf) {
            // there is no edimax device with this IP. We check if there is a mystrom device instead
            $conf = $this->mystrom->getConfig($device['ip']);
        }
        if (null === $conf) {
            // there is no edimax and mystrom device with this IP. We check if there is a shelly device instead
            $conf = $this->shelly->getConfig($device['ip'], $device['port']);
        }
        // check for force conditions for all energy rates
        if (isset($conf['forceOn'])) {
            if ($this->processConditions($conf['forceOn'])) {

                return true;
            }
        }
        if ($type == 'forceOpen' && isset($conf['forceOpen'])) {
            if ($this->processConditions($conf['forceOpen'])) {

                return true;
            }
        }
        if ($type == 'forceClose' && isset($conf['forceClose'])) {
            if ($this->processConditions($conf['forceClose'])) {

                return true;
            }
        }

        // check for force conditions valid on low energy rate
        if (isset($conf['lowRateOn']) && $this->checkEnergyLowRate()) {
            if ($this->processConditions($conf['lowRateOn'])) {
                return true;
            }
        }

        return false;
    }

    public function checkEnergyLowRate()
    {
        $now = new \DateTime();
        $nowH = $now->format('H');
        if ($nowH >= $this->energyLowRate['start'] || $nowH < $this->energyLowRate['end']) {
            return true;
        } else {
            return false;
        }
    }

    private function processConditions($conditions)
    {
        // if we have several conditions defined, all of them must be fulfilled
        $fulfilled = false;
        foreach ($conditions as $sensor => $condition) {
            $condArr = explode(':', $sensor);
            if ($condArr[0] == 'mobilealerts') {
                $maData = $this->mobilealerts->getAllLatest();
                $sensorData = $maData[$condArr[1]][$condArr[2]];
                // check if > or < should be checked
                if (strpos($condition, '>') !== false) {
                    // we have larger than condition
                    $condition = str_replace('>', '', $condition);
                    if (floatval($sensorData['value']) > floatval($condition)) {
                        $fulfilled = true;
                    } else {
                        $fulfilled = false;
                        break;
                    }
                } else {
                    // we have a smaller than condition
                    $condition = str_replace('<', '', $condition);
                    if (floatval($sensorData['value']) < floatval($condition)) {
                        $fulfilled = true;
                    } else {
                        $fulfilled = false;
                        break;
                    }
                }
            }
            if ($condArr[0] == 'openweathermap') {
                $owmData = $this->openweathermap->getAllLatest();
                $weatherData = $owmData[$condArr[1]];
                // check if > or < should be checked
                if (strpos($condition, '>') !== false) {
                    // we have larger than condition
                    $condition = str_replace('>', '', $condition);
                    if (floatval($weatherData) > floatval($condition)) {
                        $fulfilled = true;
                    } else {
                        $fulfilled = false;
                        break;
                    }
                } else {
                    // we have a smaller than condition
                    $condition = str_replace('<', '', $condition);
                    if (floatval($weatherData) < floatval($condition)) {
                        $fulfilled = true;
                    } else {
                        $fulfilled = false;
                        break;
                    }
                }
            }
        }

        return $fulfilled;
    }
}
