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
        ];

        // render the template
        return $this->render('default/index.html.twig', [
            'currentStat' => $currentStat,
        ]);
    }
}
