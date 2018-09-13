<?php

namespace AppBundle\Utils;

use AppBundle\Utils\Connectors\EdiMaxConnector;
use AppBundle\Utils\Connectors\MobileAlertsConnector;
use AppBundle\Utils\Connectors\PcoWebConnector;
use Doctrine\ORM\EntityManager;

/**
 *
 * @author Markus Schafroth
 */
class ConditionChecker
{
    protected $em;
    protected $edimax;
    protected $mobilealerts;

    public function __construct(EntityManager $em, EdiMaxConnector $edimax, MobileAlertsConnector $mobilealerts, PcoWebConnector $pcoweb, $energyLowRate)
    {
        $this->em = $em;
        $this->edimax = $edimax;
        $this->mobilealerts = $mobilealerts;
        $this->pcoweb = $pcoweb;
        $this->energyLowRate = $energyLowRate;
    }

    public function checkCondition($device)
    {
        $conf = $this->edimax->getConfig($device['ip']);

        // check for force conditions for all energy rates
        if (isset($conf['forceOn'])) {
            if ($this->processConditions($conf['forceOn'])) {
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
                        return true;
                    }
                } else {
                    // we have a smaller than condition
                    $condition = str_replace('<', '', $condition);
                    if (floatval($sensorData['value']) < floatval($condition)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }
}
