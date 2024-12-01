<?php
/**
 * Процессор для работы с TemplateVarTemplate.
 */

namespace CustomServices\Processors;

/**
 * @author Arthur Shevchenko (https://t.me/ShevArtV)
 */
class TemplateVarTemplate extends Base
{
    /**
     * @var string
     */
    protected string $className = 'modTemplateVarTemplate';

    /**
     * @param array $data
     * @return object|null
     */
    public function update(array $data): ?object
    {
        if(!$object = $this->modx->getObject($this->className, ['tmplvarid' => $data['tmplvarid'], 'templateid' => $data['templateid']])) {
            return $this->create($data);
        }
        return $this->setObjectData($object, $data);
    }
}
