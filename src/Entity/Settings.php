<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: \App\Repository\SettingsRepository::class)]
#[ORM\Table(name: 'settings')]
class Settings
{
    /**
     * @var integer
     */
    #[ORM\Column(type: 'integer')]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
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
     */
    #[ORM\Column(type: 'string', nullable: true)]
    #[Assert\NotBlank]
    private $type;

    /**
     * Set the type of the connector.
     *
     * @param string $type
     *
     * @return $this
     */
    public function setType($type)
    {
        $this->type = $type;

        return $this;
    }


    /**
     * Get the type of the connector.
     *
     * @return string
     */
    public function getType()
    {
        return $this->type;
    }

    /**
     * @var string
     */
    #[ORM\Column(type: 'string', nullable: false)]
    #[Assert\NotBlank]
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
     */
    #[ORM\Column(type: 'integer', nullable: true)]
    private $mode;

    public const MODE_WARMWATER = -2;
    public const MODE_HOLIDAY = -1;
    public const MODE_AUTO = 0;
    public const MODE_MANUAL = 1;

    /**
     * Set mode
     *
     * @param int $mode
     *
     * @return Settings $this
     */
    public function setMode($mode)
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
     */
    #[ORM\Column(type: 'json', nullable: true)]
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
