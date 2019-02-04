<?php

namespace AppBundle\Utils\Connectors;

use Doctrine\ORM\EntityManager;
use AppBundle\Entity\Settings;
use AppBundle\lib\CastV2inPHP\Chromecast;

/**
 * Connector to interact with Google Chromecast devices
 *
 * @author Markus Schafroth
 */
class ChromecastConnector
{
    protected $em;
    protected $connectors;

    public function __construct(EntityManager $em, Array $connectors, $browser)
    {
        $this->em = $em;
        $this->connectors = $connectors;
    }

    /**
     * Reads the current URL for a chromecast device from the database
     * @return array
     */
    public function getUrl($ip)
    {
        $settings = $this->em->getRepository('AppBundle:Settings')->findOneByConnectorId($ip);
        $url = false;
        if ($settings) {
            $conf = $settings->getConfig();
            if ($conf && array_key_exists('url', $conf)) {
                $url = $conf['url'];
            }
        }

        return $url;
    }

    /**
     * Reads the current power state for a chromecast device from the database
     * @return array
     */
    public function getPower($ip)
    {
        $settings = $this->em->getRepository('AppBundle:Settings')->findOneByConnectorId($ip);
        $power = 0;
        if ($settings) {
            $power = $settings->getMode();
        }

        return $power;
    }

    /**
     * Reads the current state for a chromecast device from the database
     * @return array
     */
    public function getState($ip)
    {
        $settings = $this->em->getRepository('AppBundle:Settings')->findOneByConnectorId($ip);
        $state = false;
        if ($settings) {
            $conf = $settings->getConfig();
            if ($conf && array_key_exists('state', $conf)) {
                $state = $conf['state'];
            }
        }

        return $state;
    }

    public function startStream($ip, $url, $metadata = [], $type = "audio/mp4")
    {
        $settings = $this->em->getRepository('AppBundle:Settings')->findOneByConnectorId($ip);
        if (!$settings) {
            $settings = new Settings();
            $settings->setConnectorId($ip);
            $this->em->persist($settings);
        }
        // set loading state
        $settings->setConfig([
            'url' => $url,
            'state' => 'working',
        ]);
        $this->em->flush();

        try {
            $cc = new Chromecast($ip, "8009");
            $cc->DMP->play($url, "LIVE", $type, true, 0, $metadata);
            $cc->DMP->UnMute();
            $cc->DMP->SetVolume(0.5);
        } catch (\Exception $e) {
            // reset state to stopped
            $settings->setConfig([
                'url' => false,
                'state' => 'stopped',
            ]);
            $this->em->flush();
            return false;
        }

        // set playing state
        $settings->setConfig([
            'url' => $url,
            'mediaid' => $cc->DMP->mediaid,
            'state' => 'playing',
        ]);
        $this->em->flush();

        return true;
    }

    public function stopStream($ip)
    {
        $settings = $this->em->getRepository('AppBundle:Settings')->findOneByConnectorId($ip);
        if ($settings) {
            $config = $settings->getConfig();
            // set working state
            $config['state'] = 'working';
            $settings->setConfig($config);
            $this->em->flush();
            try {
                $cc = new Chromecast($ip, "8009");
                $cc->DMP->mediaid = $config['mediaid'];
                $cc->DMP->getStatus();
                $cc->DMP->stop();
            } catch (\Exception $e) {
                return false;
            }
        }

        // set stopped state
        $settings->setConfig([
                    'url' => false,
                    'mediaid' => false,
                    'state' => 'stopped'
                ]);
        $this->em->flush();

        return true;
    }

    public function volumeUp($ip)
    {
        $settings = $this->em->getRepository('AppBundle:Settings')->findOneByConnectorId($ip);
        if ($settings) {
            $config = $settings->getConfig();
            if (!array_key_exists('volume', $config)) {
                $config['volume'] = 0.5;
            }
            try {
                $cc = new Chromecast($ip, "8009");
                $cc->DMP->mediaid = $config['mediaid'];
                $cc->DMP->getStatus();
                if ($config['volume'] <= 0.9) {
                    $config['volume'] += 0.1;
                    $cc->DMP->SetVolume($config['volume']);
                }
                $settings->setConfig($config);
                $this->em->flush();
            } catch (\Exception $e) {
                return false;
            }
        }

        return true;
    }

    public function volumeDown($ip)
    {
        $settings = $this->em->getRepository('AppBundle:Settings')->findOneByConnectorId($ip);
        if ($settings) {
            $config = $settings->getConfig();
            if (!array_key_exists('volume', $config)) {
                $config['volume'] = 0.5;
            }
            try {
                $cc = new Chromecast($ip, "8009");
                $cc->DMP->mediaid = $config['mediaid'];
                $cc->DMP->getStatus();
                if ($config['volume'] >= 0.1) {
                    $config['volume'] -= 0.1;
                    $cc->DMP->SetVolume($config['volume']);
                }
                $settings->setConfig($config);
                $this->em->flush();
            } catch (\Exception $e) {
                return false;
            }
        }

        return true;
    }
}
