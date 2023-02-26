<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass="App\Repository\NetatmoDataStoreRepository")
 */
class NetatmoDataStore extends DataStoreBase
{
    protected $archiveClass = NetatmoDataArchive::class;

    /**
     * @var bool
     *
     * @ORM\Column(type="json")
     */
    private $jsonValue;

    /**
     * Set the data.
     *
     * @param array $data
     *
     * @return NetatmoDataStorage $this
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

    public function getStationData()
    {
        $data = json_decode($this->getData(), true);
        return [
            'name' => $data['body']['devices'][0]['module_name'],
            'temp' => $data['body']['devices'][0]['dashboard_data']['Temperature'],
            'humidity' => $data['body']['devices'][0]['dashboard_data']['Humidity'],
        ];
    }

    public function getModuleData($id)
    {
        $data = json_decode($this->getData(), true);
        foreach ($data['body']['devices'][0]['modules'] as $module) {
            if ($module['_id'] == $id && array_key_exists('dashboard_data', $module)) {
                return [
                    'name' => $module['module_name'],
                    'temp' => $module['dashboard_data']['Temperature'],
                    'humidity' => $module['dashboard_data']['Humidity'],
                    'battery' => $module['battery_percent'],
                ];
            }
        }
    }

    public function getModulesData()
    {
        $data = json_decode($this->getData(), true);
        $modulesData = [];
        foreach ($data['body']['devices'][0]['modules'] as $module) {
            if (array_key_exists('dashboard_data', $module)) {
                $modulesData[$module['_id']] = [
                    'name' => $module['module_name'],
                    'temp' => $module['dashboard_data']['Temperature'],
                    'humidity' => $module['dashboard_data']['Humidity'],
                    'battery' => $module['battery_percent'],
                ];
            }
        }

        return $modulesData;
    }

    public function getAllData()
    {
        return array_merge($this->getStationData(), $this->getModulesData());
    }
}
