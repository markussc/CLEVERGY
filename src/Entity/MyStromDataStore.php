<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass="App\Repository\MyStromDataStoreRepository")
 */
class MyStromDataStore extends DataStoreBase
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
     * @ORM\Column(type="json_array")
     */
    private $jsonValue;

    /**
     * Set the data.
     *
     * @param bool $data
     *
     * @return MyStromDataStorage $this
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
     * @return MyStromDataStorage $this
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
