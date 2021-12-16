<?php

namespace App\Utils;

use App\Entity\Settings;
use App\Entity\MyStromDataStore;
use App\Entity\ConexioDataStore;
use App\Entity\LogoControlDataStore;
use App\Entity\PcoWebDataStore;
use App\Entity\WemDataStore;
use App\Entity\SmartFoxDataStore;
use App\Entity\MobileAlertsDataStore;
use App\Entity\ShellyDataStore;
use App\Entity\NetatmoDataStore;
use App\Entity\EcarDataStore;
use App\Entity\CommandLog;
use App\Utils\Connectors\MobileAlertsConnector;
use App\Utils\Connectors\OpenWeatherMapConnector;
use App\Utils\Connectors\MyStromConnector;
use App\Utils\Connectors\ShellyConnector;
use App\Utils\Connectors\PcoWebConnector;
use App\Utils\Connectors\WemConnector;
use App\Utils\Connectors\LogoControlConnector;
use App\Utils\Connectors\ThreemaConnector;
use App\Utils\Connectors\SmartFoxConnector;
use App\Utils\Connectors\ConexioConnector;
use App\Utils\Connectors\NetatmoConnector;
use App\Utils\Connectors\GardenaConnector;
use App\Utils\Connectors\EcarConnector;
use App\Utils\ConditionChecker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 *
 * @author Markus Schafroth
 */
class LogicProcessor
{
    protected $em;
    protected $mobilealerts;
    protected $openweathermap;
    protected $mystrom;
    protected $shelly;
    protected $smartfox;
    protected $pcoweb;
    protected $wem;
    protected $conexio;
    protected $logo;
    protected $netatmo;
    protected $gardena;
    protected $threema;
    protected $conditionchecker;
    protected $translator;
    protected $energyLowRate;
    protected $connectors;

    public function __construct(EntityManagerInterface $em, MobileAlertsConnector $mobilealerts, OpenWeatherMapConnector $openweathermap, MyStromConnector $mystrom, ShellyConnector $shelly, SmartFoxConnector $smartfox, PcoWebConnector $pcoweb, WemConnector $wem, ConexioConnector $conexio, LogoControlConnector $logo, NetatmoConnector $netatmo, GardenaConnector $gardena, EcarConnector $ecar, ThreemaConnector $threema, ConditionChecker $conditionchecker, TranslatorInterface $translator, $energyLowRate, $minInsideTemp, Array $connectors)
    {
        $this->em = $em;
        $this->mobilealerts = $mobilealerts;
        $this->openweathermap = $openweathermap;
        $this->mystrom = $mystrom;
        $this->shelly = $shelly;
        $this->smartfox = $smartfox;
        $this->pcoweb = $pcoweb;
        $this->wem = $wem;
        $this->conexio = $conexio;
        $this->logo = $logo;
        $this->netatmo = $netatmo;
        $this->gardena = $gardena;
        $this->ecar = $ecar;
        $this->threema = $threema;
        $this->conditionchecker = $conditionchecker;
        $this->energyLowRate = $energyLowRate;
        $this->minInsideTemp = $minInsideTemp;
        $this->connectors = $connectors;
        $this->translator = $translator;
    }

    public function execute()
    {
        // mystrom
        $this->initMystrom();

        // shelly
        $this->initShelly();

        // smartfox
        $smartfox = $this->initSmartfox();

        // conexio
        $this->initConexio();

        // logocontrol
        $this->initLogo();

        // pcoweb
        $this->initPcoweb();

        // wem
        $doWemPortal = false;
        if ($this->wem->getUsername()) {
            $now = new \DateTime();
            $nowMinutes = $now->format('i');
            if ($nowMinutes % 15 == 0 || ($smartfox['PvPower'][0] > 0 && $nowMinutes % 5 == 0)) {
                // WEM Portal requests should only be done every 30 minutes; if PV power is available, allow every 15 minutes.
                $doWemPortal = true;
            }
        }
        $this->initWem();


        // mobilealerts
        $this->initMobilealerts();

        // netatmo
        $this->initNetAtmo();

        // gardena
        $this->initGardena();

        // ecar
        $this->initEcar();

        // write to database
        $this->em->flush();

        // openweathermap
        $this->openweathermap->save5DayForecastToDb();
        $this->openweathermap->saveCurrentWeatherToDb();

        // execute auto actions for mystrom devices
        $this->autoActionsMystrom();

        // execute auto actions for shelly devices
        $this->autoActionsShelly();

        // execute auto actions for PcoWeb heating, if we are not in manual mode
        $pcoIp = $this->pcoweb->getIp();
        if ($pcoIp) {
            $pcoMode = $this->em->getRepository('App:Settings')->getMode($pcoIp);
            if (Settings::MODE_MANUAL != $pcoMode) {
                $this->autoActionsPcoWeb($pcoMode);
            }
        }

        // execute auto actions for wem WEM heating, if we are not in manual mode
        $wemUsername = $this->wem->getUsername();
        if ($wemUsername) {
            $wemMode = $this->em->getRepository('App:Settings')->getMode($wemUsername);
            if (Settings::MODE_MANUAL != $wemMode) {
                $this->autoActionsWem($wemMode, $doWemPortal);
            }
        }

        // execute auto actions for Gardena devices
        // do nothing currently

        // process alarms
        $this->processAlarms();

        return 0;
    }

