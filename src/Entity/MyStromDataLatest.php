<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass="App\Repository\DataLatestBaseRepository")
 */
class MyStromDataLatest extends DataLatestBase
{
    /**
     * @var bool
     *
     * @ORM\Column(type="boolean")
     */
    private $boolValue;

    /**
     * @var array
     *
     * @ORM\Column(type="json")
     */
    private $jsonValue;

    /**
     * Set the data.
     *
     * @param bool $data
     *
     * @return MyStromDataLatest $this
     */
    public function setData($data = false)
    {
        $this->boolValue = $data;

        return $this;
    }

    public function getData()
    {
        return $this->boolValue;
    }

    /**
     * Set the extended data.
     *
     * @param array $data
     *
     * @return MyStromDataLatest $this
     */
    public function setExtendedData($data = array())
    {
        $this->jsonValue = $data;

        return $this;
    }

    public function getExtendedData()
    {
        return $this->jsonValue;
    }
}
