<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: \App\Repository\SmartFoxDataStoreRepository::class)]
class SmartFoxDataStore extends DataStoreBase
{
    protected $latestClass = SmartFoxDataLatest::class;

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
     * @return SmartFoxDataStorage $this
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
