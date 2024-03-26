<?php

namespace App\Controller;

use App\Entity\Settings;
use App\Entity\User;
use App\Entity\SmartFoxDataStore;
use App\Entity\MobileAlertsDataStore;
use App\Entity\NetatmoDataStore;
use App\Entity\PcoWebDataStore;
use App\Entity\WemDataStore;
use App\Entity\ConexioDataStore;
use App\Entity\LogoControlDataStore;
use App\Entity\TaCmiDataStore;
use App\Entity\EcarDataStore;
use App\Utils\LogicProcessor;
use App\Utils\Connectors\ConexioConnector;
use App\Utils\Connectors\EcarConnector;
use App\Utils\Connectors\GardenaConnector;
use App\Utils\Connectors\LogoControlConnector;
use App\Utils\Connectors\TaCmiConnector;
use App\Utils\Connectors\MobileAlertsConnector;
use App\Utils\Connectors\MyStromConnector;
use App\Utils\Connectors\NetatmoConnector;
use App\Utils\Connectors\OpenWeatherMapConnector;
use App\Utils\Connectors\PcoWebConnector;
use App\Utils\Connectors\ShellyConnector;
use App\Utils\Connectors\SmartFoxConnector;
use App\Utils\Connectors\WemConnector;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Contracts\Translation\TranslatorInterface;
use Doctrine\ORM\EntityManagerInterface;

class DefaultController extends AbstractController
{
    public function __construct(
            MyStromConnector $mystrom,
            ShellyConnector $shelly,
            SmartFoxConnector $smartfox, 
            PcoWebConnector $pcoweb,
            WemConnector $wem,
            ConexioConnector $conexio,
            GardenaConnector $gardena,
            NetatmoConnector $netatmo,
            MobileAlertsConnector $mobilealerts,
            LogoControlConnector $logocontrol,
            TaCmiConnector $tacmi,
            OpenWeatherMapConnector $openweather,
            EcarConnector $ecar
        )
    {
       $this->mystrom = $mystrom;
       $this->shelly = $shelly;
       $this->smartfox = $smartfox;
       $this->pcoweb = $pcoweb;
       $this->wem = $wem;
       $this->conexio = $conexio;
       $this->gardena = $gardena;
       $this->netatmo = $netatmo;
       $this->mobilealerts = $mobilealerts;
       $this->logocontrol = $logocontrol;
       $this->tacmi = $tacmi;
       $this->openweather = $openweather;
       $this->ecar = $ecar;
    }

