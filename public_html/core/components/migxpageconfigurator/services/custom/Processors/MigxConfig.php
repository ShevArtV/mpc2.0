<?php

/**
 * Процессор для работы с migxConfig.
 */

namespace MpcServices\Processors;

/**
 * @author Arthur Shevchenko (https://t.me/ShevArtV)
 */
class MigxConfig extends Base
{
    public function __construct(\ModX $modx)
    {
        parent::__construct($modx);
        $modx->addPackage('migx', MODX_CORE_PATH . 'components/migx/model/');
    }

    public function copy(string $donorName, string $newName)
    {
        if (!$donor = $this->modx->getObject('migxConfig', ['name' => $donorName])) {
            return false;
        }
        $data = $donor->toArray();
        $data['name'] = $newName;
        unset($data['id']);
        return $this->create($data);
    }

    public function create(array $data): ?object
    {
        if($object = $this->modx->getObject('migxConfig', ['name' => $data['name']])){
           return $object;
        }
        $object = $this->modx->newObject('migxConfig');
        $object->fromArray($data, '', true);
        $object->save();
        return $object;
    }
}
