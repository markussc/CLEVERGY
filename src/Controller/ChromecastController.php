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
    /**
     * @Route("/power/{ccId}/{power}", name="chromecast_power")
     */
    public function powerAction(MyStromConnector $mystrom, $ccId, $power)
    {
        $em = $this->getDoctrine()->getManager();
        $chromecast = $this->getParameter('connectors')['chromecast'][$ccId];
        $ip = $chromecast['ip'];
        $settings = $em->getRepository('App:Settings')->findOneByConnectorId($ip);
        if (!$settings) {
            $settings = new Settings();
            $settings->setConnectorId($ip);
        }
        if ($power) {
            // turn on
            $settings->setMode(1);
            if (array_key_exists('mystrom', $chromecast)) {
                foreach ($chromecast['mystrom'] as $mystromId) {
                    $mystrom->executeCommand($mystromId, 1);
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
                    $mystrom->executeCommand($mystromId, 0);
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
    public function playAction(ChromecastConnector $ccConnector, $ccId, $streamId)
    {
        $chromecast = $this->getParameter('connectors')['chromecast'][$ccId];
        $stream = $chromecast['streams'][$streamId];
        $metadata = [];
        if (isset($stream['metadata'])) {
            $metadata = $stream['metadata'];
        }
        $success = $ccConnector->startStream($chromecast['ip'], $stream['url'], $metadata);

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