    /**
     * Based on the available values in the DB, decide whether any commands should be sent to attached mystrom devices
     */
    public function autoActionsMystrom()
    {
        $avgPower = $this->em->getRepository('App:SmartFoxDataStore')->getNetPowerAverage($this->smartfox->getIp(), 10);

        // get current net_power
        $smartfox = $this->smartfox->getAllLatest();
        $netPower = $smartfox['power_io'];

        // get  mystrom values
        $mystromDevices = $this->mystrom->getAllLatest();

        // if device is of type battery, calculate the remaining required total runtime and store temporarily as "forceOff --> runTime" condition). It will then turn off if the accumulated activeDuration (over several days) has reached the requested activeTime
        foreach ($mystromDevices as $deviceId => $mystrom) {
            if ($mystrom['type'] == 'battery') {
                if (array_key_exists('activeTime', $mystrom['timerData']) && array_key_exists('activeDuration', $mystrom['timerData']) && $mystrom['timerData']['activeTime']*60 > $mystrom['timerData']['activeDuration']) {
                    // we have not reached the activeTime we need for this battery
                    $mystromDevices[$deviceId]['forceOff'][]['runTime'] = 24*60;
                } else {
                    // we have reached the activeTime we need for this battery
                    $mystromDevices[$deviceId]['forceOff'][]['runTime'] = 0;
                }
            }
        }

        // auto actions for devices which have a nominalPower
        if ($netPower > 0) {
            if ($avgPower > 0) {
                // if current net_power positive and average over last 10 minutes positive as well: turn off the first found device
                foreach ($mystromDevices as $deviceId => $mystrom) {
                    if ($mystrom['nominalPower'] > 0) {
                        // check for "forceOn" or "lowRateOn" conditions (if true, try to turn it on and skip)
                        if ($this->forceOnMystrom($deviceId, $mystrom)) {
                            continue;
                        }
                        // check if the device is on and allowed to be turned off
                        if ($mystrom['status']['val'] && $this->mystrom->switchOK($deviceId)) {
                            $this->mystrom->executeCommand($deviceId, 0);
                            break;
                        }
                    }
                }
            }
        } else {
            // if current net_power negative and average over last 10 minutes negative: turn on a device if its power consumption is less than the negative value (current and average)
            foreach ($mystromDevices as $deviceId => $mystrom) {
                if ($mystrom['nominalPower'] > 0) {
                    // check for "forceOff" conditions (if true, try to turn it off and skip
                    if ($this->conditionchecker->checkCondition($mystrom, 'forceOff')) {
                        $this->forceOffMystrom($deviceId, $mystrom);
                        continue;
                    }
                    // if a "forceOn" condition is set, check it (if true, try to turn it on and skip)
                    if ($this->forceOnMystrom($deviceId, $mystrom)) {
                        continue;
                    }
                    // check if the device is off, compare the required power with the current and average power over the last 10 minutes, and on condition is fulfilled (or not set) and check if the device is allowed to be turned on
                    if (!$mystrom['status']['val'] && $mystrom['nominalPower'] < -1*$netPower && $mystrom['nominalPower'] < -1*$avgPower && $this->conditionchecker->checkCondition($mystrom, 'on') && $this->mystrom->switchOK($deviceId)) {
                        if($this->mystrom->executeCommand($deviceId, 1)) {
                            break;
                        } else {
                            continue;
                        }
                    }
                }
            }
        }

        // auto actions for devices without nominal power
        foreach ($mystromDevices as $deviceId => $mystrom) {
            if ($mystrom['nominalPower'] == 0) {
                if($this->conditionchecker->checkCondition($mystrom, 'forceOff')) {
                    if ($this->forceOffMystrom($deviceId, $mystrom)) {
                        continue;
                    }
                } elseif ($this->conditionchecker->checkCondition($mystrom, 'forceOn')) {
                    // we only try to activate if we disable not close just before (disable wins)
                    if ($this->forceOnMystrom($deviceId, $mystrom)) {
                        continue;
                    }
                }
            }
        }
    }

    private function forceOnMystrom($deviceId, $mystrom)
    {
        $forceOn = $this->conditionchecker->checkCondition($mystrom, 'forceOn');
        if ($forceOn  && $this->mystrom->switchOK($deviceId)) {
            // force turn it on if we are allowed to
            if (!$mystrom['status']['val']) {
                $this->mystrom->executeCommand($deviceId, 1);
            }
            return true;
        } else {
            return false;
        }
    }

    private function forceOffMystrom($deviceId, $mystrom)
    {
        if ($mystrom['status']['val'] && $this->mystrom->switchOK($deviceId)) {
            // force turn it on if we are allowed to
            $this->mystrom->executeCommand($deviceId, 0);
        }

        return true;
    }

    /**
     * Based on the available environmental data, decide whether any commands should be sent to attached shelly devices
     * NOTE: currently only implemented for roller devices
     */
    public function autoActionsShelly()
    {
        $avgPower = $this->em->getRepository('App:SmartFoxDataStore')->getNetPowerAverage($this->smartfox->getIp(), 10);

        // get current net_power
        $smartfox = $this->smartfox->getAllLatest();
        $netPower = $smartfox['power_io'];

        // get  shelly values
        $shellyDevices = $this->shelly->getAllLatest();

        foreach ($shellyDevices as $deviceId => $shelly) {
            $shellyConfig = $this->connectors['shelly'][$deviceId];
            if ($shellyConfig['type'] == 'roller') {
                // for rollers, check forceOpen and forceClose conditions
                if($this->conditionchecker->checkCondition($shelly, 'forceClose')) {
                    if ($this->forceCloseShelly($deviceId, $shelly)) {
                        break;
                    }
                } elseif ($this->conditionchecker->checkCondition($shelly, 'forceOpen')) {
                    // we only try to open if we did not close just before (closing wins)
                    if ($this->forceOpenShelly($deviceId, $shelly)) {
                        break;
                    }
                }
            } elseif ($shellyConfig['type'] != 'door') {
                // for switches, check force off and forceOn conditions
                if($this->conditionchecker->checkCondition($shelly, 'forceOff')) {
                    if ($this->forceOffShelly($deviceId, $shelly)) {
                        continue;
                    }
                } elseif ($this->conditionchecker->checkCondition($shelly, 'forceOn')) {
                    // we only try to activate if we did not disable just before (disable wins)
                    if ($this->forceOnShelly($deviceId, $shelly)) {
                        continue;
                    }
                }
            }
        }
        // auto actions for devices which have a nominalPower
        if ($netPower > 0) {
            if ($avgPower > 0) {
                // if current net_power positive and average over last 10 minutes positive as well: turn off the first found device
                foreach ($shellyDevices as $deviceId => $shelly) {
                    if ($shelly['nominalPower'] > 0) {
                        // check for "forceOn" or "lowRateOn" conditions (if true, try to turn it on and skip)
                        if ($this->forceOnShelly($deviceId, $shelly)) {
                            continue;
                        }
                        // check if the device is on and allowed to be turned off
                        if ($shelly['status']['val'] && $this->shelly->switchOK($deviceId)) {
                            $this->shelly->executeCommand($deviceId, 0);
                            break;
                        }
                    }
                }
            }
        } else {
            // if current net_power negative and average over last 10 minutes negative: turn on a device if its power consumption is less than the negative value (current and average)
            foreach ($shellyDevices as $deviceId => $shelly) {
                if ($shelly['nominalPower'] > 0) {
                    // check for "forceOff" conditions (if true, try to turn it off and skip
                    if ($this->conditionchecker->checkCondition($shelly, 'forceOff')) {
                        $this->forceOffShelly($deviceId, $shelly);
                        continue;
                    }
                    // if a "forceOn" condition is set, check it (if true, try to turn it on and skip)
                    if ($this->forceOnShelly($deviceId, $shelly)) {
                        continue;
                    }
                    // check if the device is off, compare the required power with the current and average power over the last 10 minutes, and on condition is fulfilled (or not set) and check if the device is allowed to be turned on
                    if (!$shelly['status']['val'] && $shelly['nominalPower'] < -1*$netPower && $shelly['nominalPower'] < -1*$avgPower && $this->conditionchecker->checkCondition($shelly, 'on') && $this->shelly->switchOK($deviceId)) {
                        if($this->shelly->executeCommand($deviceId, 1)) {
                            break;
                        } else {
                            continue;
                        }
                    }
                }
            }
        }
    }

