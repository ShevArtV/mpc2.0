<?php

namespace MpcServices\Handlers;

/**
 * Запись значения поля по адресу — публичный write-API mpc 2.4.0.
 * Используется mpcVisualEditor (и любым внешним вызовом) для сохранения правок.
 *
 * Адрес:
 *   [
 *     'type'        => 'rfield' | 'tv' | 'field',
 *     'resourceId'  => int,
 *     'fieldName'   => string,
 *     'level'       => 'local' | 'template' | 'global',   // только для type=field (M2)
 *     'section'     => string,                             // для type=field
 *     'idx'         => int,                                // для multi-row
 *     'parentField' => string,                             // для вложенных
 *   ]
 *
 * Реализовано (M4):
 *   - type=rfield — нативная колонка ресурса (из белого списка editableResourceFields);
 *   - type=tv     — значение TV ресурса.
 * НЕ реализовано: type=field (mpc_config) — требует иерархии уровней (M2) и
 * согласованной работы с lexicon-значениями. См. writeConfigField().
 */
class FieldWriter
{
    private \modX $modx;

    /** @var string[] белый список редактируемых нативных полей ресурса */
    private array $editableResourceFields;

    public function __construct(\modX $modx, array $properties = [])
    {
        $this->modx = $modx;

        $list = $properties['editableResourceFields'] ?? $this->modx->getOption(
            'mpc_editable_resource_fields',
            null,
            'pagetitle,longtitle,description,introtext,content,menutitle'
        );
        $this->editableResourceFields = is_array($list)
            ? $list
            : array_values(array_filter(array_map('trim', explode(',', (string)$list))));
    }

    /**
     * @param array $address см. docblock класса
     * @param mixed $value
     * @return array ['success'=>bool, 'message'=>string, 'data'=>array]
     */
    public function write(array $address, $value): array
    {
        $type       = (string)($address['type'] ?? 'field');
        $resourceId = (int)($address['resourceId'] ?? 0);
        $fieldName  = (string)($address['fieldName'] ?? '');

        if ($resourceId <= 0 || $fieldName === '') {
            return $this->result(false, 'invalid address: resourceId and fieldName required');
        }

        switch ($type) {
            case 'rfield':
                return $this->writeResourceField($resourceId, $fieldName, $value);
            case 'tv':
                return $this->writeTv($resourceId, $fieldName, $value);
            case 'field':
                return $this->writeConfigField($address, $value);
            default:
                return $this->result(false, 'unknown address type: ' . $type);
        }
    }

    private function writeResourceField(int $rid, string $field, $value): array
    {
        if (!in_array($field, $this->editableResourceFields, true)) {
            return $this->result(false, 'resource field is not editable: ' . $field);
        }
        /** @var \modResource|null $resource */
        $resource = $this->modx->getObject('modResource', $rid);
        if (!$resource) {
            return $this->result(false, 'resource not found: ' . $rid);
        }
        $resource->set($field, $value);
        if (!$resource->save()) {
            return $this->result(false, 'failed to save resource ' . $rid);
        }
        $this->afterSave($resource, ['type' => 'rfield', 'fieldName' => $field]);
        return $this->result(true, 'saved', ['type' => 'rfield', 'resourceId' => $rid, 'fieldName' => $field]);
    }

    private function writeTv(int $rid, string $tv, $value): array
    {
        /** @var \modResource|null $resource */
        $resource = $this->modx->getObject('modResource', $rid);
        if (!$resource) {
            return $this->result(false, 'resource not found: ' . $rid);
        }
        if (!method_exists($resource, 'setTVValue')) {
            return $this->result(false, 'setTVValue unavailable on resource');
        }
        $resource->setTVValue($tv, $value);
        $this->afterSave($resource, ['type' => 'tv', 'fieldName' => $tv]);
        return $this->result(true, 'saved', ['type' => 'tv', 'resourceId' => $rid, 'fieldName' => $tv]);
    }

    /**
     * TODO M2: запись значения config-поля (mpc_config) с учётом уровня
     * (local/template/global) и lexicon-значений. Намеренно не реализовано
     * вслепую — требует проверки модели хранения на живом сайте.
     */
    private function writeConfigField(array $address, $value): array
    {
        return $this->result(
            false,
            'config-field (type=field) write not implemented yet — requires level hierarchy (M2)'
        );
    }

    /**
     * Хук + инвалидация кэша после успешной записи.
     * ВАЖНО (проверить на сайте): объём инвалидации. Сейчас обновляется
     * resource-кэш контекста ресурса; parsed/ pdoTools и lexicon-кэш —
     * через подписчиков mpcOnFieldSave (см. план mpcVE, cache invalidation).
     */
    private function afterSave(object $resource, array $address): void
    {
        $rid = (int)$resource->get('id');
        $this->modx->invokeEvent('mpcOnFieldSave', [
            'resourceId' => $rid,
            'address'    => $address,
        ]);

        $cm = null;
        if (method_exists($this->modx, 'getCacheManager')) {
            $cm = $this->modx->getCacheManager();
        } elseif (isset($this->modx->cacheManager)) {
            $cm = $this->modx->cacheManager;
        }
        if ($cm && method_exists($cm, 'refresh')) {
            $context = (string)($resource->get('context_key') ?: 'web');
            $cm->refresh(['resource' => ['contexts' => [$context]]]);
        }
    }

    private function result(bool $success, string $message, array $data = []): array
    {
        return ['success' => $success, 'message' => $message, 'data' => $data];
    }
}
