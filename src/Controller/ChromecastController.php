<?php

namespace App\Controller;

use App\Utils\Connectors\ChromecastConnector;
use App\Utils\Connectors\MyStromConnector;
use App\Entity\Settings;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Chromecast controller.
 */
#[Route(path: '/cc')]
class ChromecastController extends AbstractController
{
    private $em;
    private $mystrom;
    private $ccConnector;

    public function __construct(EntityManagerInterface $em, MyStromConnector $mystrom, ChromecastConnector $ccConnector)
    {
        $this->em = $em;
        $this->mystrom = $mystrom;
        $this->ccConnector = $ccConnector;
    }

    #[Route(path: '/power/{ccId}/{power}', name: 'chromecast_power')]
    public function power($ccId, $power)
    {
        $chromecast = $this->getParameter('connectors')['chromecast'][$ccId];
        $ip = $chromecast['ip'];
        $settings = $this->em->getRepository(Settings::class)->findOneByConnectorId($ip);
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
        $this->em->persist($settings);
        $this->em->flush();

        return new JsonResponse(['success' => true]);
    }

    #[Route(path: '/play/{ccId}/{streamId}', name: 'chromecast_play')]
    public function play($ccId, $streamId)
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

    #[Route(path: '/stop/{ccId}', name: 'chromecast_stop')]
    public function stop($ccId)
    {
        $chromecast = $this->getParameter('connectors')['chromecast'][$ccId];
        $success = $this->ccConnector->stopStream($chromecast['ip']);

        return new JsonResponse(['success' => $success]);
    }

    #[Route(path: '/volume_up/{ccId}', name: 'chromecast_volume_up')]
    public function volumeUp($ccId)
    {
        $chromecast = $this->getParameter('connectors')['chromecast'][$ccId];
        $success = $this->ccConnector->volumeUp($chromecast['ip']);

        return new JsonResponse(['success' => $success]);
    }

    #[Route(path: '/volume_down/{ccId}', name: 'chromecast_volume_down')]
    public function volumeDown($ccId)
    {
        $chromecast = $this->getParameter('connectors')['chromecast'][$ccId];
        $success = $this->ccConnector->volumeDown($chromecast['ip']);

        return new JsonResponse(['success' => $success]);
    }
}
