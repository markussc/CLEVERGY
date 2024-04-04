<?php

namespace App\Repository;

/**
 * OpenWeatherMapDataStoreRepository
 */
class OpenWeatherMapDataStoreRepository extends DataStoreBaseRepository
{
    public function getLatest($id)
    {
        return parent::getLatestByType($id, 0);
    }
}