    private function forceOpenShelly($deviceId, $shelly)
    {
        if ($shelly['status']['position'] < 100 && $this->shelly->switchOK($deviceId)) {
            // force open if we are allowed to
            $this->shelly->executeCommand($deviceId, 2);
            return true;
        } else {
            return false;
        }
    }

    private function forceCloseShelly($deviceId, $shelly)
    {
        if ($shelly['status']['position'] > 0 && $this->shelly->switchOK($deviceId)) {
            // force open if we are allowed to
            $this->shelly->executeCommand($deviceId, 3);
            return true;
        } else {
            return false;
        }
    }

    private function forceOnShelly($deviceId, $shelly)
    {
        $forceOn = $this->conditionchecker->checkCondition($shelly, 'forceOn');
        if ($forceOn && $this->shelly->switchOK($deviceId)) {
            // force turn it on if we are allowed to
            if (!$shelly['status']['val']) {
                $this->shelly->executeCommand($deviceId, 1);
            }
            return true;
        } else {
            return false;
        }
    }

    private function forceOffShelly($deviceId, $shelly)
    {
        if ($shelly['status']['val'] && $this->shelly->switchOK($deviceId)) {
            // force turn it on if we are allowed to
            $this->shelly->executeCommand($deviceId, 0);
        }

        return true;
    }

