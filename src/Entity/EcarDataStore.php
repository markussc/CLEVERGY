<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: \App\Repository\EcarDataStoreRepository::class)]
class EcarDataStore extends DataStoreBase
{
    protected $latestClass = EcarDataLatest::class;

    /**
     * @var array
     */
    #[ORM\Column(type: 'json')]
    private $jsonValue;

    /**
     * Set the data.
     *
     * @param array $data
     *
     * @return EcarDataStorage $this
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