    /**
     * @Route("/", name="homepage")
     */
    public function indexAction(Request $request, EntityManagerInterface $em)
    {
        $clientIp = $request->getClientIp();
        $authenticatedIps = $this->getParameter('authenticated_ips');
        $params = $request->query->all();
        $securityContext = $this->container->get('security.authorization_checker');
        if (!$securityContext->isGranted('IS_AUTHENTICATED_REMEMBERED') && array_key_exists($clientIp, $authenticatedIps)) {
            $route = $request->get('route');
            $user = $em->getRepository(User::class)->findOneBy(array('email' => $authenticatedIps[$clientIp]));
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
    public function overviewAction(Request $request, EntityManagerInterface $em)
    {
        $activePage = "overview";
        $history = [];
        $currentStat = [];
        $fromParam = $from = $request->query->get("from");
        $toParam = $request->query->get("to");
        if ($fromParam && $toParam) {
            $from = new \DateTime($fromParam);
            $to = new \DateTime($toParam);
        } else {
            $from = new \DateTime('-1 days');
            $to = new \DateTime('now');
        }
        if ($request->query->get("details")) {
            if ($fromParam && $toParam) {
                $from = new \DateTime($fromParam);
                $to = new \DateTime($toParam);
            } else {
                $from = new \DateTime('-1 days');
                $to = new \DateTime('now');
            }

            $activePage = "details";
            // get current values
            if (array_key_exists('smartfox', $this->getParameter('connectors'))) {
                $currentStat['smartFox'] = $this->smartfox->getAllLatest();
                $currentStat['smartFoxChart'] = true;
                $currentStat['smartFox_energy_mix'] = $em->getRepository(SmartFoxDataStore::class)->getEnergyMix($this->smartfox->getIp(), $this->getParameter('energy_low_rate'), new \DateTime('today'), new \DateTime('now'));
            }
            if (array_key_exists('pcoweb', $this->getParameter('connectors'))) {
                $currentStat['pcoWeb'] = $this->pcoweb->getAllLatest();
            } elseif (array_key_exists('wem', $this->getParameter('connectors'))) {
                $currentStat['pcoWeb'] = $this->wem->getAllLatest(); // we store the wem data to the pcoWeb data structure for simplicity
            }
            if (array_key_exists('conexio', $this->getParameter('connectors'))) {
                $currentStat['conexio'] = $this->conexio->getAllLatest();
            }
            if (array_key_exists('mobilealerts', $this->getParameter('connectors'))) {
                $currentStat['mobileAlerts'] = $this->mobilealerts->getAllLatest();
            }
            if (array_key_exists('openweathermap', $this->getParameter('connectors'))) {
                $currentStat['openweathermap'] = $this->openweather->getAllLatest();
            }
            if (array_key_exists('logocontrol', $this->getParameter('connectors'))) {
                $currentStat['logoControl'] = $this->logocontrol->getAllLatest();
            }
            if (array_key_exists('tacmi', $this->getParameter('connectors'))) {
                $currentStat['taCmi'] = $this->tacmi->getAllLatest();
            }
            if (array_key_exists('netatmo', $this->getParameter('connectors'))) {
                $currentStat['netatmo'] = $this->netatmo->getAllLatest();
            }

            // get history
            if (array_key_exists('mobilealerts', $this->getParameter('connectors')) && is_array($this->getParameter('connectors')['mobilealerts']['sensors'])) {
                $mobileAlertsHistory = [];
                foreach ($this->getParameter('connectors')['mobilealerts']['sensors'] as $sensorId => $mobileAlertsSensor) {
                    $mobileAlertsHistory[$sensorId] = $em->getRepository(MobileAlertsDataStore::class)->getHistory($sensorId, $from, $to);
                }
                $history['mobileAlerts'] = $mobileAlertsHistory;
            }
            if (array_key_exists('netatmo', $this->getParameter('connectors'))) {
                $history['netatmo'] = $em->getRepository(NetatmoDataStore::class)->getHistory($this->netatmo->getId(), $from, $to);
            }
            if (array_key_exists('smartfox', $this->getParameter('connectors'))) {
                $history['smartFox'] = $em->getRepository(SmartFoxDataStore::class)->getHistory($this->smartfox->getIp(), $from, $to);
            }
            if (array_key_exists('pcoweb', $this->getParameter('connectors'))) {
                $history['pcoWeb'] = $em->getRepository(PcoWebDataStore::class)->getHistory($this->pcoweb->getIp(), $from, $to);
            } elseif (array_key_exists('wem', $this->getParameter('connectors'))) {
                $history['pcoWeb'] = $em->getRepository(WemDataStore::class)->getHistory($this->wem->getUsername(), $from, $to); // we store the wem data to the pcoWeb data structure for simplicity
            }
            if (array_key_exists('conexio', $this->getParameter('connectors'))) {
                $history['conexio'] = $em->getRepository(ConexioDataStore::class)->getHistory($this->conexio->getIp(), $from, $to);
            }
            if (array_key_exists('logocontrol', $this->getParameter('connectors'))) {
                $history['logoControl'] = $em->getRepository(LogoControlDataStore::class)->getHistory($this->logocontrol->getIp(), $from, $to);
            }
            if (array_key_exists('tacmi', $this->getParameter('connectors'))) {
                $history['taCmi'] = $em->getRepository(TaCmiDataStore::class)->getHistory($this->tacmi->getIp(), $from, $to);
            }
            if (array_key_exists('ecar', $this->getParameter('connectors'))) {
                foreach ($this->getParameter('connectors')['ecar'] as $ecar) {
                    $ecarHistory[$ecar['carId']] = $em->getRepository(EcarDataStore::class)->getHistory($ecar['carId'], $from, $to);
                }
                $history['ecar'] = $ecarHistory;
            }
        } else {
            $currentStat = $this->getCurrentStat([
                'mystrom' => true,
                'shelly' => true,
                'pcoweb' => true,
                'wem' => true,
                'openweathermap' => true,
                'mobilealerts' => true,
                'netatmo' => true,
                'ecar' => true,
            ]);
        }     

        // render the template
        return $this->render('default/index.html.twig', [
            'activePage' => $activePage,
            'currentStat' => $currentStat,
            'history' => $history,
            'from' => $from,
            'to' => $to,
        ]);
    }

    /**
     * Execute command
     * @Route("/cmd/{command}", name="command_exec")
     */
    public function commandExecuteAction(EntityManagerInterface $em, $command)
    {
        // only owners are allowed to execute commands
        $this->denyAccessUnlessGranted('ROLE_OWNER');

        // execute the command
        $success = $this->executeCommand($em, $command);

        return new JsonResponse(['success' => $success]);
    }

    private function executeCommand($em, $jsonCommand)
    {
        $command = json_decode($jsonCommand);
        switch ($command[0]) {
            case 'mystrom':
                return $this->mystrom->executeCommand($command[1], $command[2]);
            case 'shelly':
                return $this->shelly->executeCommand($command[1], $command[2]);
            case 'pcoweb':
                return $this->pcoweb->executeCommand($command[1], $command[2]);
            case 'wem':
                return $this->wem->executeCommand($command[1], $command[2]);
            case 'settings':
                if ($command[1] == 'mode') {
                    if ($command[2] == 'pcoweb') {
                        $connectorId = $this->pcoweb->getIp();
                        if ($connectorId && intval($command['3']) !== Settings::MODE_WARMWATER) {
                            $this->pcoweb->normalizeSettings();
                        }
                    } elseif ($command[2] == 'wem') {
                        $connectorId = $this->wem->getUsername();
                    } else {
                        $connectorId = $command[2];
                    }
                    if ($connectorId == 'alarm')
                    {
                        // make sure all mystrom PIR devices have their action URL set correctly
                        $this->mystrom->activateAllPIR();
                    }
                    $settings = $em->getRepository(Settings::class)->findOneByConnectorId($connectorId);
                    if (!$settings) {
                        $settings = new Settings();
                        $settings->setConnectorId($connectorId);
                        $em->persist($settings);
                    }
                    $settings->setMode($command[3]);

                    $em->flush();
                    return true;
                }
            case 'command':
                $connectors = $this->getParameter('connectors');
                exec($connectors['command'][$command[1]]['cmd']);
                return true;
            case 'gardena':
                return $this->gardena->executeCommand($command[1], $command[2]);
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
                'mystrom' => true,
                'shelly' => true,
                'openweathermap' => true,
                'mobilealerts' => true,
                'ecar' => true,
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
    public function historyAction(Request $request, EntityManagerInterface $em)
    {
        $yesterday = new \DateTime('yesterday');
        $now = new \DateTime('now');
        $today = new \DateTime('today');
        $thisWeek = new \DateTime('monday this week midnight');
        $thisMonth = new \DateTime('first day of this month midnight');
        $lastYearMonth = new \DateTime('first day of this month midnight');
        $lastYearMonth = $lastYearMonth->sub(new \DateInterval('P1Y'));
        $lastQuarterStart = (new \DateTime('first day of -' . (((date('n') - 1) % 3) + 3) . ' month'))->modify('00:00:00.000000');
        $lastQuarterEnd = (new \DateTime('last day of -' . (((date('n') - 1) % 3) + 1) . ' month'))->modify('23:59:59.999999');
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
            'lastQuarter',
            'year',
            'lastYearPart',
            'lastYear',
        ];
        if (array_key_exists('smartfox', $this->getParameter('connectors'))) {
            $ip = $this->smartfox->getIp();
            $history['smartfox'] = [
                'pv_today' => $em->getRepository(SmartFoxDataStore::class)->getEnergyInterval($ip, 'PvEnergy', $today, $now),
                'pv_yesterday' => $em->getRepository(SmartFoxDataStore::class)->getEnergyInterval($ip, 'PvEnergy', $yesterday, $today),
                'pv_week' => $em->getRepository(SmartFoxDataStore::class)->getEnergyInterval($ip, 'PvEnergy', $thisWeek, $now),
                'pv_month' => $em->getRepository(SmartFoxDataStore::class)->getEnergyInterval($ip, 'PvEnergy', $thisMonth, $now),
                'pv_lastYearMonth' => $em->getRepository(SmartFoxDataStore::class)->getEnergyInterval($ip, 'PvEnergy', $lastYearMonth, $lastYearPart),
                'pv_lastQuarter' => $em->getRepository(SmartFoxDataStore::class)->getEnergyInterval($ip, 'PvEnergy', $lastQuarterStart, $lastQuarterEnd),
                'pv_year' => $em->getRepository(SmartFoxDataStore::class)->getEnergyInterval($ip, 'PvEnergy', $thisYear, $now),
                'pv_lastYearPart' => $em->getRepository(SmartFoxDataStore::class)->getEnergyInterval($ip, 'PvEnergy', $lastYear, $lastYearPart),
                'pv_lastYear' => $em->getRepository(SmartFoxDataStore::class)->getEnergyInterval($ip, 'PvEnergy', $lastYear, $thisYear),
                'energy_in_today' => $em->getRepository(SmartFoxDataStore::class)->getEnergyInterval($ip, 'energy_in', $today, $now),
                'energy_in_today_highrate' => $em->getRepository(SmartFoxDataStore::class)->getEnergyIntervalHighRate($ip, 'energy_in', $this->getParameter('energy_low_rate'), $today, $now),
                'energy_out_today' => $em->getRepository(SmartFoxDataStore::class)->getEnergyInterval($ip, 'energy_out', $today, $now),
                'energy_in_yesterday' => $em->getRepository(SmartFoxDataStore::class)->getEnergyInterval($ip, 'energy_in', $yesterday, $today),
                'energy_in_yesterday_highrate' => $em->getRepository(SmartFoxDataStore::class)->getEnergyIntervalHighRate($ip, 'energy_in', $this->getParameter('energy_low_rate'), $yesterday, $today),
                'energy_out_yesterday' => $em->getRepository(SmartFoxDataStore::class)->getEnergyInterval($ip, 'energy_out', $yesterday, $today),
                'energy_in_week' => $em->getRepository(SmartFoxDataStore::class)->getEnergyInterval($ip, 'energy_in', $thisWeek, $now),
                'energy_in_week_highrate' => $em->getRepository(SmartFoxDataStore::class)->getEnergyIntervalHighRate($ip, 'energy_in', $this->getParameter('energy_low_rate'), $thisWeek, $now),
                'energy_out_week' => $em->getRepository(SmartFoxDataStore::class)->getEnergyInterval($ip, 'energy_out', $thisWeek, $now),
                'energy_in_month' => $em->getRepository(SmartFoxDataStore::class)->getEnergyInterval($ip, 'energy_in', $thisMonth, $now),
                'energy_in_month_highrate' => $em->getRepository(SmartFoxDataStore::class)->getEnergyIntervalHighRate($ip, 'energy_in', $this->getParameter('energy_low_rate'), $thisMonth, $now),
                'energy_out_month' => $em->getRepository(SmartFoxDataStore::class)->getEnergyInterval($ip, 'energy_out', $thisMonth, $now),
                'energy_in_lastYearMonth' => $em->getRepository(SmartFoxDataStore::class)->getEnergyInterval($ip, 'energy_in', $lastYearMonth, $lastYearPart),
                'energy_in_lastYearMonth_highrate' => $em->getRepository(SmartFoxDataStore::class)->getEnergyIntervalHighRate($ip, 'energy_in', $this->getParameter('energy_low_rate'), $lastYearMonth, $lastYearPart),
                'energy_out_lastYearMonth' => $em->getRepository(SmartFoxDataStore::class)->getEnergyInterval($ip, 'energy_out', $lastYearMonth, $lastYearPart),
                'energy_in_lastQuarter' => $em->getRepository(SmartFoxDataStore::class)->getEnergyInterval($ip, 'energy_in', $lastQuarterStart, $lastQuarterEnd),
                'energy_in_lastQuarter_highrate' => $em->getRepository(SmartFoxDataStore::class)->getEnergyIntervalHighRate($ip, 'energy_in', $this->getParameter('energy_low_rate'), $lastQuarterStart, $lastQuarterEnd),
                'energy_out_lastQuarter' => $em->getRepository(SmartFoxDataStore::class)->getEnergyInterval($ip, 'energy_out', $lastQuarterStart, $lastQuarterEnd),
                'energy_in_year' => $em->getRepository(SmartFoxDataStore::class)->getEnergyInterval($ip, 'energy_in', $thisYear, $now),
                'energy_in_year_highrate' => $em->getRepository(SmartFoxDataStore::class)->getEnergyIntervalHighRate($ip, 'energy_in', $this->getParameter('energy_low_rate'), $thisYear, $now),
                'energy_out_year' => $em->getRepository(SmartFoxDataStore::class)->getEnergyInterval($ip, 'energy_out', $thisYear, $now),
                'energy_in_lastYearPart' => $em->getRepository(SmartFoxDataStore::class)->getEnergyInterval($ip, 'energy_in', $lastYear, $lastYearPart),
                'energy_in_lastYearPart_highrate' => $em->getRepository(SmartFoxDataStore::class)->getEnergyIntervalHighRate($ip, 'energy_in', $this->getParameter('energy_low_rate'), $lastYear, $lastYearPart),
                'energy_out_lastYearPart' => $em->getRepository(SmartFoxDataStore::class)->getEnergyInterval($ip, 'energy_out', $lastYear, $lastYearPart),
                'energy_in_lastYear' => $em->getRepository(SmartFoxDataStore::class)->getEnergyInterval($ip, 'energy_in', $lastYear, $thisYear),
                'energy_in_lastYear_highrate' => $em->getRepository(SmartFoxDataStore::class)->getEnergyIntervalHighRate($ip, 'energy_in', $this->getParameter('energy_low_rate'), $lastYear, $thisYear),
                'energy_out_lastYear' => $em->getRepository(SmartFoxDataStore::class)->getEnergyInterval($ip, 'energy_out', $lastYear, $thisYear),
            ];
            if ($this->smartfox->hasAltPv()) {
                $history['smartfox'] = array_merge($history['smartfox'], [
                    'pv_alt_today' => $em->getRepository(SmartFoxDataStore::class)->getEnergyInterval($ip, 'PvEnergyAlt', $today, $now),
                    'pv_alt_yesterday' => $em->getRepository(SmartFoxDataStore::class)->getEnergyInterval($ip, 'PvEnergyAlt', $yesterday, $today),
                    'pv_alt_week' => $em->getRepository(SmartFoxDataStore::class)->getEnergyInterval($ip, 'PvEnergyAlt', $thisWeek, $now),
                    'pv_alt_month' => $em->getRepository(SmartFoxDataStore::class)->getEnergyInterval($ip, 'PvEnergyAlt', $thisMonth, $now),
                    'pv_alt_lastYearMonth' => $em->getRepository(SmartFoxDataStore::class)->getEnergyInterval($ip, 'PvEnergyAlt', $lastYearMonth, $lastYearPart),
                    'pv_alt_lastQuarter' => $em->getRepository(SmartFoxDataStore::class)->getEnergyInterval($ip, 'PvEnergyAlt', $lastQuarterStart, $lastQuarterEnd),
                    'pv_alt_year' => $em->getRepository(SmartFoxDataStore::class)->getEnergyInterval($ip, 'PvEnergyAlt', $thisYear, $now),
                    'pv_alt_lastYearPart' => $em->getRepository(SmartFoxDataStore::class)->getEnergyInterval($ip, 'PvEnergyAlt', $lastYear, $lastYearPart),
                    'pv_alt_lastYear' => $em->getRepository(SmartFoxDataStore::class)->getEnergyInterval($ip, 'PvEnergyAlt', $lastYear, $thisYear),
                ]);
            }
            if ($this->smartfox->hasStorage()) {
                $history['smartfox'] = array_merge($history['smartfox'], [
                    'storage_in_today' => $em->getRepository(SmartFoxDataStore::class)->getEnergyInterval($ip, 'StorageEnergyIn', $today, $now),
                    'storage_in_yesterday' => $em->getRepository(SmartFoxDataStore::class)->getEnergyInterval($ip, 'StorageEnergyIn', $yesterday, $today),
                    'storage_in_week' => $em->getRepository(SmartFoxDataStore::class)->getEnergyInterval($ip, 'StorageEnergyIn', $thisWeek, $now),
                    'storage_in_month' => $em->getRepository(SmartFoxDataStore::class)->getEnergyInterval($ip, 'StorageEnergyIn', $thisMonth, $now),
                    'storage_in_lastYearMonth' => $em->getRepository(SmartFoxDataStore::class)->getEnergyInterval($ip, 'StorageEnergyIn', $lastYearMonth, $lastYearPart),
                    'storage_in_lastQuarter' => $em->getRepository(SmartFoxDataStore::class)->getEnergyInterval($ip, 'StorageEnergyIn', $lastQuarterStart, $lastQuarterEnd),
                    'storage_in_year' => $em->getRepository(SmartFoxDataStore::class)->getEnergyInterval($ip, 'StorageEnergyIn', $thisYear, $now),
                    'storage_in_lastYearPart' => $em->getRepository(SmartFoxDataStore::class)->getEnergyInterval($ip, 'StorageEnergyIn', $lastYear, $lastYearPart),
                    'storage_in_lastYear' => $em->getRepository(SmartFoxDataStore::class)->getEnergyInterval($ip, 'StorageEnergyIn', $lastYear, $thisYear),
                    'storage_out_today' => $em->getRepository(SmartFoxDataStore::class)->getEnergyInterval($ip, 'StorageEnergyOut', $today, $now),
                    'storage_out_yesterday' => $em->getRepository(SmartFoxDataStore::class)->getEnergyInterval($ip, 'StorageEnergyOut', $yesterday, $today),
                    'storage_out_week' => $em->getRepository(SmartFoxDataStore::class)->getEnergyInterval($ip, 'StorageEnergyOut', $thisWeek, $now),
                    'storage_out_month' => $em->getRepository(SmartFoxDataStore::class)->getEnergyInterval($ip, 'StorageEnergyOut', $thisMonth, $now),
                    'storage_out_lastYearMonth' => $em->getRepository(SmartFoxDataStore::class)->getEnergyInterval($ip, 'StorageEnergyOut', $lastYearMonth, $lastYearPart),
                    'storage_out_lastQuarter' => $em->getRepository(SmartFoxDataStore::class)->getEnergyInterval($ip, 'StorageEnergyOut', $lastQuarterStart, $lastQuarterEnd),
                    'storage_out_year' => $em->getRepository(SmartFoxDataStore::class)->getEnergyInterval($ip, 'StorageEnergyOut', $thisYear, $now),
                    'storage_out_lastYearPart' => $em->getRepository(SmartFoxDataStore::class)->getEnergyInterval($ip, 'StorageEnergyOut', $lastYear, $lastYearPart),
                    'storage_out_lastYear' => $em->getRepository(SmartFoxDataStore::class)->getEnergyInterval($ip, 'StorageEnergyOut', $lastYear, $thisYear),
                ]);
            }
        }
        if (array_key_exists('conexio', $this->getParameter('connectors'))) {
            $ip = $this->conexio->getIp();
            $history['conexio'] = [
                'energy_today' => $em->getRepository(ConexioDataStore::class)->getEnergyInterval($ip, $today, $now),
                'energy_yesterday' => $em->getRepository(ConexioDataStore::class)->getEnergyInterval($ip, $yesterday, $today),
                'energy_week' => $em->getRepository(ConexioDataStore::class)->getEnergyInterval($ip, $thisWeek, $now),
                'energy_month' => $em->getRepository(ConexioDataStore::class)->getEnergyInterval($ip, $thisMonth, $now),
                'energy_lastYearMonth' => $em->getRepository(ConexioDataStore::class)->getEnergyInterval($ip, $lastYearMonth, $lastYearPart),
                'energy_lastQuarter' => $em->getRepository(ConexioDataStore::class)->getEnergyInterval($ip, $lastQuarterStart, $lastQuarterEnd),
                'energy_year' => $em->getRepository(ConexioDataStore::class)->getEnergyInterval($ip, $thisYear, $now),
                'energy_lastYearPart' => $em->getRepository(ConexioDataStore::class)->getEnergyInterval($ip, $lastYear, $lastYearPart),
                'energy_lastYear' => $em->getRepository(ConexioDataStore::class)->getEnergyInterval($ip, $lastYear, $thisYear),
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
    public function visualDashboardAction(Request $request, TranslatorInterface $translator)
    {
        $currentStat = $this->getCurrentStat([
            'smartfox' => true,
            'mobilealerts' => true,
            'pcoweb' => true,
            'wem' => true,
            'conexio' => true,
            'logocontrol' => true,
            'tacmi' => true,
            'netatmo' => true,
        ]);

        $fileContent = file_get_contents($this->getParameter('kernel.project_dir').'/public/visual_dashboard.svg');

        $climateValues = [];
        if (isset($currentStat['mobileAlerts'])) {
            foreach ($currentStat['mobileAlerts'] as $maDevice) {
                if (is_array($maDevice)) {
                    foreach ($maDevice as $maSensor) {
                        if (is_array($maSensor ) && array_key_exists('usage', $maSensor) && $maSensor['usage'] !== false) {
                            $climateValues[$maSensor['usage']] = $maSensor['value'];
                        }
                    }
                }
            }
        }

        if (isset($currentStat['netatmo'])) {
            if ($netatmoValues = $this->netatmo->getLatestByLocation('inside')) {
                $climateValues['insidetemp'] = $netatmoValues['temp'];
                $climateValues['insidehumidity'] = $netatmoValues['humidity'];
            }
            if ($netatmoValues = $this->netatmo->getLatestByLocation('firstfloor')) {
                $climateValues['firstfloortemp'] = $netatmoValues['temp'];
                $climateValues['firstfloorhumidity'] = $netatmoValues['humidity'];
            }
            if ($netatmoValues = $this->netatmo->getLatestByLocation('secondfloor')) {
                $climateValues['secondfloortemp'] = $netatmoValues['temp'];
                $climateValues['secondfloorhumidity'] = $netatmoInside['humidity'];
            }
            if ($netatmoValues = $this->netatmo->getLatestByLocation('basement')) {
                $climateValues['basementtemp'] = $netatmoValues['temp'];
                $climateValues['basementhumidity'] = $netatmoValues['humidity'];
            }
            if ($netatmoValues = $this->netatmo->getLatestByLocation('outside')) {
                $climateValues['outsidetemp'] = $netatmoValues['temp'];
                $climateValues['outsidehumidity'] = $netatmoValues['humidity']; // currently not displaied
            }
        }

        // get values
        if (isset($currentStat['smartFox'])) {
            $pvpower = array_sum($currentStat['smartFox']['PvPower'])." W";
            $netpower = $currentStat['smartFox']['power_io']." W";
            $intpowerVal = $currentStat['smartFox']['power_io'] + array_sum($currentStat['smartFox']['PvPower']);
            if (array_key_exists('StoragePower', $currentStat['smartFox'])) {
                $intpowerVal = $intpowerVal - $currentStat['smartFox']['StoragePower'];
            }
            $intpower = $intpowerVal ." W";
        } else {
            $pvpower = "";
            $netpower = "";
            $intpower = "";
        }

        if (isset($currentStat['conexio']) && is_array($currentStat['conexio'])) {
            $solpower = $currentStat['conexio']['p']." W";
            $soltemp = $currentStat['conexio']['s1']."°C";
            $hightemp = $currentStat['conexio']['s3']."°C";
            $lowtemp = $currentStat['conexio']['s2']."°C";
        } elseif (isset($currentStat['logoControl']) && is_array($currentStat['logoControl'])) {
            $solpower = $currentStat['logoControl'][$this->getParameter('connectors')['logocontrol']['powerSensor']] . "°C";
            $soltemp = $currentStat['logoControl'][$this->getParameter('connectors')['logocontrol']['collectorSensor']] . "°C";
            $hightemp = $currentStat['logoControl'][$this->getParameter('connectors')['logocontrol']['heatStorageSensor']] . "°C";
            $lowtemp = "";
        } elseif (isset($currentStat['taCmi']) && is_array($currentStat['taCmi'])) {
            $solpower = $currentStat['taCmi'][$this->getParameter('connectors')['tacmi']['powerSensor']] . " %";
            $soltemp = $currentStat['taCmi'][$this->getParameter('connectors')['tacmi']['collectorSensor']] . "°C";
            $hightemp = $currentStat['taCmi'][$this->getParameter('connectors')['tacmi']['heatStorageSensor']] . "°C";
            $lowtemp = $currentStat['taCmi'][$this->getParameter('connectors')['tacmi']['lowStorageSensor']] . "°C";;
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
            $insidetemp = $climateValues['insidetemp'] . "°C";
        }
        if (isset($climateValues['firstfloortemp'])) {
            $firstfloortemp = $climateValues['firstfloortemp'] . "°C";
        }
        if (isset($climateValues['secondfloortemp'])) {
            $secondfloortemp = $climateValues['secondfloortemp']. "°C";
        }
        if (isset($climateValues['insidehumidity'])) {
            $insidehumidity = $climateValues['insidehumidity'] . " %";
        }
        if (isset($climateValues['basementtemp'])) {
            $basementtemp = $climateValues['basementtemp'] . "°C";
        }
        if (isset($climateValues['basementhumidity'])) {
            $basementhumidity = $climateValues['basementhumidity'] . " %";
        }
        if (isset($climateValues['outsidetemp'])) {
            $outsideTemp = $climateValues['outsidetemp'] . "°C";
        }

        if(isset($currentStat['pcoWeb']) && is_array($currentStat['pcoWeb']) && $currentStat['pcoWeb']['cpStatus'] === 'label.device.status.on') {
            $effDistrTemp = $currentStat['pcoWeb']['effDistrTemp']."°C";
        } elseif(isset($currentStat['pcoWeb']) && is_array($currentStat['pcoWeb'])) {
            $effDistrTemp = $translator->trans($currentStat['pcoWeb']['cpStatus']);
        } else {
            $effDistrTemp = '';
        }

        if (isset($currentStat['pcoWeb']) && is_array($currentStat['pcoWeb']) && ($currentStat['pcoWeb']['ppStatus'] === "label.device.status.on" || strpos($currentStat['pcoWeb']['ppStatus'], '%') > 0)) {
            $sourceintemp = $currentStat['pcoWeb']['ppSourceIn']."°C";
            $sourceouttemp = $currentStat['pcoWeb']['ppSourceOut']."°C";
        } else {
            $sourceintemp = "";
            $sourceouttemp = "";
        }
        if(isset($currentStat['pcoWeb']) && is_array($currentStat['pcoWeb'])) {
            $outsideTemp = $currentStat['pcoWeb']['outsideTemp']."°C";
            $waterTemp = $currentStat['pcoWeb']['waterTemp']."°C";
            if ($currentStat['pcoWeb']['ppStatus'] === 0 || $currentStat['pcoWeb']['ppStatus'] == 'Aus' || $currentStat['pcoWeb']['ppStatus'] == 'label.device.status.off') {
                $ppStatus = $translator->trans('label.device.status.off');
            } else {
                if (is_string($currentStat['pcoWeb']['ppStatus'])) {
                    $ppStatus = $translator->trans($currentStat['pcoWeb']['ppStatusMsg']);
                } else {
                    $ppStatus = str_replace(' %', '', $currentStat['pcoWeb']['ppStatus']) . '%';
                }
            }
            $storTemp = $currentStat['pcoWeb']['storTemp']."°C";
        } else {
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
            max($storTemp, $hightemp),
            min($storTemp, $hightemp),
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
            $currentStat['smartFox'] = $this->smartfox->getAll();
        } elseif (array_key_exists('smartfox', $this->getParameter('connectors'))) {
            $currentStat['smartFox'] = $this->smartfox->getAllLatest();
        }
        if (($fullSet === true || isset($fullSet['pcoweb'])) && array_key_exists('pcoweb', $this->getParameter('connectors'))) {
            $currentStat['pcoWeb'] = $this->pcoweb->getAllLatest();
        } elseif (($fullSet === true || isset($fullSet['wem'])) && array_key_exists('wem', $this->getParameter('connectors'))) {
            $currentStat['pcoWeb'] = $this->wem->getAllLatest();  // we store the wem data to the pcoWeb data structure for simplicity
        }
        if (($fullSet === true || isset($fullSet['conexio'])) && array_key_exists('conexio', $this->getParameter('connectors'))) {
            $currentStat['conexio'] = $this->conexio->getAllLatest();
        }
        if (($fullSet === true || isset($fullSet['mystrom'])) && array_key_exists('mystrom', $this->getParameter('connectors'))) {
            $currentStat['mystrom'] = $this->mystrom->getAllLatest();
        }
        if (($fullSet === true || isset($fullSet['shelly'])) && array_key_exists('shelly', $this->getParameter('connectors'))) {
            $currentStat['shelly'] = $this->shelly->getAllLatest();
        }
        if (($fullSet === true || isset($fullSet['mobilealerts'])) && array_key_exists('mobilealerts', $this->getParameter('connectors'))) {
            $currentStat['mobileAlerts'] = $this->mobilealerts->getAllLatest();
        }
        if (($fullSet === true || isset($fullSet['openweathermap'])) && array_key_exists('openweathermap', $this->getParameter('connectors'))) {
            $currentStat['openweathermap'] = $this->openweather->getAllLatest();
        }
        if (($fullSet === true || isset($fullSet['logocontrol'])) && array_key_exists('logocontrol', $this->getParameter('connectors'))) {
            $currentStat['logoControl'] = $this->logocontrol->getAllLatest();
        }
        if (($fullSet === true || isset($fullSet['tacmi'])) && array_key_exists('tacmi', $this->getParameter('connectors'))) {
            $currentStat['taCmi'] = $this->tacmi->getAllLatest();
        }
        if (($fullSet === true || isset($fullSet['netatmo'])) && array_key_exists('netatmo', $this->getParameter('connectors'))) {
            $currentStat['netatmo'] = $this->netatmo->getAllLatest();
        }
        if (($fullSet === true || isset($fullSet['ecar'])) && array_key_exists('ecar', $this->getParameter('connectors'))) {
            $currentStat['ecar'] = $this->ecar->getAllLatest();
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
    public function statAction($variable)
    {
        $value = null;
        if ($variable === 'netPower' && $this->smartfox->getIp()) {
            $smartFox = $this->smartfox->getAll();
            $value = $smartFox['power_io'];
        }

        return new JsonResponse($value);
    }

    /**
     * interface for spoofing Shelly Pro 3 EM
     * @Route("/rpc/EM.GetStatus", name="EMStatus")
     */
    public function emStatusAction(OpenWeatherMapConnector $weather)
    {
        $value = $this->smartfox->getShellyPro3EMResponse($weather->getRelevantCloudsNextDaylightPeriod());

        return new JsonResponse($value);
    }

    /**
     * interface for spoofing Fronius Meter Solar API v1
     * @Route("/solar_api/v1/GetMeterRealtimeData.cgi", name="FroniusV1Status")
     */
    public function froniusV1StatusAction(OpenWeatherMapConnector $weather)
    {
        $value = $this->smartfox->getFroniusV1MeterResponse($weather->getRelevantCloudsNextDaylightPeriod());

        return new Response(json_encode($value, JSON_FORCE_OBJECT));
    }

    /**
     * callback interface for external authentication responses
     * currently only supports responses for netatmo
     * @Route("/extauth/{service}", name="extAuth")
     */
    public function extAuthAction(Request $request, $service)
    {
        if ($service === 'netatmo_code') {
            $state = $request->query->get('state');
            $code = $request->query->get('code');
            $this->netatmo->storeAuthConfig($state, $code, null, null);
        }

        return $this->redirectToRoute('homepage');
    }
}
