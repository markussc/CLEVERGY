<?php

namespace AppBundle\Utils;

use AppBundle\Utils\Connectors\EdiMaxConnector;
use AppBundle\Utils\Connectors\MobileAlertsConnector;
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

    public function __construct(EntityManager $em, EdiMaxConnector $edimax, MobileAlertsConnector $mobilealerts)
    {
        $this->em = $em;
        $this->edimax = $edimax;
        $this->mobilealerts = $mobilealerts;
    }

    public function checkCondition($device)
    {
        $conf = $this->edimax->getConfig($device['ip']);
        if (isset($conf['forceOn'])) {
            $conditions = $conf['forceOn'];
            foreach ($conditions as $sensor => $condition) {
                $condArr = explode(':', $sensor);
                if ($condArr[0] == 'mobilealerts') {
                    $maData = $this->mobilealerts->getAllLatest();
                    $sensorData = $maData[$condArr[1]][$condArr[2]];
                    // check if > or < should be checked
                    if (strpos($condition, '>') !== false) {
                        // we have larger than condition
                        $condition = str_replace('>', '', $condition);
                        if ($sensorData['value'] > $condition) {
                            dump("force on gt");
                            return true;
                        }
                    } else {
                        // we have a smaller than condition
                        $condition = str_replace('<', '', $condition);
                        if ($sensorData['value'] < $condition) {
                            dump("force on st");
                            return true;
                        }
                    }
                }
            }
        }
        
        return false;
    }
}
