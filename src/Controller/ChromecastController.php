<?php

namespace App\Controller;

use App\Utils\Connectors\ChromecastConnector;
use App\Utils\Connectors\MyStromConnector;
use App\Entity\Settings;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Chromecast controller.
 *
 * @Route("/cc")
 */
class ChromecastController extends AbstractController
{
    private $mystrom;
    private $cc;

    public function __construct(MyStromConnector $mystrom, ChromecastConnector $ccConnector)
    {
        $this->mystrom = $mystrom;
        $this->ccConnector = $ccConnector;
    }

    /**
     * @Route("/power/{ccId}/{power}", name="chromecast_power")
     */
    public function powerAction($ccId, $power)
    {
        $em = $this->getDoctrine()->getManager();
        $chromecast = $this->getParameter('connectors')['chromecast'][$ccId];
        $ip = $chromecast['ip'];
        $settings = $em->getRepository('App:Settings')->findOneByConnectorId($ip);
        if (!$settings) {
            $settings = new Settings();
            $settings->setConnectorId($ip);
        }
        if ($power == -1) {
            // we want to toggle the power
            if ($settings->getMode()) {
                $power = 0;
            } else {
                $power = 1;
            }
        }
        if ($power) {
            // turn on
            $settings->setMode(1);
            if (array_key_exists('mystrom', $chromecast)) {
                foreach ($chromecast['mystrom'] as $mystromId) {
                    $this->mystrom->executeCommand($mystromId, 1);
                }
            }
            // wait a few seconds until chromecast might be ready
            sleep(20);
        } else {
            // turn off
            $settings->setMode(0);
            $settings->setConfig([
                'url' => false,
                'state' => 'stopped',
            ]);
            if (array_key_exists('mystrom', $chromecast)) {
                foreach ($chromecast['mystrom'] as $mystromId) {
                    $this->mystrom->executeCommand($mystromId, 0);
                }
            }
        }
        $em->persist($settings);
        $em->flush();

        return new JsonResponse(['success' => true]);
    }

    /**
     * @Route("/play/{ccId}/{streamId}", name="chromecast_play")
     */
    public function playAction($ccId, $streamId)
    {
        $chromecast = $this->getParameter('connectors')['chromecast'][$ccId];
        $stream = $chromecast['streams'][$streamId];
        $metadata = [];
        if (isset($stream['metadata'])) {
            $metadata = $stream['metadata'];
        }
        $success = $this->ccConnector->startStream($chromecast['ip'], $stream['url'], $metadata);

        return new JsonResponse(['success' => $success]);
    }

    /**
     * @Route("/stop/{ccId}", name="chromecast_stop")
     */
    public function stopAction($ccId)
    {
        $chromecast = $this->getParameter('connectors')['chromecast'][$ccId];
        $success = $this->ccConnector->stopStream($chromecast['ip']);

        return new JsonResponse(['success' => $success]);
    }

    /**
     * @Route("/volume_up/{ccId}", name="chromecast_volume_up")
     */
    public function volumeUpAction($ccId)
    {
        $chromecast = $this->getParameter('connectors')['chromecast'][$ccId];
        $success = $this->ccConnector->volumeUp($chromecast['ip']);

        return new JsonResponse(['success' => $success]);
    }

    /**
     * @Route("/volume_down/{ccId}", name="chromecast_volume_down")
     */
    public function volumeDownAction($ccId)
    {
        $chromecast = $this->getParameter('connectors')['chromecast'][$ccId];
        $success = $this->ccConnector->volumeDown($chromecast['ip']);

        return new JsonResponse(['success' => $success]);
    }
}