    /**
     * Based on the available values in the DB, decide whether any commands should be sent to attached pcoweb heating
     */
    private function autoActionsPcoWeb($pcoMode)
    {
        if ($pcoMode === Settings::MODE_HOLIDAY) {
            $autoMode = PcoWebConnector::MODE_HOLIDAY;
        } else {
            $autoMode = PcoWebConnector::MODE_AUTO;
        }
        $energyLowRate = $this->conditionchecker->checkEnergyLowRate();
        $smartfox = $this->smartfox->getAllLatest();
        $smartFoxHighPower = $smartfox['digital'][0]['state'];
        $avgPower = $this->em->getRepository('App:SmartFoxDataStore')->getNetPowerAverage($this->smartfox->getIp(), 10);
        $avgPvPower = $this->em->getRepository('App:SmartFoxDataStore')->getPvPowerAverage($this->smartfox->getIp(), 10);
        $nowDateTime = new \DateTime();
        $diffToEndOfLowEnergyRate = $this->energyLowRate['end'] - $nowDateTime->format('H');
        if ($diffToEndOfLowEnergyRate < 0) {
            $diffToEndOfLowEnergyRate += 24;
        }
        $pcoweb = $this->pcoweb->getAll();

        // set the temperature offset for low outside temp
        $tempOffset = 0;
        $outsideTemp = $pcoweb['outsideTemp'];
        if ($outsideTemp < 2) {
            $tempOffset = (1 +(0-$outsideTemp)/10);
        }

        // set the emergency temperature levels
        $minWaterTemp = 38;
        $minInsideTemp = $this->minInsideTemp-0.5+$tempOffset/5;
        if ($pcoMode == Settings::MODE_HOLIDAY) {
            $minInsideTemp = $minInsideTemp - 2;
        }
        // set the max inside temp above which we do not want to have the 2nd heat circle active
            $maxInsideTemp = $this->minInsideTemp+1.7+$tempOffset;
        // readout current temperature values
        if ($this->mobilealerts->getAvailable()) {
            $insideTemp =  $this->mobilealerts->getCurrentMinInsideTemp();
        } elseif ($this->netatmo->getAvailable()) {
            $insideTemp = $this->netatmo->getCurrentMinInsideTemp();
        } else {
            // if no inside sensor is available, we assume 20°C
            $insideTemp = 20;
        }

        $waterTemp = $pcoweb['waterTemp'];
        if ($waterTemp === null) {
            // if waterTemp could not be read out, the values can not be trusted. Skip any further processing.
            return;
        }
        $ppMode = $this->pcoweb->ppModeToInt($pcoweb['ppMode']);
        $ppStatus = 0;
        if ($pcoweb['ppStatus'] == "label.device.status.on") {
            $ppStatus = 1;
        }

        $heatStorageMidTemp = $pcoweb['storTemp'];

        // readout weather forecast (currently the cloudiness for the next mid-day hours period)
        $avgClouds = $this->openweathermap->getRelevantCloudsNextDaylightPeriod();

        // write values to command log
        $commandLog = new CommandLog();
        $commandLog->setHighPvPower($smartFoxHighPower);
        $commandLog->setAvgPvPower($avgPvPower);
        $commandLog->setAvgPower($avgPower);
        $commandLog->setInsideTemp($insideTemp);
        $commandLog->setWaterTemp($waterTemp);
        $commandLog->setHeatStorageMidTemp($heatStorageMidTemp);
        $commandLog->setAvgClouds($avgClouds);
        $log = [];

        if ($this->pcoweb->getIp()) {
            // decide whether it's summer half year
            $isSummer = (\date('z') > 70 && \date('z') < 243); // 10th of march - 31th august

            $activateHeating = false;
            $deactivateHeating = false;
            $warmWater = false;

            // normalize temporary values
            if ($ppMode == PcoWebConnector::MODE_SUMMER && $pcoweb['hwHist'] == 2) {
                $log[] = "normalize hwHysteresis and hotWater after switching to MODE_SUMMER";
                $this->pcoweb->executeCommand('hwHysteresis', 12);
                $this->pcoweb->executeCommand('waterTemp', 52);
            }

            // high PV power handling
            if ($smartFoxHighPower && $waterTemp < 65) {
                // SmartFox has force heating flag set
                $activateHeating = true;
                // we make sure the hwHysteresis is set to a lower value, so hot water heating is forced
                $this->pcoweb->executeCommand('hwHysteresis', 5);
                // we make sure the heating curve (circle 1) is maximized
                $this->pcoweb->executeCommand('hc1', 40);
                $log[] = "PvHighPower, water temp < 65. minimize hwHysteresis (5), increase hc1 (set hc1=40)";

                if ($ppMode !== PcoWebConnector::MODE_AUTO) {
                    $this->pcoweb->executeCommand('mode', PcoWebConnector::MODE_AUTO);
                    $log[] = "set MODE_AUTO due to PvHighPower";
                }
            }

            // heat storage is low or net power is not growing too much into positive. Warm up on high PV power or low energy rate (if it makes any sense)
            if ($heatStorageMidTemp < 33 || ($avgPower < 2*$avgPvPower && ($heatStorageMidTemp < 55 || $waterTemp < 62 ))) {
                if (!$smartFoxHighPower && (((((!$isSummer || $avgClouds > 25) && \date('G') > 10) || \date('G') > 12) && $avgPvPower > 1300) || ($isSummer && $avgPvPower > 3000) )) {
                    // detected high PV power (independently of current use), but SmartFox is not forcing heating
                    // and either
                    // - winter, cloudy or later than 12am together with avgPvPower > 1300 W
                    // - summer and avgPvPower > 3000 W
                    $activateHeating = true;
                    // we make sure the hwHysteresis is set to the default value
                    $this->pcoweb->executeCommand('hwHysteresis', 10);
                    $this->pcoweb->executeCommand('hc1', 35);
                    $log[] = "low heatStorageMidTemp and/or relatively high PV but flag not set with not fully charged heat storage. Set hwHysteresis to default (10), increase hc1 (set hc1=35).";
                    if ($ppMode !== PcoWebConnector::MODE_AUTO && $ppMode !== PcoWebConnector::MODE_HOLIDAY && ($ppMode != PcoWebConnector::MODE_SUMMER || !$ppStatus)) {
                        $this->pcoweb->executeCommand('mode', PcoWebConnector::MODE_HOLIDAY);
                        $log[] = "set MODE_HOLIDAY due to high PV without flag set";
                    }
                }
            }

            // apply emergency actions
            $emergency = false;
            $insideEmergency = false;
            if ($insideTemp < $minInsideTemp || $waterTemp < $minWaterTemp) {
                // we are below expected values (at least for one of the criteria), switch HP on
                $activateHeating = true;
                $emergency = true;
                if ($insideTemp < $minInsideTemp) {
                    $insideEmergency = true;
                    $this->pcoweb->executeCommand('hc2', 30);
                    $log[] = "set hc2=30 as emergency action";
                    if (($ppMode !== Settings::MODE_AUTO || $ppMode !== Settings::MODE_HOLIDAY) && $heatStorageMidTemp < 36 && $pcoweb['effDistrTemp'] < 25) {
                        $this->pcoweb->executeCommand('mode', PcoWebConnector::MODE_HOLIDAY);
                        $log[] = "set MODE_HOLIDAY due to emergency action";
                    }
                } elseif ($pcoMode !== Settings::MODE_HOLIDAY) {
                    // only warmWater is too cold
                    $this->pcoweb->executeCommand('waterTemp', 45);
                    $this->pcoweb->executeCommand('hwHysteresis', 5);
                    $log[] = "set hwHysteresis to 5 and reduce waterTemp due to emergency action: we only want minimal water heating";
                    if ($ppMode !== PcoWebConnector::MODE_SUMMER && $ppMode !== PcoWebConnector::MODE_AUTO) {
                        $this->pcoweb->executeCommand('mode', PcoWebConnector::MODE_SUMMER);
                        $log[] = "set MODE_SUMMER due to emergency action (warm water only)";
                    }
                }
            }

            // default cases for energy low rate
            if (!$emergency && $energyLowRate && $diffToEndOfLowEnergyRate > 1) {
                if ($avgClouds < 30) {
                    // we expect clear sky in the next daylight period which will give some extra heat. Reduce heating curve (circle 1)
                    $this->pcoweb->executeCommand('hc1', 23);
                    $log[] = "not PvHighPower, expected clear sky, reduce hc1 (set hc1=23)";
                } else {
                    $this->pcoweb->executeCommand('hc1', 28);
                    $log[] = "not PvHighPower, expected cloudy sky, increase hc1 (set hc1=28)";
                }
                $warmWater = false;
                if ($diffToEndOfLowEnergyRate <= 2) {
                    // 2 hours before end of energyLowRate, we decrease the hwHysteresis to make sure the warm water can be be heated up (only warm water will be heated during this hour!)
                    $this->pcoweb->executeCommand('hwHysteresis', 5);
                    $log[] = "diffToEndOfLowEnergyRate <= 2. reduce hwHysteresis (5)";
                    if ($pcoMode !== Settings::MODE_HOLIDAY) {
                        $warmWater = true;
                    }
                    $activateHeating = true;
                }
                if ($warmWater && $ppMode !== PcoWebConnector::MODE_SUMMER && ($waterTemp < 50 || $heatStorageMidTemp < 36)) {
                    // warm water generation only
                    $this->pcoweb->executeCommand('hwHysteresis', 10);
                    $this->pcoweb->executeCommand('waterTemp', 52);
                    $this->pcoweb->executeCommand('mode', PcoWebConnector::MODE_SUMMER);
                    $log[] = "set MODE_SUMMER for warm water generation only during low energy rate";
                }
                elseif (!$warmWater && $heatStorageMidTemp < 36) {
                    // storage heating only
                    $activateHeating = true;
                    if ((!$ppStatus || (PcoWebConnector::MODE_SUMMER && $waterTemp > $minWaterTemp + 4)) && ($ppMode !== PcoWebConnector::MODE_AUTO || $ppMode !== PcoWebConnector::MODE_HOLIDAY)) {
                        $this->pcoweb->executeCommand('hwHysteresis', 10);
                        $this->pcoweb->executeCommand('waterTemp', 52);
                        $this->pcoweb->executeCommand('mode', PcoWebConnector::MODE_HOLIDAY);
                        $log[] = "set MODE_HOLIDAY for storage only heating during low energy rate";
                    }
                }
            }

            // end of energy low rate is near. switch to MODE_2ND or MODE_SUMMER (depending on current inside temperature) as soon as possible and reset the hwHysteresis to default value
            if (!$emergency && $diffToEndOfLowEnergyRate <= 1 && $diffToEndOfLowEnergyRate > 0) {
                $deactivateHeating = true;
                if (!$ppStatus && $ppMode !== PcoWebConnector::MODE_2ND && $insideTemp < $minInsideTemp+1) {
                    $this->pcoweb->executeCommand('mode', PcoWebConnector::MODE_2ND);
                    $log[] = "set MODE_2ND in final low energy rate";
                } elseif (!$ppStatus && $ppMode !== PcoWebConnector::MODE_SUMMER && $insideTemp >= $minInsideTemp+2) {
                    $this->pcoweb->executeCommand('mode', PcoWebConnector::MODE_SUMMER);
                    $log[] = "set MODE_SUMMER in final low energy rate";
                }
                $this->pcoweb->executeCommand('hwHysteresis', 10);
                $this->pcoweb->executeCommand('waterTemp', 52);
                $log[] = "set hwHysteresis to default (10) and normalize waterTemp (52)";
            }

            // deactivate 2nd heating circle if insideTemp is > $maxInsideTemp
            if ($insideTemp > $maxInsideTemp) {
                // it's warm enough, disable 2nd heating circle
                $this->pcoweb->executeCommand('hc2', 0);
                $this->pcoweb->executeCommand('cpAutoMode', 1);
                $log[] = "warm enough inside and waterTemp above minimum, disable hc2 (set hc2=0)";
                if ($waterTemp < $minWaterTemp + 3 && $ppMode == PcoWebConnector::MODE_SUMMER && !$emergency && !$warmWater && !$energyLowRate) {
                    $this->pcoweb->executeCommand('mode', PcoWebConnector::MODE_2ND);
                    $log[] = "set MODE_2ND instead of MODE_SUMMER due to water temp dropping towards but not below minimum during high energy rate";
                }
            } else {
                $activate2ndCircle = false;
                // it's not too warm, set 2nd heating circle with a reasonable target temperature
                if (!$emergency && $ppMode == PcoWebConnector::MODE_SUMMER && $insideTemp < ($minInsideTemp + 1)) {
                    // if we are in summer mode and insideTemp drops towards minInsideTemp
                    // if we are currently in summer mode (probably because before it was too warm inside), we switch back to MODE_2ND so 2nd heating circle can restart if required
                    $activate2ndCircle = true;
                    $this->pcoweb->executeCommand('cpAutoMode', 0);
                } elseif ($insideTemp > ($minInsideTemp + 0.8) && $insideTemp <= ($minInsideTemp + 1.5)) {
                    $this->pcoweb->executeCommand('hc2', 19);
                    $log[] = "set hc2=19 due to current inside temp";
                    $activate2ndCircle = true;
                } elseif ($insideTemp >= ($minInsideTemp + 0.5) && $insideTemp <= ($minInsideTemp + 0.8)) {
                    $this->pcoweb->executeCommand('hc2', 23);
                    $log[] = "set hc2=22 due to current inside temp";
                    $activate2ndCircle = true;
                } elseif (!$insideEmergency && $insideTemp < ($minInsideTemp + 0.5)) {
                    // set default value for 2nd heating circle
                    $this->pcoweb->executeCommand('hc2', 28);
                    $log[] = "set hc2=28 due to current inside temp";
                    $activate2ndCircle = true;
                }
                if (!$emergency && !$warmWater && $activate2ndCircle && $ppMode == PcoWebConnector::MODE_SUMMER && (!$ppStatus || $waterTemp > $minWaterTemp + 5)) {
                    // do no switch to MODE_2ND if we are in emergency mode or pp is currently running (except water temp is warmer than min + 5°C)
                    $this->pcoweb->executeCommand('mode', PcoWebConnector::MODE_2ND);
                    $log[] = "set MODE_2ND instead of MODE_SUMMER due to inside temp dropping towards minInsideTemp";
                }
            }

            // check if minimum requirements are fulfilled during high energy rate
            if (!$energyLowRate && !$activateHeating && $insideTemp > ($minInsideTemp + 0.5) && $heatStorageMidTemp > 28 && $waterTemp > ($minWaterTemp + 4)) {
                // the minimum requirements are fulfilled, no heating is required during high energy rate
                $deactivateHeating = true;
                $this->pcoweb->executeCommand('hwHysteresis', 12);
                $log[] = "high energy rate, set high hwHysteresis (12)";
                $this->pcoweb->executeCommand('hc1', 25);
                $log[] = "normalize hc1 (set hc1=25) during high energy rate";
                if ((($isSummer && $insideTemp > ($minInsideTemp + 1))|| $insideTemp >= $maxInsideTemp) && ($ppMode !== PcoWebConnector::MODE_SUMMER && $ppMode !== PcoWebConnector::MODE_HOLIDAY) && $pcoweb['hwHist'] >= 12) {
                    $this->pcoweb->executeCommand('waterTemp', $minWaterTemp+2);
                    $this->pcoweb->executeCommand('hwHysteresis', 2);
                    $this->pcoweb->executeCommand('mode', PcoWebConnector::MODE_SUMMER);
                    $log[] = "set MODE_SUMMER due to high energy rate and reduce waterTemp and hysteresis to min temporarily";
                }
                if ((!$isSummer || $insideTemp < ($minInsideTemp + 1) ) && ($insideTemp < $maxInsideTemp || $ppMode === PcoWebConnector::MODE_HOLIDAY) && $ppMode !== PcoWebConnector::MODE_2ND) {
                    $this->pcoweb->executeCommand('mode', PcoWebConnector::MODE_2ND);
                    $log[] = "set MODE_2ND due to high energy rate";
                }
            }

            // make sure heating is deactivated if not required, during low energy rate
            if (!$activateHeating && $energyLowRate && !$ppStatus) {
                if ($insideTemp > ($minInsideTemp + 1.5)) {
                    if ($ppMode !== PcoWebConnector::MODE_SUMMER) {
                        $this->pcoweb->executeCommand('waterTemp', $minWaterTemp+2);// this will be overwritten in the next loop! the goal is, that during switch to mode_summer, we do not provocate water heating
                        $this->pcoweb->executeCommand('hwHysteresis', 2);           // this will be overwritten in the next loop!
                        $this->pcoweb->executeCommand('mode', PcoWebConnector::MODE_SUMMER);
                        $log[] = "set MODE_SUMMER during low energy rate and high inside temperature and reduce waterTemp and hysteresis to min temporarily";
                    }
                } elseif ($ppMode !== PcoWebConnector::MODE_2ND && $insideTemp <= ($minInsideTemp + 1)) {
                    $this->pcoweb->executeCommand('mode', PcoWebConnector::MODE_2ND);
                    $this->pcoweb->executeCommand('hwHysteresis', 10);
                    $this->pcoweb->executeCommand('waterTemp', 52);
                    $log[] = "set MODE_2ND and normalize hwHysteresis during low energy rate and lower inside temperature";
                }
            }
        }
        $pcowebNew = $this->pcoweb->getAll();
        $commandLog->setPpMode($pcowebNew['ppMode']);
        $commandLog->setLog($log);
        $commandLog->setTimestamp(new \DateTime());
        $this->em->persist($commandLog);
        $this->em->flush();
    }

