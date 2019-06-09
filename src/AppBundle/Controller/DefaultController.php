<?php

namespace AppBundle\Controller;

use AppBundle\Entity\Settings;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

class DefaultController extends Controller
{
    /**
     * @Route("/", name="homepage")
     */
    public function indexAction(Request $request)
    {
        $clientIp = $request->getClientIp();
        $authenticatedIps = $this->getParameter('authenticated_ips');
        $params = $request->query->all();
        $securityContext = $this->container->get('security.authorization_checker');
        if (!$securityContext->isGranted('IS_AUTHENTICATED_REMEMBERED') && array_key_exists($clientIp, $authenticatedIps)) {
            $route = $request->get('route');
            $em = $this->getDoctrine()->getManager();
            $user = $em->getRepository('AppBundle:User')->findOneBy(array('email' => $authenticatedIps[$clientIp]));
            if ($user) {
                $token = new UsernamePasswordToken($user, $user->getPassword(), "xinstance", $user->getRoles());
                $this->get('security.token_storage')->setToken($token);
                if ($route) {
                    return $this->redirectToRoute($route, $params);
                }
            }
        }

        return $this->redirectToRoute('overview', $params);
    }

    /**
     * @Route("/overview", name="overview")
     */
    public function overviewAction(Request $request)
    {
        $activePage = "overview";
        if ($request->query->get("details")) {
            $activePage = "details";
        }
        $currentStat = [];
        if (array_key_exists('smartfox', $this->getParameter('connectors'))) {
            $currentStat['smartFox'] = $this->get('AppBundle\Utils\Connectors\SmartFoxConnector')->getAllLatest();
            $currentStat['smartFoxChart'] = true;
        }
        if (array_key_exists('pcoweb', $this->getParameter('connectors'))) {
            $currentStat['pcoWeb'] = $this->get('AppBundle\Utils\Connectors\PcoWebConnector')->getAllLatest();
        }
        if (array_key_exists('conexio', $this->getParameter('connectors'))) {
            $currentStat['conexio'] = $this->get('AppBundle\Utils\Connectors\ConexioConnector')->getAllLatest();
        }
        if (array_key_exists('mobileAlerts', $this->getParameter('connectors'))) {
            $currentStat['mobileAlerts'] = $this->get('AppBundle\Utils\Connectors\MobileAlertsConnector')->getAllLatest();
        }
        if (array_key_exists('edimax', $this->getParameter('connectors'))) {
            $currentStat['edimax'] = $this->get('AppBundle\Utils\Connectors\EdiMaxConnector')->getAllLatest();
        }
        if (array_key_exists('mystrom', $this->getParameter('connectors'))) {
            $currentStat['mystrom'] = $this->get('AppBundle\Utils\Connectors\MyStromConnector')->getAllLatest();
        }
        if (array_key_exists('openweathermap', $this->getParameter('connectors'))) {
            $currentStat['openweathermap'] = $this->get('AppBundle\Utils\Connectors\OpenWeatherMapConnector')->getAllLatest();
        }

        $em = $this->getDoctrine()->getManager();

        $history = [];
        if (array_key_exists('mobilealerts', $this->getParameter('connectors')) && is_array($this->getParameter('connectors')['mobilealerts']['sensors'])) {
            $mobileAlertsHistory = [];
            foreach ($this->getParameter('connectors')['mobilealerts']['sensors'] as $sensorId => $mobileAlertsSensor) {
                $mobileAlertsHistory[$sensorId] = $em->getRepository('AppBundle:MobileAlertsDataStore')->getHistoryLast24h($sensorId);
            }
            $history['mobileAlerts'] = $mobileAlertsHistory;
        }
        if (array_key_exists('smartfox', $this->getParameter('connectors'))) {
            $history['smartFox'] = $em->getRepository('AppBundle:SmartFoxDataStore')->getHistoryLast24h($this->get('AppBundle\Utils\Connectors\SmartFoxConnector')->getIp());
        }
        if (array_key_exists('pcoweb', $this->getParameter('connectors'))) {
            $history['pcoWeb'] = $em->getRepository('AppBundle:PcoWebDataStore')->getHistoryLast24h($this->get('AppBundle\Utils\Connectors\PcoWebConnector')->getIp());
        }
        if (array_key_exists('conexio', $this->getParameter('connectors'))) {
            $history['conexio'] = $em->getRepository('AppBundle:ConexioDataStore')->getHistoryLast24h($this->get('AppBundle\Utils\Connectors\ConexioConnector')->getIp());
        }

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
        $success = $this->executeCommand($command);

        return new JsonResponse(['success' => $success]);
    }

