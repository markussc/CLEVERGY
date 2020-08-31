<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass="App\Repository\OpenWeatherMapDataStoreRepository")
 */
class OpenWeatherMapDataStore extends DataStoreBase
{
    protected $archiveClass = OpenWeatherMapDataArchive::class;

    /**
     * @var bool
     *
     * @ORM\Column(type="json_array")
     */
    private $jsonValue;

    /**
     * Set the data.
     *
     * @param array $data
     *
     * @return OpenWeatherMapDataStorage $this
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
