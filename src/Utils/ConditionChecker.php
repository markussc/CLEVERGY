<?php

namespace App\Utils;

use App\Entity\MyStromDataStore;
use App\Entity\ShellyDataStore;
use App\Entity\MobileAlertsDataStore;
use App\Entity\Settings;
use App\Entity\SmartFoxDataStore;
use App\Utils\Connectors\MobileAlertsConnector;
use App\Utils\Connectors\OpenWeatherMapConnector;
use App\Utils\Connectors\MyStromConnector;
use App\Utils\Connectors\ShellyConnector;
use App\Utils\Connectors\SmartFoxConnector;
use App\Utils\Connectors\PcoWebConnector;
use App\Utils\Connectors\EcarConnector;
use App\Utils\Connectors\GardenaConnector;
use App\Utils\PriorityManager;
use Doctrine\ORM\EntityManagerInterface;

/**
 *
 * @author Markus Schafroth
 */
class ConditionChecker
{
    protected $cm;
    protected $em;
    protected $prio;
    protected $smartfox;
    protected $mobilealerts;
    protected $openweathermap;
    protected $mystrom;
    protected $shelly;
    protected $pcoWeb;
    protected $ecar;
    protected $gardena;
    protected $energyLowRate;

    public function __construct(EntityManagerInterface $em, PriorityManager $prio, SmartFoxConnector $smartfox, MobileAlertsConnector $mobilealerts, OpenWeatherMapConnector $openweathermap, MyStromConnector $mystrom, ShellyConnector $shelly, PcoWebConnector $pcoweb, EcarConnector $ecar, GardenaConnector $gardena, $energyLowRate)
    {
        $this->em = $em;
        $this->prio = $prio;
        $this->smartfox = $smartfox;
        $this->mobilealerts = $mobilealerts;
        $this->openweathermap = $openweathermap;
        $this->mystrom = $mystrom;
        $this->shelly = $shelly;
        $this->pcoweb = $pcoweb;
        $this->ecar = $ecar;
        $this->gardena = $gardena;
        $this->energyLowRate = $energyLowRate;
        $this->deviceClass = null;
        $this->ip = null;
        $this->port = null;
    }

