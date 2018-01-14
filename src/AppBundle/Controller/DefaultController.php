<?php

namespace AppBundle\Controller;

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
            'smartFox' => $this->get('AppBundle\Utils\Connectors\SmartFoxConnector')->getAll(),
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
            'smartFox' => $this->get('AppBundle\Utils\Connectors\SmartFoxConnector')->getAll(),
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
}
