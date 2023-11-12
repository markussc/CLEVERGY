<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass="App\Repository\TaCmiDataStoreRepository")
 */
class TaCmiDataStore extends DataStoreBase
{
    protected $archiveClass = TaCmiDataArchive::class;
    protected $latestClass = TaCmiDataLatest::class;

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
     * @return LogoControlStorage $this
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