    public function checkCondition($device, $type='forceOn')
    {
        $this->deviceClass = "MyStrom";
        $this->ip = $device['ip'];
        $conf = $this->mystrom->getConfig($this->ip);
        if (null !== $conf) {
            // this is a mystrom device; it's possible that there is an additional forceOff condition set, which is not part of the static/dynamic configuration!
            if (array_key_exists('forceOff', $device)) {
                $conf['forceOff'] = $device['forceOff'];
            }
        }
        if (null === $conf) {
            // there is no mystrom device with this IP. We check if there is a shelly device instead
            $conf = $this->shelly->getConfig($this->ip.'_'.$device['port']);
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
            if ($this->ecar->checkHighPriority($conf, $this->checkEnergyLowRate())) {
                return true;
            }
        }

        // check for minimum runtime conditions during low energy rate and in first night half (before midnight)
        $now = new \DateTime("now");
        if ($now->format("H") > 12 && $this->checkEnergyLowRate() && isset($conf['minRunTime'])) {
            $runTime = null;
            if($this->deviceClass == "MyStrom") {
                $runTime = $this->em->getRepository(MyStromDataStore::class)->getActiveDuration($this->ip);
            } elseif($this->deviceClass == "Shelly") {
                $runTime = $this->em->getRepository(ShellyDataStore::class)->getActiveDuration($this->ip);
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
        // check for prioritized forceOn
        if ($type == 'forceOn' && $this->checkPriorityForceOn($this->deviceClass, $conf)) {
            // there is a device with lower priority ready to be stopped
            return true;
        }
        // check forceOff
        if ($type == 'forceOff' && isset($conf['forceOff'])) {
            if ($this->processConditions($conf['forceOff'])) {

                return true;
            }
        }
        // check for prioritized forceOff
        if ($type == 'forceOff' && !$this->checkPriorityForceOff($this->deviceClass, $conf)) {
            // there is a device with higher priority ready to be started
            return true;
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

        // check for keepOn condition
        if ($type == 'keepOn' && isset($conf['keepOn'])) {
            if ($this->processConditions($conf['keepOn'])) {
                return true;
            }
        }

        return false;
    }

    public function checkEnergyLowRate()
    {
        if (isset($this->energyLowRate['start']) && isset($this->energyLowRate['end'])) {
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
                $lowRate = true;
            } else {
                $lowRate = false;
            }
        } else {
            // no differentiation of rates
            $lowRate = true;
        }

        return $lowRate;
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
                    $rain = $this->em->getRepository(MobileAlertsDataStore::class)->getDiffLast60Min($condArr[1]);
                    if ($rain > strtolower($condition)) {
                        $fulfilled = true;
                    } else {
                        $fulfilled = false;
                        break;
                    }
                } elseif (strpos($condition, '<') !== false){
                    // we have a smaller than condition
                    $condition = str_replace('<', '', $condition);
                    if (is_array($sensorData) && array_key_exists('value', $sensorData) && floatval($sensorData['value']) < floatval($condition)) {
                        $fulfilled = true;
                    } else {
                        $fulfilled = false;
                        break;
                    }
                } elseif (strpos($condition, '>') !== false) {
                    // we have larger than condition
                    $condition = str_replace('>', '', $condition);
                    if (is_array($sensorData) && array_key_exists('value', $sensorData) && floatval($sensorData['value']) > floatval($condition)) {
                        $fulfilled = true;
                    } else {
                        $fulfilled = false;
                        break;
                    }
                }
            }
            if ($condArr[0] == 'gardena') {
                $sensorData = $this->gardena->getSensorValue($condArr[1], $condArr[2]);
                if ($sensorData === null) {
                    break;
                }
                // check if > or < should be checked
                if (strpos($condition, '<') !== false){
                    // we have a smaller than condition
                    $condition = str_replace('<', '', $condition);
                    if (floatval($sensorData) < floatval($condition)) {
                        $fulfilled = true;
                    } else {
                        $fulfilled = false;
                        break;
                    }
                } elseif (strpos($condition, '>') !== false) {
                    // we have larger than condition
                    $condition = str_replace('>', '', $condition);
                    if (floatval($sensorData) > floatval($condition)) {
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
                $status = $this->em->getRepository(MyStromDataStore::class)->getLatest($condArr[1]);
                // we only have equal condition (true / false)
                if ($status == $condition) {
                    $fulfilled = true;
                } else {
                    $fulfilled = false;
                    break;
                }
            }
            if ($condArr[0] == 'alarm') {
                $status = $this->em->getRepository(Settings::class)->getMode('alarm');
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
                if ($this->deviceClass == 'MyStrom') {
                    $runTime = $this->em->getRepository(MyStromDataStore::class)->getActiveDuration($this->ip);
                }
                if ($this->deviceClass == 'Shelly') {
                    $runTime = $this->em->getRepository(ShellyDataStore::class)->getActiveDuration($this->ip);
                }
                if ($runTime !== null && intval($runTime) > intval($condition)) {
                    $fulfilled = true;
                } else {
                    $fulfilled = false;
                    break;
                }
            }
            if ($condArr[0] == 'smartfox') {
                $smartFox = $this->smartfox->getAllLatest();
                if (is_array($smartFox) && -1 * $smartFox['power_io'] > $condition) {
                    $fulfilled = true;
                } else {
                    $fulfilled = false;
                    break;
                }
            }
            if ($condArr[0] == 'battery') {
                $smartFox = $this->smartfox->getAllLatest();
                if (is_array($smartFox) && array_key_exists('StorageSoc', $smartFox)) {
                    $currentSoc = $smartFox['StorageSoc'];
                    $socThresh = str_replace('<', '', str_replace('>', '', $condition));
                    // check if > or < should be checked
                    if (strpos($condition, '>') !== false) {
                        // we have larger than condition
                        if ($currentSoc > $socThresh) {
                            $fulfilled = true;
                        } else {
                            $fulfilled = false;
                            break;
                        }
                    } elseif (strpos($condition, '<') !== false) {
                        // we have a smaller than condition
                        if ($currentSoc < $socThresh) {
                            $fulfilled = true;
                        } else {
                            $fulfilled = false;
                            break;
                        }
                    }
                } else {
                    $fulfilled = true;
                }
            }
        }

        return $fulfilled;
    }

    /*
     * returns true, if we have priority (i.e. no force off required)
     */
    private function checkPriorityForceOff($deviceClass, $device)
    {
        // check if there is a waiting device with higher priority (return false if there is one with higher priority, true if we have priority)
        if (array_key_exists('priority', $device) && array_key_exists('nominalPower', $device)) {
            $currentAveragePower = $this->em->getRepository(SmartFoxDataStore::class)->getNetPowerAverage($this->smartfox->getIp(), 2);
            // check if device is currently running
            $status = [];
            if ($deviceClass == "MyStrom") {
                $status = $this->mystrom->getStatus($device);
            } elseif ($deviceClass == "Shelly") {
                $status = $this->shelly->getStatus($device);
            }
            // check if we should turn off or prevent to turn on (forceOff)
            if (array_key_exists('val', $status) && $status['val'] && array_key_exists('power', $status)) {
                // currently turned on, calculate using effective current power used by the device
                $maxNominalPower = -1*($currentAveragePower - $status['power']);
            } elseif (array_key_exists('val', $status) && $status['val']) {
                // currently turned on, calculate using 90% of nominalPower
                $maxNominalPower = -1*($currentAveragePower - 0.9 * $device['nominalPower']);
            } else {
                // currently turned off, check with current averagePower
                $maxNominalPower = -1*($currentAveragePower);
            }
            $otherDeviceWaiting = $this->prio->checkWaitingDevice($this, $device['priority']+1, $maxNominalPower);
            if ($otherDeviceWaiting) {
                // other device is waiting, we have no priority
                return false;
            }
        }
        // we do not have any priority set or no other device is waiting, so we have priority
        return true;
    }

    /*
     * returns true, if we have priority (i.e. forceOn required)
     */
    private function checkPriorityForceOn($deviceClass, $device)
    {
        // check if there is a device with lower priority to be turned off first
        if (array_key_exists('priority', $device) && $device['priority'] > 0 && array_key_exists('nominalPower', $device)) {
            // check if device is currently running
            $status = [];
            if ($deviceClass == "MyStrom") {
                $status = $this->mystrom->getStatus($device);
            } elseif ($deviceClass == "Shelly") {
                $status = $this->shelly->getStatus($device);
            }
            if (array_key_exists('val', $status) && $status['val']) {
                $currentAveragePower = $this->em->getRepository(SmartFoxDataStore::class)->getNetPowerAverage($this->smartfox->getIp(), 5);
                if (array_key_exists('power', $status)) {
                    // if we have a valid power value, use this instead of the nominal power
                    $device['nominalPower'] = $status['power'];
                }
                if ($currentAveragePower < $device['nominalPower']/4) {
                    // currently running and only small part of nominalPower is currently imported
                    // check if there is another device with lower priority that could be turned off first
                    $otherDeviceStopReady = $this->prio->checkStoppingDevice($this, $device['priority']-1);
                    if ($otherDeviceStopReady) {
                        // Another device with lower priority is ready to be stopped. This means, we have priority.
                        return true;
                    }
                }
            }
        }
        // we do not have any priority set or not currently running, so we have no priority
        return false;
    }
}
