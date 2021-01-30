<?php

namespace App\Controller;

use App\Utils\Connectors\WemConnector;
use App\Entity\Settings;
use App\Utils\LogicProcessor;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
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
            $user = $em->getRepository('App:User')->findOneBy(array('email' => $authenticatedIps[$clientIp]));
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
        $history = [];
        $currentStat = [];
        if ($request->query->get("details")) {
            $activePage = "details";
            $em = $this->getDoctrine()->getManager();
            // get current values
            if (array_key_exists('smartfox', $this->getParameter('connectors'))) {
                $currentStat['smartFox'] = $this->get('App\Utils\Connectors\SmartFoxConnector')->getAllLatest();
                $currentStat['smartFoxChart'] = true;
            }
            if (array_key_exists('pcoweb', $this->getParameter('connectors'))) {
                $currentStat['pcoWeb'] = $this->get('App\Utils\Connectors\PcoWebConnector')->getAllLatest();
            } elseif (array_key_exists('wem', $this->getParameter('connectors'))) {
                $currentStat['pcoWeb'] = $this->get('App\Utils\Connectors\WemConnector')->getAllLatest(); // we store the wem data to the pcoWeb data structure for simplicity
            }
            if (array_key_exists('conexio', $this->getParameter('connectors'))) {
                $currentStat['conexio'] = $this->get('App\Utils\Connectors\ConexioConnector')->getAllLatest();
            }
            if (array_key_exists('mobilealerts', $this->getParameter('connectors'))) {
                $currentStat['mobileAlerts'] = $this->get('App\Utils\Connectors\MobileAlertsConnector')->getAllLatest();
            }
            if (array_key_exists('edimax', $this->getParameter('connectors'))) {
                $currentStat['edimax'] = $this->get('App\Utils\Connectors\EdiMaxConnector')->getAllLatest();
            }
            if (array_key_exists('mystrom', $this->getParameter('connectors'))) {
                $currentStat['mystrom'] = $this->get('App\Utils\Connectors\MyStromConnector')->getAllLatest();
            }
            if (array_key_exists('shelly', $this->getParameter('connectors'))) {
                $currentStat['shelly'] = $this->get('App\Utils\Connectors\ShellyConnector')->getAllLatest();
            }
            if (array_key_exists('openweathermap', $this->getParameter('connectors'))) {
                $currentStat['openweathermap'] = $this->get('App\Utils\Connectors\OpenWeatherMapConnector')->getAllLatest();
            }
            if (array_key_exists('logocontrol', $this->getParameter('connectors'))) {
                $currentStat['logoControl'] = $this->get('App\Utils\Connectors\LogoControlConnector')->getAllLatest();
            }
            if (array_key_exists('netatmo', $this->getParameter('connectors'))) {
                $currentStat['netatmo'] = $this->get('App\Utils\Connectors\NetatmoConnector')->getAllLatest();
            }

            // get history
            if (array_key_exists('mobilealerts', $this->getParameter('connectors')) && is_array($this->getParameter('connectors')['mobilealerts']['sensors'])) {
                $mobileAlertsHistory = [];
                foreach ($this->getParameter('connectors')['mobilealerts']['sensors'] as $sensorId => $mobileAlertsSensor) {
                    $mobileAlertsHistory[$sensorId] = $em->getRepository('App:MobileAlertsDataStore')->getHistoryLast24h($sensorId);
                }
                $history['mobileAlerts'] = $mobileAlertsHistory;
            }
            if (array_key_exists('netatmo', $this->getParameter('connectors'))) {
                $history['netatmo'] = $em->getRepository('App:NetatmoDataStore')->getHistoryLast24h($this->get('App\Utils\Connectors\NetatmoConnector')->getId());
            }
            if (array_key_exists('smartfox', $this->getParameter('connectors'))) {
                $history['smartFox'] = $em->getRepository('App:SmartFoxDataStore')->getHistoryLast24h($this->get('App\Utils\Connectors\SmartFoxConnector')->getIp());
            }
            if (array_key_exists('pcoweb', $this->getParameter('connectors'))) {
                $history['pcoWeb'] = $em->getRepository('App:PcoWebDataStore')->getHistoryLast24h($this->get('App\Utils\Connectors\PcoWebConnector')->getIp());
            } elseif (array_key_exists('wem', $this->getParameter('connectors'))) {
                $history['pcoWeb'] = $em->getRepository('App:WemDataStore')->getHistoryLast24h($this->get('App\Utils\Connectors\WemConnector')->getUsername()); // we store the wem data to the pcoWeb data structure for simplicity
            }
            if (array_key_exists('conexio', $this->getParameter('connectors'))) {
                $history['conexio'] = $em->getRepository('App:ConexioDataStore')->getHistoryLast24h($this->get('App\Utils\Connectors\ConexioConnector')->getIp());
            }
            if (array_key_exists('logocontrol', $this->getParameter('connectors'))) {
                $history['logoControl'] = $em->getRepository('App:LogoControlDataStore')->getHistoryLast24h($this->get('App\Utils\Connectors\LogoControlConnector')->getIp());
            }
        } else {
            $currentStat = $this->getCurrentStat([
                'edimax' => true,
                'mystrom' => true,
                'shelly' => true,
                'pcoweb' => true,
                'wem' => true,
                'openweathermap' => true,
                'mobilealerts' => true,
                'netatmo' => true,
            ]);
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
                return $this->get('App\Utils\Connectors\EdiMaxConnector')->executeCommand($command[1], $command[2]);
            case 'mystrom':
                return $this->get('App\Utils\Connectors\MyStromConnector')->executeCommand($command[1], $command[2]);
            case 'shelly':
                return $this->get('App\Utils\Connectors\ShellyConnector')->executeCommand($command[1], $command[2]);
            case 'pcoweb':
                return $this->get('App\Utils\Connectors\PcoWebConnector')->executeCommand($command[1], $command[2]);
            case 'wem':
                return $this->get('App\Utils\Connectors\WemConnector')->executeCommand($command[1], $command[2]);
            case 'settings':
                if ($command[1] == 'mode') {
                    if ($command[2] == 'pcoweb') {
                        $connectorId = $this->get('App\Utils\Connectors\PcoWebConnector')->getIp();
                    } elseif ($command[2] == 'wem') {
                        $connectorId = $this->get('App\Utils\Connectors\WemConnector')->getUsername();
                    } else {
                        $connectorId = $command[2];
                    }
                    if ($connectorId == 'alarm')
                    {
                        // make sure all mystrom PIR devices have their action URL set correctly
                        $this->get('App\Utils\Connectors\MyStromConnector')->activateAllPIR();
                    }
                    $settings = $this->getDoctrine()->getManager()->getRepository('App:Settings')->findOneByConnectorId($connectorId);
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

        $template = "default/contentHomepage.html.twig";
        if ($request->query->get("details")) {
            $template = "default/contentDetails.html.twig";
            $currentStat = $this->getCurrentStat(true);
        } else {
            $currentStat = $this->getCurrentStat([
                'edimax' => true,
                'mystrom' => true,
                'shelly' => true,
                'openweathermap' => true,
                'mobilealerts' => true,
            ]);
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
        $lastYearMonth = new \DateTime('first day of this month midnight');
        $lastYearMonth = $lastYearMonth->sub(new \DateInterval('P1Y'));
        $thisYear = new \DateTime('first day of january this year midnight');
        $lastYearPart = new \DateTime();
        $lastYearPart = $lastYearPart->sub(new \DateInterval('P1Y'));
        $lastYear = new \DateTime('first day of january last year midnight');

        $history['intervals'] = [
            'today',
            'yesterday',
            'week',
            'month',
            'lastYearMonth',
            'year',
            'lastYearPart',
            'lastYear',
        ];
        if (array_key_exists('smartfox', $this->getParameter('connectors'))) {
            $history['smartfox'] = [
                'pv_today' => $em->getRepository('App:SmartFoxDataStore')->getEnergyInterval($this->get('App\Utils\Connectors\SmartFoxConnector')->getIp(), 'PvEnergy', $today, $now),
                'pv_yesterday' => $em->getRepository('App:SmartFoxDataStore')->getEnergyInterval($this->get('App\Utils\Connectors\SmartFoxConnector')->getIp(), 'PvEnergy', $yesterday, $today),
                'pv_week' => $em->getRepository('App:SmartFoxDataStore')->getEnergyInterval($this->get('App\Utils\Connectors\SmartFoxConnector')->getIp(), 'PvEnergy', $thisWeek, $now),
                'pv_month' => $em->getRepository('App:SmartFoxDataStore')->getEnergyInterval($this->get('App\Utils\Connectors\SmartFoxConnector')->getIp(), 'PvEnergy', $thisMonth, $now),
                'pv_lastYearMonth' => $em->getRepository('App:SmartFoxDataStore')->getEnergyInterval($this->get('App\Utils\Connectors\SmartFoxConnector')->getIp(), 'PvEnergy', $lastYearMonth, $lastYearPart),
                'pv_year' => $em->getRepository('App:SmartFoxDataStore')->getEnergyInterval($this->get('App\Utils\Connectors\SmartFoxConnector')->getIp(), 'PvEnergy', $thisYear, $now),
                'pv_lastYearPart' => $em->getRepository('App:SmartFoxDataStore')->getEnergyInterval($this->get('App\Utils\Connectors\SmartFoxConnector')->getIp(), 'PvEnergy', $lastYear, $lastYearPart),
                'pv_lastYear' => $em->getRepository('App:SmartFoxDataStore')->getEnergyInterval($this->get('App\Utils\Connectors\SmartFoxConnector')->getIp(), 'PvEnergy', $lastYear, $thisYear),
                'energy_in_today' => $em->getRepository('App:SmartFoxDataStore')->getEnergyInterval($this->get('App\Utils\Connectors\SmartFoxConnector')->getIp(), 'energy_in', $today, $now),
                'energy_in_today_highrate' => $em->getRepository('App:SmartFoxDataStore')->getEnergyIntervalHighRate($this->get('App\Utils\Connectors\SmartFoxConnector')->getIp(), 'energy_in', $this->getParameter('energy_low_rate'), $today, $now),
                'energy_out_today' => $em->getRepository('App:SmartFoxDataStore')->getEnergyInterval($this->get('App\Utils\Connectors\SmartFoxConnector')->getIp(), 'energy_out', $today, $now),
                'energy_in_yesterday' => $em->getRepository('App:SmartFoxDataStore')->getEnergyInterval($this->get('App\Utils\Connectors\SmartFoxConnector')->getIp(), 'energy_in', $yesterday, $today),
                'energy_in_yesterday_highrate' => $em->getRepository('App:SmartFoxDataStore')->getEnergyIntervalHighRate($this->get('App\Utils\Connectors\SmartFoxConnector')->getIp(), 'energy_in', $this->getParameter('energy_low_rate'), $yesterday, $today),
                'energy_out_yesterday' => $em->getRepository('App:SmartFoxDataStore')->getEnergyInterval($this->get('App\Utils\Connectors\SmartFoxConnector')->getIp(), 'energy_out', $yesterday, $today),
                'energy_in_week' => $em->getRepository('App:SmartFoxDataStore')->getEnergyInterval($this->get('App\Utils\Connectors\SmartFoxConnector')->getIp(), 'energy_in', $thisWeek, $now),
                'energy_in_week_highrate' => $em->getRepository('App:SmartFoxDataStore')->getEnergyIntervalHighRate($this->get('App\Utils\Connectors\SmartFoxConnector')->getIp(), 'energy_in', $this->getParameter('energy_low_rate'), $thisWeek, $now),
                'energy_out_week' => $em->getRepository('App:SmartFoxDataStore')->getEnergyInterval($this->get('App\Utils\Connectors\SmartFoxConnector')->getIp(), 'energy_out', $thisWeek, $now),
                'energy_in_month' => $em->getRepository('App:SmartFoxDataStore')->getEnergyInterval($this->get('App\Utils\Connectors\SmartFoxConnector')->getIp(), 'energy_in', $thisMonth, $now),
                'energy_in_month_highrate' => $em->getRepository('App:SmartFoxDataStore')->getEnergyIntervalHighRate($this->get('App\Utils\Connectors\SmartFoxConnector')->getIp(), 'energy_in', $this->getParameter('energy_low_rate'), $thisMonth, $now),
                'energy_out_month' => $em->getRepository('App:SmartFoxDataStore')->getEnergyInterval($this->get('App\Utils\Connectors\SmartFoxConnector')->getIp(), 'energy_out', $thisMonth, $now),
                'energy_in_lastYearMonth' => $em->getRepository('App:SmartFoxDataStore')->getEnergyInterval($this->get('App\Utils\Connectors\SmartFoxConnector')->getIp(), 'energy_in', $lastYearMonth, $lastYearPart),
                'energy_in_lastYearMonth_highrate' => $em->getRepository('App:SmartFoxDataStore')->getEnergyIntervalHighRate($this->get('App\Utils\Connectors\SmartFoxConnector')->getIp(), 'energy_in', $this->getParameter('energy_low_rate'), $lastYearMonth, $lastYearPart),
                'energy_out_lastYearMonth' => $em->getRepository('App:SmartFoxDataStore')->getEnergyInterval($this->get('App\Utils\Connectors\SmartFoxConnector')->getIp(), 'energy_out', $lastYearMonth, $lastYearPart),
                'energy_in_year' => $em->getRepository('App:SmartFoxDataStore')->getEnergyInterval($this->get('App\Utils\Connectors\SmartFoxConnector')->getIp(), 'energy_in', $thisYear, $now),
                'energy_in_year_highrate' => $em->getRepository('App:SmartFoxDataStore')->getEnergyIntervalHighRate($this->get('App\Utils\Connectors\SmartFoxConnector')->getIp(), 'energy_in', $this->getParameter('energy_low_rate'), $thisYear, $now),
                'energy_out_year' => $em->getRepository('App:SmartFoxDataStore')->getEnergyInterval($this->get('App\Utils\Connectors\SmartFoxConnector')->getIp(), 'energy_out', $thisYear, $now),
                'energy_in_lastYearPart' => $em->getRepository('App:SmartFoxDataStore')->getEnergyInterval($this->get('App\Utils\Connectors\SmartFoxConnector')->getIp(), 'energy_in', $lastYear, $lastYearPart),
                'energy_in_lastYearPart_highrate' => $em->getRepository('App:SmartFoxDataStore')->getEnergyIntervalHighRate($this->get('App\Utils\Connectors\SmartFoxConnector')->getIp(), 'energy_in', $this->getParameter('energy_low_rate'), $lastYear, $lastYearPart),
                'energy_out_lastYearPart' => $em->getRepository('App:SmartFoxDataStore')->getEnergyInterval($this->get('App\Utils\Connectors\SmartFoxConnector')->getIp(), 'energy_out', $lastYear, $lastYearPart),
                'energy_in_lastYear' => $em->getRepository('App:SmartFoxDataStore')->getEnergyInterval($this->get('App\Utils\Connectors\SmartFoxConnector')->getIp(), 'energy_in', $lastYear, $thisYear),
                'energy_in_lastYear_highrate' => $em->getRepository('App:SmartFoxDataStore')->getEnergyIntervalHighRate($this->get('App\Utils\Connectors\SmartFoxConnector')->getIp(), 'energy_in', $this->getParameter('energy_low_rate'), $lastYear, $thisYear),
                'energy_out_lastYear' => $em->getRepository('App:SmartFoxDataStore')->getEnergyInterval($this->get('App\Utils\Connectors\SmartFoxConnector')->getIp(), 'energy_out', $lastYear, $thisYear),
            ];
        }
        if (array_key_exists('conexio', $this->getParameter('connectors'))) {
            $history['conexio'] = [
                'energy_today' => $em->getRepository('App:ConexioDataStore')->getEnergyInterval($this->get('App\Utils\Connectors\ConexioConnector')->getIp(), $today, $now),
                'energy_yesterday' => $em->getRepository('App:ConexioDataStore')->getEnergyInterval($this->get('App\Utils\Connectors\ConexioConnector')->getIp(), $yesterday, $today),
                'energy_week' => $em->getRepository('App:ConexioDataStore')->getEnergyInterval($this->get('App\Utils\Connectors\ConexioConnector')->getIp(), $thisWeek, $now),
                'energy_month' => $em->getRepository('App:ConexioDataStore')->getEnergyInterval($this->get('App\Utils\Connectors\ConexioConnector')->getIp(), $thisMonth, $now),
                'energy_lastYearMonth' => $em->getRepository('App:ConexioDataStore')->getEnergyInterval($this->get('App\Utils\Connectors\ConexioConnector')->getIp(), $lastYearMonth, $lastYearPart),
                'energy_year' => $em->getRepository('App:ConexioDataStore')->getEnergyInterval($this->get('App\Utils\Connectors\ConexioConnector')->getIp(), $thisYear, $now),
                'energy_lastYearPart' => $em->getRepository('App:ConexioDataStore')->getEnergyInterval($this->get('App\Utils\Connectors\ConexioConnector')->getIp(), $lastYear, $lastYearPart),
                'energy_lastYear' => $em->getRepository('App:ConexioDataStore')->getEnergyInterval($this->get('App\Utils\Connectors\ConexioConnector')->getIp(), $lastYear, $thisYear),
            ];
        }

        // render the template
        return $this->render('default/history.html.twig', [
            'activePage' => 'history',
            'history' => $history,
        ]);
    }

    /**
     * Create the visual dashboard
     * @Route("/visualdashboard", name="visual_dashboard")
     */
    public function visualDashboardAction(Request $request)
    {
        $currentStat = $this->getCurrentStat([
            'smartfox' => true,
            'mobilealerts' => true,
            'pcoweb' => true,
            'wem' => true,
            'conexio' => true,
            'logocontrol' => true,
            'netatmo' => true,
        ]);

        $fileContent = file_get_contents($this->getParameter('kernel.project_dir').'/public/visual_dashboard.svg');

        $climateValues = [];
        if (isset($currentStat['mobileAlerts'])) {
            foreach ($currentStat['mobileAlerts'] as $maDevice) {
                if (is_array($maDevice)) {
                    foreach ($maDevice as $maSensor) {
                        if (array_key_exists('usage', $maSensor) && $maSensor['usage'] !== false) {
                            $climateValues[$maSensor['usage']] = $maSensor['value'];
                        }
                    }
                }
            }
        }

        if (isset($currentStat['netatmo'])) {
            if ($netatmoValues = $this->get('App\Utils\Connectors\NetatmoConnector')->getLatestByLocation('inside')) {
                $climateValues['insidetemp'] = $netatmoValues['temp'];
                $climateValues['insidehumidity'] = $netatmoValues['humidity'];
            }
            if ($netatmoValues = $this->get('App\Utils\Connectors\NetatmoConnector')->getLatestByLocation('firstfloor')) {
                $climateValues['firstfloortemp'] = $netatmoValues['temp'];
                $climateValues['firstfloorhumidity'] = $netatmoValues['humidity'];
            }
            if ($netatmoValues = $this->get('App\Utils\Connectors\NetatmoConnector')->getLatestByLocation('secondfloor')) {
                $climateValues['secondfloortemp'] = $netatmoValues['temp'];
                $climateValues['secondfloorhumidity'] = $netatmoInside['humidity'];
            }
            if ($netatmoValues = $this->get('App\Utils\Connectors\NetatmoConnector')->getLatestByLocation('basement')) {
                $climateValues['basementtemp'] = $netatmoValues['temp'];
                $climateValues['basementhumidity'] = $netatmoValues['humidity'];
            }
            if ($netatmoValues = $this->get('App\Utils\Connectors\NetatmoConnector')->getLatestByLocation('outside')) {
                $climateValues['outsidetemp'] = $netatmoValues['temp'];
                $climateValues['outsidehumidity'] = $netatmoValues['humidity']; // currently not displaied
            }
        }

        // get values
        if (isset($currentStat['smartFox'])) {
            $pvpower = $currentStat['smartFox']['PvPower'][0]." W";
            $netpower = $currentStat['smartFox']['power_io']." W";
            $intpower = ($currentStat['smartFox']['power_io'] + $currentStat['smartFox']['PvPower'][0])." W";
        } else {
            $pvpower = "";
            $netpower = "";
            $intpower = "";
        }

        if (isset($currentStat['conexio'])) {
            $solpower = $currentStat['conexio']['p']." W";
            $soltemp = $currentStat['conexio']['s1']." °C";
            $hightemp = $currentStat['conexio']['s3']." °C";
            $lowtemp = $currentStat['conexio']['s2']." °C";
        } elseif (isset($currentStat['logoControl'])) {
            $solpower = $currentStat['logoControl'][$this->getParameter('connectors')['logocontrol']['powerSensor']] . " °C";
            $soltemp = $currentStat['logoControl'][$this->getParameter('connectors')['logocontrol']['collectorSensor']] . " °C";
            $hightemp = $currentStat['logoControl'][$this->getParameter('connectors')['logocontrol']['heatStorageSensor']] . " °C";
            $lowtemp = "";
        } else {
            $solpower = "";
            $soltemp = "";
            $hightemp = "";
            $lowtemp = "";
        }

        $insidetemp = "";
        $firstfloortemp = "";
        $secondfloortemp = "";
        $insidehumidity = "";
        $basementtemp = "";
        $basementhumidity = "";
        $outsideTemp = "";
        if (isset($climateValues['insidetemp'])) {
            $insidetemp = $climateValues['insidetemp'] . " °C";
        }
        if (isset($climateValues['firstfloortemp'])) {
            $firstfloortemp = $climateValues['firstfloortemp'] . " °C";
        }
        if (isset($climateValues['secondfloortemp'])) {
            $secondfloortemp = $climateValues['secondfloortemp']. " °C";
        }
        if (isset($climateValues['insidehumidity'])) {
            $insidehumidity = $climateValues['insidehumidity'] . " %";
        }
        if (isset($climateValues['basementtemp'])) {
            $basementtemp = $climateValues['basementtemp'] . " °C";
        }
        if (isset($climateValues['basementhumidity'])) {
            $basementhumidity = $climateValues['basementhumidity'] . " %";
        }
        if (isset($climateValues['outsidetemp'])) {
            $outsideTemp = $climateValues['outsidetemp'] . " °C";
        }

        if(isset($currentStat['pcoWeb']) && $currentStat['pcoWeb']['cpStatus'] === 'label.device.status.on') {
            $effDistrTemp = $currentStat['pcoWeb']['effDistrTemp']." °C";
        } elseif(isset($currentStat['pcoWeb'])) {
            $effDistrTemp = $this->get('translator')->trans($currentStat['pcoWeb']['cpStatus']);
        } else {
            $effDistrTemp = '';
        }

        if (isset($currentStat['pcoWeb']) &&$currentStat['pcoWeb']['ppStatus'] === "label.device.status.on") {
            $sourceintemp = $currentStat['pcoWeb']['ppSourceIn']." °C";
            $sourceouttemp = $currentStat['pcoWeb']['ppSourceOut']." °C";
        } else {
            $sourceintemp = "";
            $sourceouttemp = "";
        }
        if(isset($currentStat['pcoWeb'])) {
            $outsideTemp = $currentStat['pcoWeb']['outsideTemp']." °C";
            $waterTemp = $currentStat['pcoWeb']['waterTemp']." °C";
            $ppStatus = $this->get('translator')->trans($currentStat['pcoWeb']['ppStatus']);
            $storTemp = $this->get('translator')->trans($currentStat['pcoWeb']['storTemp']);
        } else {;
            $waterTemp = '';
            $ppStatus = '';
            $storTemp = '';
        }
        // write current values into the svg
        $labels = [
            "pvpower",
            "netpower",
            "intpower",
            "solpower",
            "soltemp",
            "outsidetemp",
            "watertemp",
            "ppstatus",
            "effdistrtemp",
            "insidetemp",
            "firstfloortemp",
            "secondfloortemp",
            "insidehumidity",
            "basementtemp",
            "basementhumidity",
            "hightemp",
            "midtemp",
            "lowtemp",
            "sourceintemp",
            "sourceouttemp"
        ];
        $values = [
            $pvpower,
            $netpower,
            $intpower,
            $solpower,
            $soltemp,
            $outsideTemp,
            $waterTemp,
            $ppStatus,
            $effDistrTemp,
            $insidetemp,
            $firstfloortemp,
            $secondfloortemp,
            $insidehumidity,
            $basementtemp,
            $basementhumidity,
            $hightemp,
            $storTemp,
            $lowtemp,
            $sourceintemp,
            $sourceouttemp,
        ];

        $fileContent = str_replace($labels, $values, $fileContent);

        // Return a response with a specific content
        $response = new Response($fileContent);

        // Create the disposition of the file
        $disposition = $response->headers->makeDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            'dashboard.svg'
        );

        // Set the content disposition
        $response->headers->set('Content-Disposition', $disposition);

        $response->headers->set('Content-Type', 'image/svg+xml');

        // Dispatch request
        return $response;
    }

    private function getCurrentStat($fullSet = true)
    {
        $currentStat = [];
        if (($fullSet === true || isset($fullSet['smartfox'])) && array_key_exists('smartfox', $this->getParameter('connectors'))) {
            $currentStat['smartFox'] = $this->get('App\Utils\Connectors\SmartFoxConnector')->getAll();
        }
        if (($fullSet === true || isset($fullSet['pcoweb'])) && array_key_exists('pcoweb', $this->getParameter('connectors'))) {
            $currentStat['pcoWeb'] = $this->get('App\Utils\Connectors\PcoWebConnector')->getAllLatest();
        } elseif (($fullSet === true || isset($fullSet['wem'])) && array_key_exists('wem', $this->getParameter('connectors'))) {
            $currentStat['pcoWeb'] = $this->get('App\Utils\Connectors\WemConnector')->getAllLatest();  // we store the wem data to the pcoWeb data structure for simplicity
        }
        if (($fullSet === true || isset($fullSet['conexio'])) && array_key_exists('conexio', $this->getParameter('connectors'))) {
            $currentStat['conexio'] = $this->get('App\Utils\Connectors\ConexioConnector')->getAll(true);
        }
        if (($fullSet === true || isset($fullSet['edimax'])) && array_key_exists('edimax', $this->getParameter('connectors'))) {
            $currentStat['edimax'] = $this->get('App\Utils\Connectors\EdiMaxConnector')->getAll();
        }
        if (($fullSet === true || isset($fullSet['mystrom'])) && array_key_exists('mystrom', $this->getParameter('connectors'))) {
            $currentStat['mystrom'] = $this->get('App\Utils\Connectors\MyStromConnector')->getAll();
        }
        if (($fullSet === true || isset($fullSet['shelly'])) && array_key_exists('shelly', $this->getParameter('connectors'))) {
            $currentStat['shelly'] = $this->get('App\Utils\Connectors\ShellyConnector')->getAll();
        }
        if (($fullSet === true || isset($fullSet['mobilealerts'])) && array_key_exists('mobilealerts', $this->getParameter('connectors'))) {
            $currentStat['mobileAlerts'] = $this->get('App\Utils\Connectors\MobileAlertsConnector')->getAllLatest();
        }
        if (($fullSet === true || isset($fullSet['openweathermap'])) && array_key_exists('openweathermap', $this->getParameter('connectors'))) {
            $currentStat['openweathermap'] = $this->get('App\Utils\Connectors\OpenWeatherMapConnector')->getAllLatest();
        }
        if (($fullSet === true || isset($fullSet['logocontrol'])) && array_key_exists('logocontrol', $this->getParameter('connectors'))) {
            $currentStat['logoControl'] = $this->get('App\Utils\Connectors\LogoControlConnector')->getAll(true);
        }
        if (($fullSet === true || isset($fullSet['netatmo'])) && array_key_exists('netatmo', $this->getParameter('connectors'))) {
            $currentStat['netatmo'] = $this->get('App\Utils\Connectors\NetatmoConnector')->getAllLatest();
        }

        return $currentStat;
    }

    /**
     * trigger by external event
     * @Route("/trigger/{deviceId}/{action}", defaults={"deviceId"=null, "action"=null}, name="trigger")
     */
    public function triggerAction(Request $request, LogicProcessor $logic, $deviceId, $action)
    {
        // init the mystrom device if reasonable
        $logic->initMystrom($deviceId);

        // init the shelly device if reasonable
        $logic->initShelly($deviceId, $action);

        // execute auto actions where reasonable
        $logic->autoActionsEdimax();
        $logic->autoActionsMystrom();
        $logic->autoActionsShelly();

        // trigger the alarms
        $logic->processAlarms();

        return new JsonResponse(['success' => true]);
    }

    /**
     * interface for external variable requests
     * currently only supports requests for netPower
     * @Route("/stat/{variable}", name="stat")
     */
    public function statAction(Request $request, $variable)
    {
        $value = null;
        if ($variable === 'netPower' && $this->get('App\Utils\Connectors\SmartFoxConnector')->getIp()) {
            $smartFox = $this->get('App\Utils\Connectors\SmartFoxConnector')->getAll();
            $value = $smartFox['power_io'];
        }

        return new JsonResponse($value);
    }
}
