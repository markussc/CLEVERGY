<?php

namespace AppBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

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
     * @var int
     *
     * @ORM\Column(type="integer")
     */
    private $mode;

    const MODE_AUTO = 0;
    const MODE_MANUAL_PCOWEB = 1;

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
}
