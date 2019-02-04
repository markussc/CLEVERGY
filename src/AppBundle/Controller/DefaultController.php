<?php

namespace AppBundle\Controller;

use AppBundle\Entity\Settings;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;

class DefaultController extends Controller
{
    /**
     * @Route("/", name="homepage")
     */
    public function indexAction(Request $request)
    {
        $activePage = "homepage";
        if ($request->query->get("details")) {
            $activePage = "details";
        }
        $currentStat = [
            'smartFox' => $this->get('AppBundle\Utils\Connectors\SmartFoxConnector')->getAllLatest(),
            'smartFoxChart' => true,
            'pcoWeb' => $this->get('AppBundle\Utils\Connectors\PcoWebConnector')->getAllLatest(),
            'conexio' => $this->get('AppBundle\Utils\Connectors\ConexioConnector')->getAllLatest(),
            'mobileAlerts' => $this->get('AppBundle\Utils\Connectors\MobileAlertsConnector')->getAllLatest(),
            'edimax' => $this->get('AppBundle\Utils\Connectors\EdiMaxConnector')->getAllLatest(),
            'openweathermap' => $this->get('AppBundle\Utils\Connectors\OpenWeatherMapConnector')->getAllLatest(),
        ];

        $em = $this->getDoctrine()->getManager();

        $mobileAlertsHistory = [];
        foreach ($this->getParameter('connectors')['mobilealerts']['sensors'] as $sensorId => $mobileAlertsSensor) {
            $mobileAlertsHistory[$sensorId] = $em->getRepository('AppBundle:MobileAlertsDataStore')->getHistoryLast24h($sensorId);
        }

        $history = [
            'smartFox' => $em->getRepository('AppBundle:SmartFoxDataStore')->getHistoryLast24h($this->get('AppBundle\Utils\Connectors\SmartFoxConnector')->getIp()),
            'pcoWeb' => $em->getRepository('AppBundle:PcoWebDataStore')->getHistoryLast24h($this->get('AppBundle\Utils\Connectors\PcoWebConnector')->getIp()),
            'conexio' => $em->getRepository('AppBundle:ConexioDataStore')->getHistoryLast24h($this->get('AppBundle\Utils\Connectors\ConexioConnector')->getIp()),
        ];
        $history['mobileAlerts'] = $mobileAlertsHistory;

        // render the template
        return $this->render('default/index.html.twig', [
            'activePage' => $activePage,
            'currentStat' => $currentStat,
            'history' => $history,
        ]);
    }

    /**
     * Execute command
     * @Route("/cmd/{command}", name="command_exec")
     */
    public function commandExecuteAction(Request $request, $command)
    {
        // only owners are allowed to execute commands
        $this->denyAccessUnlessGranted('ROLE_OWNER');

        // execute the command
        $this->executeCommand($command);
        // redirect to homepage
        return $this->redirect($request->headers->get('referer'));
    }

    private function executeCommand($jsonCommand)
    {
        $command = json_decode($jsonCommand);
        switch ($command[0]) {
            case 'edimax':
                return $this->get('AppBundle\Utils\Connectors\EdiMaxConnector')->executeCommand($command[1], $command[2]);
            case 'pcoweb':
                return $this->get('AppBundle\Utils\Connectors\PcoWebConnector')->executeCommand($command[1], $command[2]);
            case 'settings':
                if ($command[1] == 'mode') {
                    if ($command[2] == 'pcoweb') {
                        $connectorId = $this->get('AppBundle\Utils\Connectors\PcoWebConnector')->getIp();
                    } else {
                        $connectorId = $command[2];
                    }
                    $settings = $this->getDoctrine()->getManager()->getRepository('AppBundle:Settings')->findOneByConnectorId($connectorId);
                    if (!$settings) {
                        $settings = new Settings();
                        $settings->setConnectorId($connectorId);
                        $this->getDoctrine()->getManager()->persist($settings);
                    }
                    $settings->setMode($command[3]);

                    $this->getDoctrine()->getManager()->flush();
                    return true;
                }
            case 'command':
                $connectors = $this->getParameter('connectors');
                exec($connectors['command'][$command[1]]['cmd']);
                return true;
        }
        // no known device
        return false;
    }

