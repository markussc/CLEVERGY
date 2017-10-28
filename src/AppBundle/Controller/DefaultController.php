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
            'smartFox' => [
                'power' => $this->get('AppBundle\Utils\Connectors\SmartFoxConnector')->getPower(),
            ],
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

    private function executeCommand($command)
    {
        $command = json_decode($command);
        switch ($command[0]) {
            case 'edimax':
                return $this->get('AppBundle\Utils\Connectors\EdiMaxConnector')->executeCommand($command[1], $command[2]);
        }
        // no known device
        return false;
    }
}
