<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\Event\LifecycleEventArgs;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Base class for custom field tables
 */
#[ORM\Entity]
#[ORM\InheritanceType('SINGLE_TABLE')]
#[ORM\DiscriminatorColumn('discr_type', type: 'string')]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(name: 'data_store')]
#[ORM\Index(name: 'connector_timestamp_idx', columns: ['connector_id', 'timestamp'])]
#[ORM\Index(name: 'discr_type_connector_idx_timestamp', columns: ['discr_type', 'connector_id', 'timestamp'])]
abstract class DataStoreBase
{
    /**
     * @var integer
     */
    #[ORM\Column(type: 'integer')]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    private $id;

    /**
     * @var string
     */
    #[ORM\Column(type: 'string', name: 'connector_id', nullable: false)]
    #[Assert\NotBlank]
    protected $connectorId;

    /**
     *
     * @var class
     */
    protected $latestClass;

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
     */
    #[ORM\Column(type: 'datetime')]
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

    #[ORM\PrePersist] // Will be invoked when EntityManager::persist is called,
    public function onPrePersist(LifecycleEventArgs $eventArgs): void
    {
        $latest = self::createLatest($eventArgs->getObjectManager(), $this);
        if ($latest) {
            $eventArgs->getObjectManager()->persist($latest);
        }
    }

    /**
    * Returns an instance of XXXDataLatest with all class properties copied.
    * @param XXXDataStore $item - the Item to copy properties from
    * @return XXXDataLatest
    */
    protected static function createLatest(EntityManagerInterface $em, $item)
    {
        $latest = null;
        if (isset($item->latestClass)) {
            $latest = $em->getRepository($item->latestClass)->getLatest($item->getConnectorId());
            if (!$latest) {
                $latest = new $item->latestClass;
            }

            foreach (get_object_vars($item) as $key => $value) {
                $setter = 'set' . ucfirst($key);
                if (is_callable([$latest, $setter])) {
                    $latest->$setter($value);
                }
            }
            // set data
            $latest->setData($item->getData());
            // set extended data if possible
            if (method_exists($latest, "setExtendedData") && method_exists($item, "getExtendedData")) {
                $latest->setExtendedData($item->getExtendedData());
            }
        }

      return $latest;
    }

    /**
     * Get value of this connector.
     *
     * @return many
     */
    abstract public function getData();
}
