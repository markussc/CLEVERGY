<?php

namespace AppBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\Event\LifecycleEventArgs;

/**
 * @ORM\Entity(repositoryClass="AppBundle\Entity\SmartFoxDataStoreRepository")
 * @ORM\HasLifecycleCallbacks
 */
class SmartFoxDataStore extends DataStoreBase
{
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
     * @return SmartFoxDataStorage $this
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

    /**
    * Returns an instance of SmartFoxDataArchive with all class properties copied. 
    * @param Item $item - the Item to copy properties from 
    * @return ItemArchive
    */
    protected static function createArchive(SmartFoxDataStore $item)
    {
        $archive = new SmartFoxDataArchive();
        foreach (get_object_vars($item) as $key => $value) {
            $setter = 'set' . ucfirst($key);
            if (is_callable([$archive, $setter])) {
                $archive->$setter($value);
            }
        }

      return $archive;
    }

    /**
    * @ORM\PreRemove
    * Will be invoked when EntityManager::remove is called, 
    * to persist a copy of the Entity in the archive table. 
    */
    public function onPreRemove(LifecycleEventArgs $eventArgs)
    {
        $archive = self::createArchive($this);
        $eventArgs->getEntityManager()->persist($archive);
    }
}
