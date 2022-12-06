<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass="App\Repository\CommandLogRepository")
 * @ORM\Table(name="commandlog")
 */
class CommandLog
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
     * @var datetime
     *
     * @ORM\Column(type="datetime")
     */
    private $timestamp;

    /**
     * Set the timestamp.
     *
     * @param datetime $timestamp
     *
     * @return CommandLog $this
     */
    public function setTimestamp($timestamp = null)
    {
        $this->timestamp = $timestamp;

        return $this;
    }

    public function getTimestamp()
    {
        return $this->timestamp;
    }

    /**
     * The operation mode of the heat pump
     *
     * @var string
     *
     * @ORM\Column(type="string", nullable = true)
     */
    private $ppMode;

    /**
     * Set ppMode
     *
     * @param string $ppMode
     *
     * @return CommandLog $this
     */
    public function setPpMode($ppMode)
    {
        $this->ppMode = $ppMode;

        return $this;
    }

    public function getPpMode()
    {
        return $this->ppMode;
    }

    /**
     * The pv high power flag
     *
     * @var bool
     *
     * @ORM\Column(type="boolean", nullable = true)
     */
    private $highPvPower;

    /**
     * Set higPvPower
     *
     * @param bool $highPvPower
     *
     * @return CommandLog $this
     */
    public function setHighPvPower($highPvPower)
    {
        $this->highPvPower = $highPvPower;

        return $this;
    }

    public function getHighPvPower()
    {
        return $this->highPvPower;
    }

    /**
     * The average PV power
     *
     * @var int
     *
     * @ORM\Column(type="integer", nullable = true)
     */
    private $avgPvPower;

    /**
     * Set avgPvPower
     *
     * @param int $avgPvPower
     *
     * @return CommandLog $this
     */
    public function setAvgPvPower($avgPvPower)
    {
        $this->avgPvPower = $avgPvPower;

        return $this;
    }

    public function getAvgPvPower()
    {
        return $this->avgPvPower;
    }

    /**
     * The average power
     *
     * @var int
     *
     * @ORM\Column(type="integer", nullable = true)
     */
    private $avgPower;

    /**
     * Set avgPower
     *
     * @param int $avgPower
     *
     * @return CommandLog $this
     */
    public function setAvgPower($avgPower)
    {
        $this->avgPower = $avgPower;

        return $this;
    }

    public function getAvgPower()
    {
        return $this->avgPower;
    }

    /**
     * The inside temperature
     *
     * @var int
     *
     * @ORM\Column(type="float", nullable = true)
     */
    private $insideTemp;

    /**
     * Set insideTemp
     *
     * @param float $insideTemp
     *
     * @return CommandLog $this
     */
    public function setInsideTemp($insideTemp)
    {
        $this->insideTemp = $insideTemp;

        return $this;
    }

    public function getInsideTemp()
    {
        return $this->insideTemp;
    }

    /**
     * The water temperature
     *
     * @var float
     *
     * @ORM\Column(type="float", nullable = true)
     */
    private $waterTemp;

    /**
     * Set waterTemp
     *
     * @param float $waterTemp
     *
     * @return CommandLog $this
     */
    public function setWaterTemp($waterTemp)
    {
        $this->waterTemp = $waterTemp;

        return $this;
    }

    public function getWaterTemp()
    {
        return $this->waterTemp;
    }

    /**
     * The heat storage mid temperature
     *
     * @var float
     *
     * @ORM\Column(type="float", nullable = true)
     */
    private $heatStorageMidTemp;

    /**
     * Set heatStorageMidTemp
     *
     * @param float heatStorageMidTemp
     *
     * @return CommandLog $this
     */
    public function setHeatStorageMidTemp($heatStorageMidTemp)
    {
        $this->heatStorageMidTemp = $heatStorageMidTemp;

        return $this;
    }

    public function getHeatStorageMidTemp()
    {
        return $this->heatStorageMidTemp;
    }

    /**
     * The average clouds
     *
     * @var int
     *
     * @ORM\Column(type="integer", nullable = true)
     */
    private $avgClouds;

    /**
     * Set avgClouds
     *
     * @param int $avgClouds
     *
     * @return CommandLog $this
     */
    public function setAvgClouds($avgClouds)
    {
        $this->avgClouds = $avgClouds;

        return $this;
    }

    public function getAvgClouds()
    {
        return $this->avgClouds;
    }

    /**
     * @var array
     *
     * @ORM\Column(type="json", nullable = true)
     */
    private $log;

    /**
     * Set log
     *
     * @param array $log
     *
     * @return CommandLog $this
     */
    public function setLog($log)
    {
        $this->log = $log;

        return $this;
    }

    public function getLog()
    {
        return $this->log;
    }
}
