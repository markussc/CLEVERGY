<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\Event\LifecycleEventArgs;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Base class for custom field tables
 *
 * @ORM\Entity
 * @ORM\Table(name="data_store", indexes={@ORM\Index(name="connector_timestamp_idx", columns={"connector_id", "timestamp"}),@ORM\Index(name="discr_type_connector_idx_timestamp", columns={"discr_type", "connector_id", "timestamp"})})
 * @ORM\InheritanceType("SINGLE_TABLE")
 * @ORM\DiscriminatorColumn("discr_type", type="string")
 * @ORM\HasLifecycleCallbacks
 */
abstract class DataStoreBase
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
    protected $connectorId;

    /**
     *
     * @var class
     */
    protected $archiveClass;

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
    protected $timestamp;

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
    * @ORM\PreRemove
    * Will be invoked when EntityManager::remove is called, 
    * to persist a copy of the Entity in the archive table. 
    */
    public function onPreRemove(LifecycleEventArgs $eventArgs)
    {
        $archive = self::createArchive($this);
        if ($archive) {
            $eventArgs->getEntityManager()->persist($archive);
        }
    }

    /**
    * Returns an instance of XXXDataArchive with all class properties copied. 
    * @param XXXDataStore $item - the Item to copy properties from 
    * @return XXXDataArchive
    */
    protected static function createArchive($item)
    {
        $archive = null;
        if (isset($item->archiveClass)) {
            $archive = new $item->archiveClass;
            foreach (get_object_vars($item) as $key => $value) {
                $setter = 'set' . ucfirst($key);
                if (is_callable([$archive, $setter])) {
                    $archive->$setter($value);
                }
            }
            // set data
            $archive->setData($item->getData());
        }

      return $archive;
    }

    /**
     * Get value of this connector.
     *
     * @return many
     */
    abstract public function getData();
}
