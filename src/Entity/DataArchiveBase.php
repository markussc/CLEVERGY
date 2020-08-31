<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Base class for custom field tables
 *
 * @ORM\Entity
 * @ORM\Table(name="data_archive", indexes={@ORM\Index(name="timestamp_idx", columns={"timestamp"}),@ORM\Index(name="connector_idx", columns={"connector_id"})})
 * @ORM\InheritanceType("SINGLE_TABLE")
 * @ORM\DiscriminatorColumn("discr_type", type="string")
 */
abstract class DataArchiveBase
{
    /**
     * @var integer
     *
     * @ORM\Column(type="integer")
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    private $id;

    /**
     * @var string
     *
     * @ORM\Column(type="string", name="connector_id", nullable=false)
     * @Assert\NotBlank()
     */
    private $connectorId;

    /**
     * Get id
     *
     * @return integer
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Set the id of the connector.
     *
     * @param string $connectorId
     *
     * @return $this
     */
    public function setConnectorId($connectorId)
    {
        $this->connectorId = $connectorId;

        return $this;
    }


    /**
     * Get the id of the connector.
     *
     * @return string
     */
    public function getConnectorId()
    {
        return $this->connectorId;
    }

    /**
     * @var datetime
     *
     * @ORM\Column(type="datetime")
     */
    private $timestamp;

    /**
     * Set the timestamp.
     *
     * @param datetime $timestamp
     *
     * @return DataStorageBase $this
     */
    public function setTimestamp($timestamp)
    {
        $this->timestamp = $timestamp;

        return $this;
    }

    public function getTimestamp()
    {
        return $this->timestamp;
    }

    /**
     * Returns true when the entity (its data) is empty.
     *
     * @return boolean
     */
    public function isEmpty()
    {
        return empty($this->getData());
    }

    /**
     * Get value of this connector.
     *
     * @return many
     */
    abstract public function getData();
}
