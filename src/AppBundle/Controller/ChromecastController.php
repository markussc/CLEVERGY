<?php

namespace AppBundle\Controller;

use AppBundle\Utils\Connectors\ChromecastConnector;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Chromecast controller.
 *
 * @Route("/cc")
 */
class ChromecastController extends Controller
{
    /**
     * @Route("/play/{ccId}/{streamId}", name="chromecast_play")
     */
    public function playAction(ChromecastConnector $ccConnector, $ccId, $streamId)
    {
        $chromecast = $this->getParameter('connectors')['chromecast'][$ccId];
        $stream = $chromecast['streams'][$streamId];
        $success = $ccConnector->startStream($chromecast['ip'], $stream['url']);

        return new JsonResponse(['success' => $success]);
    }

    /**
     * @Route("/stop/{ccId}", name="chromecast_stop")
     */
    public function stopAction(ChromecastConnector $ccConnector, $ccId)
    {
        $chromecast = $this->getParameter('connectors')['chromecast'][$ccId];
        $success = $ccConnector->stopStream($chromecast['ip']);

        return new JsonResponse(['success' => $success]);
    }

    /**
     * @Route("/volume_up/{ccId}", name="chromecast_volume_up")
     */
    public function volumeUpAction(ChromecastConnector $ccConnector, $ccId)
    {
        $chromecast = $this->getParameter('connectors')['chromecast'][$ccId];
        $success = $ccConnector->volumeUp($chromecast['ip']);

        return new JsonResponse(['success' => $success]);
    }

    /**
     * @Route("/volume_down/{ccId}", name="chromecast_volume_down")
     */
    public function volumeDownAction(ChromecastConnector $ccConnector, $ccId)
    {
        $chromecast = $this->getParameter('connectors')['chromecast'][$ccId];
        $success = $ccConnector->volumeDown($chromecast['ip']);

        return new JsonResponse(['success' => $success]);
    }
}
