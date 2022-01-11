<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity()
 */
class ConexioDataArchive extends DataArchiveBase
{
    /**
     * @var array
     *
     * @ORM\Column(type="json")
     */
    private $jsonValue;

    /**
     * Set the data.
     *
     * @param array $data
     *
     * @return ConexioDataStorage $this
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
