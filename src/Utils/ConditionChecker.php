<?php

namespace App\Utils;

use App\Utils\Connectors\EdiMaxConnector;
use App\Utils\Connectors\MobileAlertsConnector;
use App\Utils\Connectors\OpenWeatherMapConnector;
use App\Utils\Connectors\MyStromConnector;
use App\Utils\Connectors\ShellyConnector;
use App\Utils\Connectors\SmartFoxConnector;
use App\Utils\Connectors\PcoWebConnector;
use Doctrine\Common\Persistence\ObjectManager;

/**
 *
 * @author Markus Schafroth
 */
class ConditionChecker
{
    protected $em;
    protected $smartfox;
    protected $edimax;
    protected $mobilealerts;
    protected $openweathermap;
    protected $mystrom;
    protected $shelly;
    protected $pcoWeb;
    protected $energyLowRate;

    public function __construct(ObjectManager $em, SmartFoxConnector $smartfox, EdiMaxConnector $edimax, MobileAlertsConnector $mobilealerts, OpenWeatherMapConnector $openweathermap, MyStromConnector $mystrom, ShellyConnector $shelly, PcoWebConnector $pcoweb, $energyLowRate)
    {
        $this->em = $em;
        $this->smartfox = $smartfox;
        $this->edimax = $edimax;
        $this->mobilealerts = $mobilealerts;
        $this->openweathermap = $openweathermap;
        $this->mystrom = $mystrom;
        $this->shelly = $shelly;
        $this->pcoweb = $pcoweb;
        $this->energyLowRate = $energyLowRate;
        $this->deviceClass = null;
        $this->ip = null;
        $this->port = null;
    }