    public function autoActionsWem($wemMode, $doWemPortal)
    {
        $energyLowRate = $this->conditionchecker->checkEnergyLowRate();
        $wem = $this->wem->getAllLatest();
        $outsideTemp = $wem['outsideTemp'];
        $smartfox = $this->smartfox->getAllLatest();
        $smartFoxHighPower = $smartfox['digital'][1]['state'];
        $netPower = $smartfox['power_io'];
        $avgPower = $this->em->getRepository('App:SmartFoxDataStore')->getNetPowerAverage($this->smartfox->getIp(), 10);
        $avgPvPower = $this->em->getRepository('App:SmartFoxDataStore')->getPvPowerAverage($this->smartfox->getIp(), 10);
        // readout weather forecast (currently the cloudiness for the next mid-day hours period)
        $avgClouds = $this->openweathermap->getRelevantCloudsNextDaylightPeriod();
        // readout current temperature values
        if ($this->mobilealerts->getAvailable()) {
            $insideTemp =  $this->mobilealerts->getCurrentMinInsideTemp();
        } elseif ($this->netatmo->getAvailable()) {
            $insideTemp = $this->netatmo->getCurrentMinInsideTemp();
        } else {
            // if no inside sensor is available, we assume 20°C
            $insideTemp = 20;
        }
        // set the temperature offset for low outside temp
        $tempOffset = 0;
        if ($outsideTemp < 2) {
            $tempOffset = (1 +(0-$outsideTemp)/10);
        }

        // get current ppPowerLevel
        $ppLevel = $wem['ppStatus'];

        // temp diff between setDistrTemp and effDistrTemp
        if ($wem['setDistrTemp'] !== '---') {
            $hc2TempDiff = $wem['setDistrTemp'] - $wem['effDistrTemp'];
        } else {
            $hc2TempDiff = 0;
        }

        $minInsideTemp = $this->minInsideTemp-0.5+$tempOffset/5;
        if ($wemMode == Settings::MODE_HOLIDAY) {
            $minInsideTemp = $minInsideTemp - 2;
        }

        $waterTemp = $wem['waterTemp'];
        if ($waterTemp === null) {
            // if waterTemp could not be read out, the values can not be trusted. Skip any further processing.
            return;
        }

        // write values to command log
        $commandLog = new CommandLog();
        $commandLog->setHighPvPower($smartFoxHighPower);
        $commandLog->setAvgPvPower($avgPvPower);
        $commandLog->setAvgPower($avgPower);
        $commandLog->setWaterTemp($waterTemp);
        //$commandLog->setHeatStorageMidTemp($wem['storTemp']); // currently not available
        $commandLog->setAvgClouds($avgClouds);
        $commandLog->setPpMode($wem['ppMode']);
        $commandLog->setInsideTemp($insideTemp);
        $log = [];

        $log[] = 'current ppLevel: ' . $ppLevel;
        // configure minPpPower
        $minPpPower = 10;
        if ($outsideTemp < 10) {
            // cold outside. set minPpPower depending on hc2TempDiff
            if ($hc2TempDiff > 5) {
                $minPpPower = min(100,  $ppLevel + 5);
            } elseif ($hc2TempDiff > 3) {
                $minPpPower = min(100, $ppLevel + 3);
            } elseif ($hc2TempDiff > 1) {
                $minPpPower = $ppLevel;
            } elseif ($hc2TempDiff <= 0) {
                // hc2TempDiff small, we can reduce minPpPower slightly
                $minPpPower = max(10, $ppLevel - 5);
            } elseif ($hc2TempDiff <= -1) {
                // hc2TempDiff negative, we can reduce minPpPower significantly
                $minPpPower = max(10, $ppLevel - 10);
            }
            $log[] = "set minPpPower to " . $minPpPower . " due to cold outside temperature, cold storage and according to current hc2TempDiff (" . $hc2TempDiff . ")";
        }

        // adjust hc1
        if ($outsideTemp < 1 && $hc2TempDiff < 10 && $insideTemp > $minInsideTemp - 1) {
            // it's really cold, limit hc1 to max. 60
            $hc1Limit = 60;
        } else {
            // it's not extremely cold, do not limit hc1
            $hc1Limit = 100;
        }
        $hc1 = 75;
        $ppPower = 100;
        if ($smartFoxHighPower) {
            $hc1 = min($hc1Limit, 150);
            $ppPower = 100;
            $log[] = "set hc1 to " . $hc1 . " due to high PV power, set ppPower to 100%";
        } elseif (!$energyLowRate) {
            // readout temperature forecast for the coming night
            $minTempNight = $this->openweathermap->getMinTempNextNightPeriod();
            if ($minTempNight < $outsideTemp - 5) {
                // night will be cold compared to current temp
                $hc1 = min($hc1Limit, 70);
                $ppPower = 50;
                $log[] = "set hc1 to 70 as night will be cold compared to current temp; set ppPower to 50%";
            } else {
                // night will not be cold compared to current temp
                $hc1 = min($hc1Limit, 60);
                $ppPower = 30;
                $log[] = "set hc1 to 60 as night will not be cold compared to current temp; set ppPower to 30%";
            }
            if ($hc2TempDiff < 1) {
                $ppPower -= 10;
                $log[] = "reduce ppPower by 10 due to hc2TempDiff < 1";
            }
            if ($hc2TempDiff < 0.5) {
                $ppPower -= 10;
                $log[] = "reduce ppPower by 10 due to hc2TempDiff < 0.5";
            }
        } else {
            // readout temperature forecast for the coming day
            $maxTempDay = $this->openweathermap->getMaxTempNextDaylightPeriod();
            if ($insideTemp > $minInsideTemp && ($maxTempDay > $outsideTemp + 8 || $avgClouds < 30)) {
                // day will be extremely warm compared to current temp or it will be sunny
                $hc1 = min($hc1Limit, 40);
                $ppPower = 20;
                $log[] = "set hc1 to 40 as day will be extremely warm compared to current temp or it will be sunny; set ppPower to 20%";
            } elseif ($maxTempDay > $outsideTemp + 5) {
                // day will be warm compared to current temp
                $hc1 = min($hc1Limit, 50);
                $ppPower = 30;
                $log[] = "set hc1 to 50 as day will be warm compared to current temp; set ppPower to 30%";
            } else {
                // day will not be warm compared to current temp
                $hc1 = min($hc1Limit, 70);
                $ppPower = 30;
                $log[] = "set hc1 to 70 as day will not be warm compared to current temp; set ppPower to 30%";
            }
            if ($hc2TempDiff < 0.5) {
                $ppPower -= 10;
                $log[] = "reduce ppPower by 10 due to hc2TempDiff < 0.5";
            }
        }
        if (!$energyLowRate) {
            // adjust hc1 and ppPower for high energy rate
            if ($avgPower < -800 || ($avgPvPower > 800 && $avgPower < 2000 && $wem['ppStatus'] != "Aus")) {
                $hc1 = 150;
                $ppPower = $ppLevel;
                if ($avgPower < 0 && $netPower < -200) {
                    $ppDelta = 3;
                    if ($avgPower < -1000 && $netPower < -1000) {
                        $ppDelta = 10;
                    } elseif ($avgPower < -500 && $netPower < -500) {
                        $ppDelta = 5;
                    }
                    $ppPower = min($ppLevel + $ppDelta, 100);
                } elseif ($avgPower > 0 && $netPower > 0) {
                    $ppPower = max($ppLevel - 10, 10);
                }
                $log[] = "increase hc1 = 150 due to negative energy during high energy rate; adjust ppPower to " . $ppPower . "%";
            }
        } else {
            // adjust hc1 and ppPower for low energy rate
            if ($avgPower < -400 || ($avgPvPower > 400 && $avgPower < 3000 && $wem['ppStatus'] != "Aus")) {
                $hc1 = 150;
                $ppPower = $ppLevel;
                if ($avgPower < 0 && $netPower < -200) {
                    $ppPower = min($ppLevel + 5, 100);
                } elseif ($avgPower > 100 && $netPower > 0) {
                    $ppPower = max($ppLevel - 10, 10);
                }
                $log[] = "increase hc1 = 150 due to negative energy during low energy rate; set ppPower to  " . $ppPower . "%";
            }
        }

        // adjust hc2
        if ($insideTemp > $minInsideTemp + 1) {
            // little warm inside
            $hc2 = 50;
            $log[] =  'little warm inside, set hc2 = 50';
        } elseif ($insideTemp > $minInsideTemp + 2) {
            // warm inside
            $hc2 = 40;
            $log[] =  'warm inside, set hc2 = 40';
        } elseif ($insideTemp > $minInsideTemp + 3) {
            // too hot inside
            $hc2 = 10;
            $log[] =  'too hot inside, set hc2 = 10';
        } elseif ($insideTemp < $minInsideTemp - 2) {
            // extremely cold inside
            $hc2 =  100;
            $log[] =  'extremely cold inside, set hc2 = 100';
        } elseif ($insideTemp < $minInsideTemp - 1) {
            // cold inside
            $hc2 = 85;
            $log[] =  'cold inside, set hc2 = 85';
        } elseif ($insideTemp < $minInsideTemp) {
            // little cold inside
            $hc2 = 70;
            $log[] =  'little cold inside, set hc2 = 70';
        } else {
            // perfect temperature inside
            $hc2 = 60;
            $log[] =  'perfect temperature inside set hc2 = 60';
        }

        // adjust hc1 for cold temperatures
        if ($insideTemp < $minInsideTemp -1 && $hc2TempDiff > 3) {
            $hc1 = max($hc1, $hc2);
            $log[] = "adjust hc1 to " . $hc1 . " due to low inside and high hc2TempDiff";
        }

        // set hc1
        $this->wem->executeCommand('hc1', $hc1);

        // set ppPower
        $newPpPower = min(100, max($minPpPower, $ppPower));
        if ($doWemPortal && $ppLevel != 0 && ($newPpPower >= $ppLevel + 3 || $newPpPower < $ppLevel - 3)) {
            $log[] = "send ppPower command to device: " . $newPpPower;
            $this->wem->executeCommand('ppPower', $newPpPower);
        }

        // set hc2
        $this->wem->executeCommand('hc2', $hc2);

        // close chromium
        $this->wem->close();

        $commandLog->setLog($log);
        $commandLog->setTimestamp(new \DateTime());
        $this->em->persist($commandLog);
        $this->em->flush();
    }

