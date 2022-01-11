<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass="App\Repository\WemDataStoreRepository")
 */
class WemDataStore extends DataStoreBase
{
    protected $archiveClass = PcoWebDataArchive::class;

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
     * @return WemDataStorage $this
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
