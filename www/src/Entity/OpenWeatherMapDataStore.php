<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: \App\Repository\OpenWeatherMapDataStoreRepository::class)]
class OpenWeatherMapDataStore extends DataStoreBase
{
    protected $latestClass = OpenWeatherMapDataLatest::class;

    /**
     * @var bool
     */
    #[ORM\Column(type: 'json')]
    private $jsonValue;

    /**
     * Set the data.
     *
     * @param array $data
     *
     * @return OpenWeatherMapDataStorage $this
     */
    public function setData($data = [])
    {
        $this->jsonValue = $data;

        return $this;
    }

    public function getData()
    {
        return $this->jsonValue;
    }
}