    public function initMystrom($deviceId = null)
    {
        if ($deviceId !== null) {
            $device = $this->mystrom->getConfig($deviceId);
            if ($device) {
                $mystrom = $this->mystrom->getOne($device);
                $this->mystrom->storeStatus($mystrom, $mystrom['status']);
            }
        } else {
            foreach ($this->mystrom->getAll() as $mystrom) {
                $this->mystrom->storeStatus($mystrom, $mystrom['status']);
            }
        }
    }

    public function initShelly($deviceId = null, $action = null) // $deviceId is a string in the form ip_port
    {
        if ($deviceId !== null) {
            $port = null;
            $ip_port = explode("_", $deviceId);
            $ip = $ip_port[0];
            if (count($ip_port) > 1) {
                $port = $ip_port[1];
            } else {
                $port = null;
            }
            $device = $this->shelly->getConfig($ip, $port);
            if ($device) {
                if ($action !== null) {
                    $shelly = $device;
                    $shelly['status'] = $this->shelly->createStatus($action);
                } else {
                    $shelly = $this->shelly->getOne($device);
                }
                if (!array_key_exists('port', $shelly)) {
                    $shelly['port'] = 0;
                }
                $shellyEntity = new ShellyDataStore();
                $shellyEntity->setTimestamp(new \DateTime('now'));
                $shellyEntity->setConnectorId($shelly['ip'].'_'.$shelly['port']);
                $shellyEntity->setData($shelly['status']);
                $this->em->persist($shellyEntity);
            }
        }
        else {
            foreach ($this->shelly->getAll() as $shelly) {
                if (!array_key_exists('port', $shelly)) {
                    $shelly['port'] = 0;
                }

                $shellyEntity = new ShellyDataStore();
                $shellyEntity->setTimestamp(new \DateTime('now'));
                $shellyEntity->setConnectorId($shelly['ip'].'_'.$shelly['port']);
                $shellyEntity->setData($shelly['status']);
                $this->em->persist($shellyEntity);
            }
        }
        $this->em->flush();
    }

