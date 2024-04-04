<?php

namespace App\Repository;

/**
 * NetatmoDataStoreRepository
 */
class NetatmoDataStoreRepository extends DataStoreBaseRepository
{
    public function getLatest($id)
    {
        return parent::getLatestByType($id, 0);
    }
}