    public function checkCondition($device, $type='forceOn')
    {
        $this->deviceClass = "EdiMax";
        $this->ip = $device['ip'];
        $conf = $this->edimax->getConfig($this->ip);
        if (null === $conf) {
            // there is no edimax device with this IP. We check if there is a mystrom device instead
            $conf = $this->mystrom->getConfig($this->ip);
            if (array_key_exists('forceOff', $device)) {
                $conf['forceOff'] = $device['forceOff'];
            }
            $this->deviceClass = "MyStrom";
        }
        if (null === $conf) {
            // there is no edimax and mystrom device with this IP. We check if there is a shelly device instead
            $conf = $this->shelly->getConfig($this->ip, $device['port']);
            $this->port = $device['port'];
            $this->deviceClass = "Shelly";
        }
        // check for on condition for all energy rates
        if (isset($conf['on']) && $type == 'on') {
            if ($this->processConditions($conf['on'])) {

                return true;
            }
        } elseif ($type == 'on') {
            // if we check for 'on' condition but this is not set, it is implicitely fulfilled
            return true;
        }

        // check for forceOn condition for carTimer
        if ($type == "forceOn" && array_key_exists('carTimerData', $conf) && is_array($conf['carTimerData']) && array_key_exists('percent', $conf['carTimerData'])) {
            // get the current percentage for the car
            $latestEcar = $this->em->getRepository("App:EcarDataStore")->getLatest($conf['carTimerData']['connectorId']);
            if (is_array($latestEcar) && array_key_exists('data', $latestEcar) && array_key_exists('soc', $latestEcar['data'])) {
                $currentPercent = $latestEcar['data']['soc'];
                $targetPercent = $conf['carTimerData']['percent'];
                $capacity = $conf['carTimerData']['capacity'];
                $chargingPower = 0.95 * $conf['nominalPower']; // we expect 5% of charging losses
                $hourlyPercent = 100 / $capacity * $chargingPower / 1000;
                $percentDiff = $targetPercent - $currentPercent;
                $now = new \DateTime('now');
                $deadline = new \DateTime($conf['carTimerData']['deadline']['date']);
                $diff = $now->diff($deadline);
                $hours = $diff->h;
                $hours = $hours + ($diff->days*24);
                if ($diff->invert) {
                    $hours *= -1;
                }
                if ($hours >= 0 && $percentDiff > 0) {
                    // the targetPercent and deadline are not reached yet
                    // check if we need to start charging in order to reach the targetPercent until deadline
                    $percentDuringDiff = $hourlyPercent * $hours;
                    if  ($percentDuringDiff < $percentDiff) {
                        // we need to start immediately
                        return true;
                    } elseif (($hours < 24 && $percentDiff > $hourlyPercent*8) || ($this->checkEnergyLowRate() && $percentDiff > $hourlyPercent*8) || $currentPercent < 30) {
                        // the deadline is within 24 hours from now and we need more than 8 hours charging left,
                        // or low rate and more than 8 hours charging left,
                        // or battery level below 30%
                        // in these cases we want to allow max half of the charging power from the grid
                        $switchState = $this->em->getRepository("App:MyStromDataStore")->getLatest($conf['ip']);
                        $powerAverage = $this->em->getRepository("App:SmartFoxDataStore")->getNetPowerAverage($this->smartfox->getIp(), 15);
                        if ($switchState && $powerAverage < $conf['nominalPower']/2) {
                            // currently charging, we want at least half of the charging power by self production
                            return true;
                        }  elseif (!$switchState && $powerAverage < -1*$conf['nominalPower']/2) {
                            // currently not charging, we want half of the charging power by self production after switching on
                            return true;
                        }
                    }
                }
                if ($this->checkEnergyLowRate() && $currentPercent < 15) {
                    return true;
                }
            }
        }

        // check for minimum runtime conditions during low energy rate and in first night half (before midnight)
        $now = new \DateTime("now");
        if ($now->format("H") > 12 && $this->checkEnergyLowRate() && isset($conf['minRunTime'])) {
            $runTime = null;
            if ($this->deviceClass == "EdiMax") {
                $runTime = $this->em->getRepository("App:EdiMaxDataStore")->getActiveDuration($this->ip);
            } elseif($this->deviceClass == "MyStrom") {
                $runTime = $this->em->getRepository("App:MyStromDataStore")->getActiveDuration($this->ip);
            } elseif($this->deviceClass == "Shelly") {
                $runTime = $this->em->getRepository("App:ShellyDataStore")->getActiveDuration($this->ip);
            }
            if ($runTime !== null && $runTime < $conf['minRunTime']) {
                if ($type !== 'forceOff') {
                    // in case we check an "activate" condition, we want to return true
                    return true;
                }
            }
        }

        // check for force conditions for all energy rates
        if ($type == 'forceOn' && isset($conf['forceOn'])) {
            if ($this->processConditions($conf['forceOn'])) {

                return true;
            }
        }
        if ($type == 'forceOff' && isset($conf['forceOff'])) {
            if ($this->processConditions($conf['forceOff'])) {

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
        $nowN = $now->format('N');
        if (!isset($this->energyLowRate['days'])) {
            $this->energyLowRate['days'] = [];
        }
        foreach ($this->energyLowRate['days'] as $lowRateDay) {
            // check if the current day is a full-low rate day or a separate start hour is specified
            $lowRateDayConfig = explode(",", $lowRateDay);
            if ($nowN == $lowRateDayConfig[0]) {
                if (count($lowRateDayConfig) == 1) {
                    // this is a full-low rate day
                    return true;
                } else {
                    // set the specific start hour for this day
                    $this->energyLowRate['start'] = $lowRateDayConfig[1];
                }
            }
        }
        if ($nowH >= $this->energyLowRate['start'] || $nowH < $this->energyLowRate['end']) {
            return true;
        } else {
            return false;
        }
    }

    private function processConditions($conditionSets)
    {
        // all conditions in one set must be fulfilled
        // at least one set must be fulfilled
        $fulfilled = false;
        foreach ($conditionSets as $conditions) {
            if ($this->checkConditionSet($conditions)) {
                $fulfilled = true;
                break;
            }
        }

        return $fulfilled;
    }

    private function checkConditionSet($conditions)
    {
        $fulfilled = false;
        foreach ($conditions as $sensor => $condition) {
            $condArr = explode(':', $sensor);
            if ($condArr[0] == 'mobilealerts') {
                $maData = $this->mobilealerts->getAllLatest();
                $sensorData = $maData[$condArr[1]][$condArr[2]];
                // check if > or < should be checked
                if (strpos($condition, 'rain') !== false) {
                    // we check for rain (more than x)
                    $condition = floatval(str_replace('rain>', '', $condition));
                    $rain = $this->em->getRepository("App:MobileAlertsDataStore")->getDiffLast60Min($condArr[1]);
                    if ($rain > strtolower($condition)) {
                        $fulfilled = true;
                    } else {
                        $fulfilled = false;
                        break;
                    }
                } elseif (strpos($condition, '<') !== false){
                    // we have a smaller than condition
                    $condition = str_replace('<', '', $condition);
                    if (floatval($sensorData['value']) < floatval($condition)) {
                        $fulfilled = true;
                    } else {
                        $fulfilled = false;
                        break;
                    }
                } elseif (strpos($condition, '>') !== false) {
                    // we have larger than condition
                    $condition = str_replace('>', '', $condition);
                    if (floatval($sensorData['value']) > floatval($condition)) {
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
                } elseif (strpos($condition, '<') !== false) {
                    // we have a smaller than condition
                    $condition = str_replace('<', '', $condition);
                    if (floatval($weatherData) < floatval($condition)) {
                        $fulfilled = true;
                    } else {
                        $fulfilled = false;
                        break;
                    }
                } else {
                    // we have a equal condition
                    $condition = str_replace('=', '', $condition);
                    if (strtolower($weatherData) == strtolower($condition)) {
                        $fulfilled = true;
                    } else {
                        $fulfilled = false;
                        break;
                    }
                }
            }
            if (strpos($condArr[0], 'time')===0) {
                $currentTime = date('H')*60 + date('i');
                $timeDataArr = explode(':', str_replace('>', '', str_replace('<', '', $condition)));
                $timeData = $timeDataArr[0]*60+$timeDataArr[1];
                // check if > or < should be checked
                if (strpos($condition, '>') !== false) {
                    // we have larger than condition
                    if ($currentTime > $timeData) {
                        $fulfilled = true;
                    } else {
                        $fulfilled = false;
                        break;
                    }
                } elseif (strpos($condition, '<') !== false) {
                    // we have a smaller than condition
                    if ($currentTime < $timeData) {
                        $fulfilled = true;
                    } else {
                        $fulfilled = false;
                        break;
                    }
                }
            }
            if (strpos($condArr[0], 'outsideTemp')===0) {
                $pcoweb = $this->pcoweb->getAllLatest();
                $outsideTemp = $pcoweb['outsideTemp'];
                $tempData = str_replace('<', '', str_replace('>', '', $condition));
                // check if > or < should be checked
                if (strpos($condition, '>') !== false) {
                    // we have larger than condition
                    if ($outsideTemp > $tempData) {
                        $fulfilled = true;
                    } else {
                        $fulfilled = false;
                        break;
                    }
                } elseif (strpos($condition, '<') !== false) {
                    // we have a smaller than condition
                    if ($outsideTemp < $tempData) {
                        $fulfilled = true;
                    } else {
                        $fulfilled = false;
                        break;
                    }
                }
            }
            if ($condArr[0] == 'mystrom') {
                $status = $this->em->getRepository("App:MyStromDataStore")->getLatest($condArr[1]);
                // we only have equal condition (true / false)
                if ($status == $condition) {
                    $fulfilled = true;
                } else {
                    $fulfilled = false;
                    break;
                }
            }
            if ($condArr[0] == 'alarm') {
                $status = $this->em->getRepository("App:Settings")->getMode('alarm');
                // we only have equal condition (true / false)
                if ($status == $condition) {
                    $fulfilled = true;
                } else {
                    $fulfilled = false;
                    break;
                }
            }
            if ($condArr[0] == 'runTime') {
                $runTime = null;
                if ($this->deviceClass == 'EdiMax') {
                    $runTime = $this->em->getRepository("App:EdiMaxDataStore")->getActiveDuration($this->ip);
                }
                if ($this->deviceClass == 'MyStrom') {
                    $runTime = $this->em->getRepository("App:MyStromDataStore")->getActiveDuration($this->ip);
                }
                if ($this->deviceClass == 'Shelly') {
                    $runTime = $this->em->getRepository("App:ShellyDataStore")->getActiveDuration($this->ip);
                }
                if ($runTime !== null && intval($runTime) > intval($condition)) {
                    $fulfilled = true;
                } else {
                    $fulfilled = false;
                    break;
                }
            }
        }

        return $fulfilled;
    }
}