    public function initSmartfox()
    {
        $smartfox = null;
        if ($this->smartfox->getIp()) {
            $smartfox = $this->smartfox->getAll();
            $smartfoxEntity = new SmartFoxDataStore();
            $smartfoxEntity->setTimestamp(new \DateTime('now'));
            $smartfoxEntity->setConnectorId($this->smartfox->getIp());
            if ($smartfox['PvEnergy'][0] <= 0) {
                $lastSmartFox = $this->smartfox->getAllLatest();
                if ($lastSmartFox) {
                    $smartfox['PvEnergy'][0] = $lastSmartFox['PvEnergy'][0];
                }
            }
            $smartfoxEntity->setData($smartfox);
            $this->em->persist($smartfoxEntity);
            $this->em->flush();
        }

        return $smartfox;
    }

    public function initConexio()
    {
        if ($this->conexio->getIp()) {
            $conexio = $this->conexio->getAll();
            if ($conexio) {
                // we only want to store valid and complete data
                $conexioEntity = new ConexioDataStore();
                $conexioEntity->setTimestamp(new \DateTime('now'));
                $conexioEntity->setConnectorId($this->conexio->getIp());
                $conexioEntity->setData($conexio);
                $this->em->persist($conexioEntity);
                $this->em->flush();
            }
        }
    }

