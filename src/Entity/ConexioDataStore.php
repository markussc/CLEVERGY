<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass="App\Repository\ConexioDataStoreRepository")
 */
class ConexioDataStore extends DataStoreBase
{
    protected $archiveClass = ConexioDataArchive::class;

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