    /**
     * Get data which needs regular refresh
     * @Route("/refresh", name="refresh")
     */
    public function refreshAction(Request $request)
    {
        $currentStat = [
            'smartFox' => $this->get('AppBundle\Utils\Connectors\SmartFoxConnector')->getAll(true),
            'pcoWeb' => $this->get('AppBundle\Utils\Connectors\PcoWebConnector')->getAllLatest(),
            'conexio' => $this->get('AppBundle\Utils\Connectors\ConexioConnector')->getAll(true),
            'edimax' => $this->get('AppBundle\Utils\Connectors\EdiMaxConnector')->getAll(),
            'mobileAlerts' => $this->get('AppBundle\Utils\Connectors\MobileAlertsConnector')->getAllLatest(),
            'openweathermap' => $this->get('AppBundle\Utils\Connectors\OpenWeatherMapConnector')->getAllLatest(),
        ];

        $template = "default/contentHomepage.html.twig";
        if ($request->query->get("details")) {
            $template = "default/contentDetails.html.twig";
        }
        
        // render the template
        return $this->render($template, [
            'currentStat' => $currentStat,
            'refresh' => true,
        ]);
    }

    /**
     * @Route("/history", name="history")
     */
    public function historyAction(Request $request)
    {
        $em = $this->getDoctrine()->getManager();
        $yesterday = new \DateTime('yesterday');
        $now = new \DateTime('now');
        $today = new \DateTime('today');
        $thisWeek = new \DateTime('monday this week midnight');
        $thisMonth = new \DateTime('first day of this month midnight');
        $thisYear = new \DateTime('first day of january this year midnight');
        $lastYear = new \DateTime('first day of january last year midnight');

        $history = [
            'intervals' => [
                'today',
                'yesterday',
                'week',
                'month',
                'year',
                'lastYear',
            ],
            'smartfox' => [
                'pv_today' => $em->getRepository('AppBundle:SmartFoxDataStore')->getEnergyInterval($this->get('AppBundle\Utils\Connectors\SmartFoxConnector')->getIp(), 'PvEnergy', $today, $now),
                'pv_yesterday' => $em->getRepository('AppBundle:SmartFoxDataStore')->getEnergyInterval($this->get('AppBundle\Utils\Connectors\SmartFoxConnector')->getIp(), 'PvEnergy', $yesterday, $today),
                'pv_week' => $em->getRepository('AppBundle:SmartFoxDataStore')->getEnergyInterval($this->get('AppBundle\Utils\Connectors\SmartFoxConnector')->getIp(), 'PvEnergy', $thisWeek, $now),
                'pv_month' => $em->getRepository('AppBundle:SmartFoxDataStore')->getEnergyInterval($this->get('AppBundle\Utils\Connectors\SmartFoxConnector')->getIp(), 'PvEnergy', $thisMonth, $now),
                'pv_year' => $em->getRepository('AppBundle:SmartFoxDataStore')->getEnergyInterval($this->get('AppBundle\Utils\Connectors\SmartFoxConnector')->getIp(), 'PvEnergy', $thisYear, $now),
                'pv_lastYear' => $em->getRepository('AppBundle:SmartFoxDataStore')->getEnergyInterval($this->get('AppBundle\Utils\Connectors\SmartFoxConnector')->getIp(), 'PvEnergy', $lastYear, $thisYear),
                'energy_in_today' => $em->getRepository('AppBundle:SmartFoxDataStore')->getEnergyInterval($this->get('AppBundle\Utils\Connectors\SmartFoxConnector')->getIp(), 'energy_in', $today, $now),
                'energy_out_today' => $em->getRepository('AppBundle:SmartFoxDataStore')->getEnergyInterval($this->get('AppBundle\Utils\Connectors\SmartFoxConnector')->getIp(), 'energy_out', $today, $now),
                'energy_in_yesterday' => $em->getRepository('AppBundle:SmartFoxDataStore')->getEnergyInterval($this->get('AppBundle\Utils\Connectors\SmartFoxConnector')->getIp(), 'energy_in', $yesterday, $today),
                'energy_out_yesterday' => $em->getRepository('AppBundle:SmartFoxDataStore')->getEnergyInterval($this->get('AppBundle\Utils\Connectors\SmartFoxConnector')->getIp(), 'energy_out', $yesterday, $today),
                'energy_in_week' => $em->getRepository('AppBundle:SmartFoxDataStore')->getEnergyInterval($this->get('AppBundle\Utils\Connectors\SmartFoxConnector')->getIp(), 'energy_in', $thisWeek, $now),
                'energy_out_week' => $em->getRepository('AppBundle:SmartFoxDataStore')->getEnergyInterval($this->get('AppBundle\Utils\Connectors\SmartFoxConnector')->getIp(), 'energy_out', $thisWeek, $now),
                'energy_in_month' => $em->getRepository('AppBundle:SmartFoxDataStore')->getEnergyInterval($this->get('AppBundle\Utils\Connectors\SmartFoxConnector')->getIp(), 'energy_in', $thisMonth, $now),
                'energy_out_month' => $em->getRepository('AppBundle:SmartFoxDataStore')->getEnergyInterval($this->get('AppBundle\Utils\Connectors\SmartFoxConnector')->getIp(), 'energy_out', $thisMonth, $now),
                'energy_in_year' => $em->getRepository('AppBundle:SmartFoxDataStore')->getEnergyInterval($this->get('AppBundle\Utils\Connectors\SmartFoxConnector')->getIp(), 'energy_in', $thisYear, $now),
                'energy_out_year' => $em->getRepository('AppBundle:SmartFoxDataStore')->getEnergyInterval($this->get('AppBundle\Utils\Connectors\SmartFoxConnector')->getIp(), 'energy_out', $thisYear, $now),
                'energy_in_lastYear' => $em->getRepository('AppBundle:SmartFoxDataStore')->getEnergyInterval($this->get('AppBundle\Utils\Connectors\SmartFoxConnector')->getIp(), 'energy_in', $lastYear, $thisYear),
                'energy_out_lastYear' => $em->getRepository('AppBundle:SmartFoxDataStore')->getEnergyInterval($this->get('AppBundle\Utils\Connectors\SmartFoxConnector')->getIp(), 'energy_out', $lastYear, $thisYear),
            ],
            'conexio' => [
                'energy_today' => $em->getRepository('AppBundle:ConexioDataStore')->getEnergyInterval($this->get('AppBundle\Utils\Connectors\ConexioConnector')->getIp(), $today, $now),
                'energy_yesterday' => $em->getRepository('AppBundle:ConexioDataStore')->getEnergyInterval($this->get('AppBundle\Utils\Connectors\ConexioConnector')->getIp(), $yesterday, $today),
                'energy_week' => $em->getRepository('AppBundle:ConexioDataStore')->getEnergyInterval($this->get('AppBundle\Utils\Connectors\ConexioConnector')->getIp(), $thisWeek, $now),
                'energy_month' => $em->getRepository('AppBundle:ConexioDataStore')->getEnergyInterval($this->get('AppBundle\Utils\Connectors\ConexioConnector')->getIp(), $thisMonth, $now),
                'energy_year' => $em->getRepository('AppBundle:ConexioDataStore')->getEnergyInterval($this->get('AppBundle\Utils\Connectors\ConexioConnector')->getIp(), $thisYear, $now),
                'energy_lastYear' => $em->getRepository('AppBundle:ConexioDataStore')->getEnergyInterval($this->get('AppBundle\Utils\Connectors\ConexioConnector')->getIp(), $lastYear, $thisYear),
            ],
        ];

        // render the template
        return $this->render('default/history.html.twig', [
            'activePage' => 'history',
            'history' => $history,
        ]);
    }
}