    public function initLogo()
    {
        if ($this->logo->getIp()) {
            $logocontrol = $this->logo->getAll();
            if ($logocontrol) {
                // we only want to store valid and complete data
                $logocontrolEntity = new LogoControlDataStore();
                $logocontrolEntity->setTimestamp(new \DateTime('now'));
                $logocontrolEntity->setConnectorId($this->logo->getIp());
                $logocontrolEntity->setData($logocontrol);
                $this->em->persist($logocontrolEntity);
                $this->em->flush();
            }
        }
    }

    public function initPcoweb()
    {
        if ($this->pcoweb->getIp()) {
            $pcoweb = $this->pcoweb->getAll();
            if ($pcoweb !== false) {
                $pcowebEntity = new PcoWebDataStore();
                $pcowebEntity->setTimestamp(new \DateTime('now'));
                $pcowebEntity->setConnectorId($this->pcoweb->getIp());
                $pcowebEntity->setData($pcoweb);
                $this->em->persist($pcowebEntity);
                $this->em->flush();
            }
        }
    }

    public function initWem()
    {
        if ($this->wem->getUsername()) {
            $wem = $this->wem->getAll(false);
            if ($wem !== false) {
                $wemEntity = new WemDataStore();
                $wemEntity->setTimestamp(new \DateTime('now'));
                $wemEntity->setConnectorId($this->wem->getUsername());
                $wemEntity->setData($wem);
                $this->em->persist($wemEntity);
                $this->em->flush();
            }
        }
    }

    public function initMobilealerts()
    {
        if ($this->mobilealerts->getAvailable()) {
            foreach ($this->mobilealerts->getAll() as $sensorId => $sensorData) {
                $mobilealertsEntity = new MobileAlertsDataStore();
                $mobilealertsEntity->setTimestamp(new \DateTime('now'));
                $mobilealertsEntity->setConnectorId($sensorId);
                $mobilealertsEntity->setData($sensorData);
                $this->em->persist($mobilealertsEntity);
            }
            $this->em->flush();
        }
    }

    public function initNetatmo()
    {
        if ($this->netatmo->getAvailable()) {
            $sensorData = $this->netatmo->getAll();
            if ($sensorData != null) {
                $netatmoEntity = new NetatmoDataStore();
                $netatmoEntity->setTimestamp(new \DateTime('now'));
                $netatmoEntity->setConnectorId($this->netatmo->getId());
                $netatmoEntity->setData($sensorData);
                $this->em->persist($netatmoEntity);
                $this->em->flush();
            }
        }
    }

    public function initGardena()
    {
        if ($this->gardena->getAvailable()) {
            $this->gardena->updateDevices();
        }
    }

     public function initEcar()
    {
        foreach ($this->ecar->getAll() as $ecar) {
            if (is_array($ecar) && array_key_exists('data', $ecar) && is_array($ecar['data']) && array_key_exists('soc', $ecar['data']) && $ecar['data']['soc'] != '') {
                $ecarEntity = new EcarDataStore();
                $ecarEntity->setTimestamp(new \DateTime('now'));
                $ecarEntity->setConnectorId($ecar['carId']);
                $ecarEntity->setData($ecar);
                $this->em->persist($ecarEntity);
                $this->em->flush();
            }
        }
    }

    public function processAlarms()
    {
        $maAlarms = $this->mobilealerts->getAlarms();
        $msAlarms = $this->mystrom->getAlarms();
        $shAlarms = $this->shelly->getAlarms();
        $alarms = array_merge($maAlarms, $msAlarms, $shAlarms);
        if (count($alarms)) {
            $alarmSetting = $this->em->getRepository('App:Settings')->findOneByConnectorId('alarm');
            if(!$alarmSetting) {
                $alarmSetting = new Settings();
                $alarmSetting->setConnectorId('alarm');
                $alarmSetting->setMode(0);
            }
            if ($alarmSetting && $alarmSetting->getMode() == 1) {
                $alarmSetting->setMode(0);
                $this->em->flush();
                $alarmMsg = $this->translator->trans("label.alarm.active");
                foreach ($alarms as $alarm) {
                    $alarmMsg .= "\n" . $alarm['name'] . ": " . $this->translator->trans($alarm['state']);
                }
                foreach ($this->connectors['threema']['alarm'] as $alarmRecipient) {
                    $this->threema->sendMessage($alarmRecipient, $alarmMsg);
                }
            }
        }
    }

    public function configureDevices()
    {
        // currently only required and available for Shelly Sensors
        if (array_key_exists('shelly', $this->connectors)) {
            foreach ($this->connectors['shelly'] as $key => $device) {
                try {
                    $this->shelly->executeCommand($key, 100);
                } catch (\Exception $e) {
                    // do nothing
                }
            }
        }
    }
}
