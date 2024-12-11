<?php
/**
 * Процессор для работы с объектами modx.
 */

namespace MpcServices\Processors;

use MpcServices\Helpers\Logging;
use MpcServices\Helpers\Response;

/**
 * @author Arthur Shevchenko (https://t.me/ShevArtV)
 */
class Base
{
    /**
     * @var \ModX
     */
    public \ModX $modx;
    /**
     * @var string
     */
    protected string $className = '';
    /**
     * @var string
     */
    protected string $primaryKey = 'id';
    /**
     * @var Logging
     */
    protected Logging $logging;
    /**
     * @var Response
     */
    protected Response $response;

    /**
     * @param \ModX $modx
     */
    public function __construct(\ModX $modx)
    {
        $this->modx = $modx;
        $this->logging = new Logging();
        $logFileName = str_replace('\\', '-', self::class) . '.txt';
        $this->logging->setPath($logFileName);
        $this->response = new Response($this->logging);
    }

    /**
     * @param array $data
     * @return object|null
     */
    public function create(array $data): ?object
    {
        $object = $this->modx->newObject($this->className);
        return $this->setObjectData($object, $data);
    }

    /**
     * @param array $data
     * @return object|null
     */
    public function update(array $data): ?object
    {
        if(!$object = $this->modx->getObject($this->className, [$this->primaryKey => $data[$this->primaryKey]])){
            return $this->create($data);
        }
        return $this->setObjectData($object, $data);
    }

    /**
     * @param $object
     * @param $data
     * @return mixed|null
     */
    public function setObjectData($object, $data): ?object
    {
        $object->fromArray($data, '', true);
        if(!$object->save()){
            return null;
        }
        return $object;
    }

    /**
     * @param int $id
     * @return true
     */
    public function delete(int $id){
        if(!$object = $this->modx->getObject($this->className, $id)){
            return true;
        }
        return $object->remove();
    }

    /**
     * @param array $conditions
     * @return \xPDOIterator
     */
    public function list(array $conditions): \xPDOIterator
    {
        $q = $this->modx->newQuery($this->className);
        $q->where($conditions);
        return $this->modx->getIterator($this->className, $q);
    }

}
