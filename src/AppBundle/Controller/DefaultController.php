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
            'mobileAlerts' => $this->get('AppBundle\Utils\Connectors\MobileAlertsConnector')->getAll(),
            'edimax' => $this->get('AppBundle\Utils\Connectors\EdiMaxConnector')->getAllStati(),
        ];

        // render the template
        return $this->render('default/index.html.twig', [
            'currentStat' => $currentStat,
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
            'pcoWeb' => $this->get('AppBundle\Utils\Connectors\PcoWebConnector')->getAll(),
        ];

        // render the template
        return $this->render('default/content.html.twig', [
            'currentStat' => $currentStat,
            'refresh' => true,
        ]);
    }
}
