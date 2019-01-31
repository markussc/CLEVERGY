<?php

namespace AppBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @ORM\Entity(repositoryClass="AppBundle\Entity\SettingsRepository")
 * @ORM\Table(name="settings")
 */
class Settings
{
    /**
     * @var integer
     *
     * @ORM\Column(type="integer")
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    private $id;

    /**
     * Get id
     *
     * @return integer
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * @var string
     *
     * @ORM\Column(type="string", nullable=false)
     * @Assert\NotBlank()
     */
    private $connectorId;

    /**
     * Set the id of the connector.
     *
     * @param string $connectorId
     *
     * @return $this
     */
    public function setConnectorId($connectorId)
    {
        $this->connectorId = $connectorId;

        return $this;
    }


    /**
     * Get the id of the connector.
     *
     * @return string
     */
    public function getConnectorId()
    {
        return $this->connectorId;
    }

    /**
     * @var int
     *
     * @ORM\Column(type="integer", nullable = true)
     */
    private $mode;

    const MODE_AUTO = 0;
    const MODE_MANUAL = 1;

    /**
     * Set mode
     *
     * @param int $mode
     *
     * @return Settings $this
     */
    public function setMode()
    {
        $this->mode = $mode;

        return $this;
    }

    public function getMode()
    {
        return $this->mode;
    }

    /**
     * @var array
     *
     * @ORM\Column(type="json", nullable = true)
     */
    private $config;

    /**
     * Set config
     *
     * @param array $config
     *
     * @return Settings $this
     */
    public function setConfig($config)
    {
        $this->config = $config;

        return $this;
    }

    public function getConfig()
    {
        return $this->config;
    }
}
