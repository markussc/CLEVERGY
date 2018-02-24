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

        $history = [
            'smartFox' => $em->getRepository('AppBundle:SmartFoxDataStore')->getHistoryLast24h($this->get('AppBundle\Utils\Connectors\SmartFoxConnector')->getIp()),
            'pcoWeb' => $em->getRepository('AppBundle:PcoWebDataStore')->getHistoryLast24h($this->get('AppBundle\Utils\Connectors\PcoWebConnector')->getIp()),
            'mobileAlerts' => $em->getRepository('AppBundle:MobileAlertsDataStore')->getHistoryLast24h($this->get('AppBundle\Utils\Connectors\MobileAlertsConnector')->getId(0)),
            'conexio' => $em->getRepository('AppBundle:ConexioDataStore')->getHistoryLast24h($this->get('AppBundle\Utils\Connectors\ConexioConnector')->getIp()),
        ];

        // render the template
        return $this->render('default/index.html.twig', [
            'activePage' => 'homepage',
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
        return $this->redirectToRoute('homepage');
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
                        $settings = $this->getDoctrine()->getManager()->getRepository('AppBundle:Settings')->findOneByConnectorId($connectorId);
                        if (!$settings) {
                            $settings = new Settings();
                            $settings->setConnectorId($connectorId);
                            $this->getDoctrine()->getManager()->persist($settings);
                        }
                        $settings->setMode($command[3]);
                    }
                    $this->getDoctrine()->getManager()->flush();
                    return true;
                }
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

        // render the template
        return $this->render('default/content.html.twig', [
            'currentStat' => $currentStat,
            'refresh' => true,
        ]);
    }

    /**
     * @Route("/history", name="history")
     */
    public function historyAction(Request $request)
    {
        $currentStat = [
            'smartFox' => $this->get('AppBundle\Utils\Connectors\SmartFoxConnector')->getAll(true),
            'smartFoxChart' => true,
            'pcoWeb' => $this->get('AppBundle\Utils\Connectors\PcoWebConnector')->getAll(),
            'conexio' => $this->get('AppBundle\Utils\Connectors\ConexioConnector')->getAll(true),
            'mobileAlerts' => $this->get('AppBundle\Utils\Connectors\MobileAlertsConnector')->getAllLatest(),
            'edimax' => $this->get('AppBundle\Utils\Connectors\EdiMaxConnector')->getAll(),
            'openweathermap' => $this->get('AppBundle\Utils\Connectors\OpenWeatherMapConnector')->getAllLatest(),
        ];

        $em = $this->getDoctrine()->getManager();

        $history = [
            'smartFox' => $em->getRepository('AppBundle:SmartFoxDataStore')->getHistoryLast24h($this->get('AppBundle\Utils\Connectors\SmartFoxConnector')->getIp()),
            'pcoWeb' => $em->getRepository('AppBundle:PcoWebDataStore')->getHistoryLast24h($this->get('AppBundle\Utils\Connectors\PcoWebConnector')->getIp()),
            'mobileAlerts' => $em->getRepository('AppBundle:MobileAlertsDataStore')->getHistoryLast24h($this->get('AppBundle\Utils\Connectors\MobileAlertsConnector')->getId(0)),
            'conexio' => $em->getRepository('AppBundle:ConexioDataStore')->getHistoryLast24h($this->get('AppBundle\Utils\Connectors\ConexioConnector')->getIp()),
        ];

        // render the template
        return $this->render('default/index.html.twig', [
            'activePage' => 'history',
            'currentStat' => $currentStat,
            'history' => $history,
        ]);
    }
}
