<?php

namespace AppBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass="AppBundle\Entity\ShellyDataStoreRepository")
 */
class ShellyDataStore extends DataStoreBase
{
    /**
     * @var array
     *
     * @ORM\Column(type="json_array")
     */
    private $jsonValue;

    /**
     * Set the data.
     *
     * @param array $data
     *
     * @return ShellyDataStorage $this
     */
    public function setData($data = array())
    {
        $this->jsonValue = $data;

        return $this;
    }

    public function getData()
    {
        return $this->jsonValue;
    }
}
