<?php

namespace App\Utils;

use App\Entity\Settings;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Helper to interact with device configuration
 *
 * @author Markus Schafroth
 */
class ConfigManager {
    protected $em;
    protected $connectors;

    public function __construct(EntityManagerInterface $em, Array $connectors)
    {
        $this->em = $em;
        $this->connectors = $connectors;
    }

    public function getConnectorIds($type)
    {
        $connectorIds = [];
        if (array_key_exists($type, $this->connectors) && is_array($this->connectors[$type])) {
            foreach ($this->connectors[$type] as $device) {
                switch ($type) {
                    case $type === 'mystrom':
                        if (is_array($device) && array_key_exists('ip', $device)) {
                            $connectorIds[] = $device['ip'];
                        }
                        break;
                    case $type === 'shelly':
                        if (is_array($device) && array_key_exists('ip', $device) && array_key_exists('port', $device)) {
                            // valid connectorId for switches/rollers contain ip and port
                            $connectorIds[] = $device['ip'].'_'.$device['port'];
                        } elseif (is_array($device) && array_key_exists('ip', $device)) {
                            // valid connectorId for doors contain ip only
                            $connectorIds[] = $device['ip'];
                        }
                        break;
                }
            }
        }

        return $connectorIds;
    }

    public function getConfig($type, $connectorId)
    {
        $config = null;
        // get static configuration from parameters.yml
        if (array_key_exists($type, $this->connectors) && is_array($this->connectors[$type])) {
            foreach ($this->connectors[$type] as $device) {
                switch ($type) {
                    case $type === 'mystrom':
                        if (is_array($device) && array_key_exists('ip', $device) && $device['ip'] === $connectorId) {
                            $config = $device;
                            break 2;
                        }
                        break;
                    case $type === 'shelly':
                        if (is_array($device) && array_key_exists('ip', $device) && ($device['ip'].'_0' === $connectorId || (array_key_exists('port', $device) && $device['ip'].'_'.$device['port'] === $connectorId))) {
                            $config = $device;
                            break 2;
                        }
                        break;
                }
            }
        }

        // get dynamic configuration from DB (settings-table, config field) if we found a static configuration for this device
        if ($config !== null) {
            $settings = $this->em->getRepository(Settings::class)->findOneByConnectorId($connectorId);
            if ($settings) {
                $dynamicConfig = $settings->getConfig();
                if (is_array($dynamicConfig)) {
                    $config = array_merge($config, $dynamicConfig);
                }
            }
        }

        return $config;
    }

    /*
     * update whole or parts of config
     */
    public function updateConfig($connectorId, $newConfig)
    {
        $settings = $this->em->getRepository(Settings::class)->findOneByConnectorId($connectorId);
        if (!$settings) {
            $settings = new Settings();
            $settings->setConnectorId($connectorId);
            $settings->setMode(Settings::MODE_AUTO);
            $settings->setConfig($newConfig);
            $this->em->persist($settings);
            $this->em->flush();
        }
        else {
            // found existing entry
            $config = $settings->getConfig();
            if ($config === null) {
                $config = [];
            }
            if (is_array($newConfig)) {
                $config = array_merge($config, $newConfig);
            }
            $settings->setConfig($config);
            $this->em->flush();
            return true;
        }
    }

    public function hasDynamicConfig($connectorId)
    {
        $settings = $this->em->getRepository(Settings::class)->findOneByConnectorId($connectorId);
        if ($settings) {
            return true;
        } else {
            return false;
        }
    }
}
