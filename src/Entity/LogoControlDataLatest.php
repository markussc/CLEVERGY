<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: \App\Repository\DataLatestBaseRepository::class)]
class LogoControlDataLatest extends DataLatestBase
{
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
     * @return LogoControlStorage $this
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