    private function executeCommand($jsonCommand)
    {
        $command = json_decode($jsonCommand);
        switch ($command[0]) {
            case 'edimax':
                return $this->get('AppBundle\Utils\Connectors\EdiMaxConnector')->executeCommand($command[1], $command[2]);
            case 'mystrom':
                return $this->get('AppBundle\Utils\Connectors\MyStromConnector')->executeCommand($command[1], $command[2]);
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
        $currentStat = [];
        if (array_key_exists('smartfox', $this->getParameter('connectors'))) {
            $currentStat['smartFox'] = $this->get('AppBundle\Utils\Connectors\SmartFoxConnector')->getAll(true);
        }
        if (array_key_exists('pcoweb', $this->getParameter('connectors'))) {
            $currentStat['pcoWeb'] = $this->get('AppBundle\Utils\Connectors\PcoWebConnector')->getAllLatest();
        }
        if (array_key_exists('conexio', $this->getParameter('connectors'))) {
            $currentStat['conexio'] = $this->get('AppBundle\Utils\Connectors\ConexioConnector')->getAll(true);
        }
        if (array_key_exists('edimax', $this->getParameter('connectors'))) {
            $currentStat['edimax'] = $this->get('AppBundle\Utils\Connectors\EdiMaxConnector')->getAll();
        }
        if (array_key_exists('mystrom', $this->getParameter('connectors'))) {
            $currentStat['mystrom'] = $this->get('AppBundle\Utils\Connectors\MyStromConnector')->getAll();
        }
        if (array_key_exists('mobilealerts', $this->getParameter('connectors'))) {
            $currentStat['mobileAlerts'] = $this->get('AppBundle\Utils\Connectors\MobileAlertsConnector')->getAllLatest();
        }
        if (array_key_exists('openweathermap', $this->getParameter('connectors'))) {
            $currentStat['openweathermap'] = $this->get('AppBundle\Utils\Connectors\OpenWeatherMapConnector')->getAllLatest();
        }

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

        $history['intervals'] = [
            'today',
            'yesterday',
            'week',
            'month',
            'year',
            'lastYear',
        ];
        if (array_key_exists('smartfox', $this->getParameter('connectors'))) {
            $history['smartfox'] = [
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
            ];
        }
        if (array_key_exists('conexio', $this->getParameter('connectors'))) {
            $history['conexio'] = [
                'energy_today' => $em->getRepository('AppBundle:ConexioDataStore')->getEnergyInterval($this->get('AppBundle\Utils\Connectors\ConexioConnector')->getIp(), $today, $now),
                'energy_yesterday' => $em->getRepository('AppBundle:ConexioDataStore')->getEnergyInterval($this->get('AppBundle\Utils\Connectors\ConexioConnector')->getIp(), $yesterday, $today),
                'energy_week' => $em->getRepository('AppBundle:ConexioDataStore')->getEnergyInterval($this->get('AppBundle\Utils\Connectors\ConexioConnector')->getIp(), $thisWeek, $now),
                'energy_month' => $em->getRepository('AppBundle:ConexioDataStore')->getEnergyInterval($this->get('AppBundle\Utils\Connectors\ConexioConnector')->getIp(), $thisMonth, $now),
                'energy_year' => $em->getRepository('AppBundle:ConexioDataStore')->getEnergyInterval($this->get('AppBundle\Utils\Connectors\ConexioConnector')->getIp(), $thisYear, $now),
                'energy_lastYear' => $em->getRepository('AppBundle:ConexioDataStore')->getEnergyInterval($this->get('AppBundle\Utils\Connectors\ConexioConnector')->getIp(), $lastYear, $thisYear),
            ];
        }

        // render the template
        return $this->render('default/history.html.twig', [
            'activePage' => 'history',
            'history' => $history,
        ]);
    }
}
