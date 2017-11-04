<?php

namespace AppBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity
 */
class EdiMaxDataStore extends DataStoreBase
{
    /**
     * @var bool
     *
     * @ORM\Column(type="boolean")
     */
    private $boolValue;

    /**
     * Set the data.
     *
     * @param bool $data
     *
     * @return EdiMaxDataStorage $this
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
}
