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

    /** Имя TV с конфигом секций (mpc_config). */
    private string $configTvName;

    /** ID ресурса со статичными блоками (база для template/global уровней). */
    private int $staticBlocksPageId;

    /** Включены ли лексиконы (тогда поля хранят ключи, текст — в lexicon-файле). */
    private bool $useLexicons;

    /** Настройки для LexiconWriter (culture/пути/теги). */
    private array $lexProps;

    private ?LexiconWriter $lexWriterInstance = null;

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

        $this->configTvName = (string)($properties['commonConfigTvName']
            ?? $this->modx->getOption('mpc_common_config_name', null, 'mpc_config'));
        $this->staticBlocksPageId = (int)($properties['staticBlocksPageId']
            ?? $this->modx->getOption('mpc_static_block_page_id', null, 1));

        $this->useLexicons = (bool)($properties['useLexicons']
            ?? $this->modx->getOption('mpc_use_lexicons', null, false));
        $culture = !empty($_COOKIE['mpc_lang'])
            ? (string)$_COOKIE['mpc_lang']
            : (string)$this->modx->getOption('cultureKey', null, 'en');
        $this->lexProps = [
            'culture'              => $culture,
            'corePath'             => (string)$this->modx->getOption('core_path', null, ''),
            'lexiconPath'          => (string)$this->modx->getOption('mpc_lexicon_path', null, 'components/migxpageconfigurator/lexicon/'),
            'lexiconFilenameField' => (string)$this->modx->getOption('mpc_lexicon_filename_field', null, 'id'),
            'allowedTags'          => explode(',', (string)$this->modx->getOption('mpc_allowed_tags', null, '')),
            'allowModxTags'        => (bool)$this->modx->getOption('mpc_allow_modx_tags', null, false),
        ];
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

        // Лексиконный режим: rfield переводится через ключ mpc_resource_<field>
        // в файле лексикона ресурса (per-resource перевод). Пишем туда ТОЛЬКО
        // если у целевого ресурса этот ключ уже есть (т.е. ресурс mpc-управляемый/
        // лексиконизированный). Иначе (обычная статья из сниппета, ключа нет) —
        // пишем в колонку. Колонку при лексиконе не трогаем — рендер берёт перевод.
        if ($this->useLexicons) {
            $writer = $this->lexiconWriter();
            $ident  = $writer->identifier($rid);
            if ($writer->has($ident, 'mpc_resource_' . $field)) {
                if ($writer->set($ident, 'mpc_resource_' . $field, is_scalar($value) ? (string)$value : '')) {
                    $this->afterSave($resource, ['type' => 'rfield', 'fieldName' => $field, 'lexicon' => true]);
                    return $this->result(true, 'saved', ['type' => 'rfield', 'resourceId' => $rid, 'fieldName' => $field, 'lexicon' => true]);
                }
                return $this->result(false, 'failed to write lexicon entry');
            }
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

        // Лексиконный режим (зеркало writeResourceField): TV переводится через
        // ключ mpc_resource_tv_<name> в файле лексикона ресурса. Пишем в лексикон
        // ТОЛЬКО если ключ у ресурса уже есть (TV лексиконизирован при нарезке);
        // иначе — прямой setTVValue. Значение TV (колонку) при лексиконе не трогаем.
        if ($this->useLexicons) {
            $writer = $this->lexiconWriter();
            $ident  = $writer->identifier($rid);
            if ($writer->has($ident, 'mpc_resource_tv_' . $tv)) {
                if ($writer->set($ident, 'mpc_resource_tv_' . $tv, is_scalar($value) ? (string)$value : '')) {
                    $this->afterSave($resource, ['type' => 'tv', 'fieldName' => $tv, 'lexicon' => true]);
                    return $this->result(true, 'saved', ['type' => 'tv', 'resourceId' => $rid, 'fieldName' => $tv, 'lexicon' => true]);
                }
                return $this->result(false, 'failed to write lexicon entry');
            }
        }

        $resource->setTVValue($tv, $value);
        $this->afterSave($resource, ['type' => 'tv', 'fieldName' => $tv]);
        return $this->result(true, 'saved', ['type' => 'tv', 'resourceId' => $rid, 'fieldName' => $tv]);
    }

    /**
     * Запись значения config-поля (mpc_config) с учётом уровня.
     *
     * Уровни (приоритет рендера resource > type > global):
     *   resource (alias local)    — TV mpc_config самого ресурса (resourceId);
     *   type     (alias template) — донор: ребёнок staticBlocksPage с тем же шаблоном;
     *   global                    — staticBlocksPage (mpc_static_block_page_id).
     *
     * Лексиконы: в лексиконном режиме поле mpc_config хранит КЛЮЧ, а текст — в
     * lexicon-файле. Поэтому если текущее значение поля (или под-поля медиа-
     * записи) — ключ лексикона, пишем НОВОЕ значение в лексикон, а ключ в
     * конфиге сохраняем (см. applyLexicon/mergeRecordWithLexicon). Не-лексиконные
     * значения пишутся прямо в конфиг. Деградирует само: лексиконы выключены /
     * поле не лексиконилось → ключей нет → всё литералом.
     *
     * ВНИМАНИЕ (инвалидация, проверить): правка на template/global уровне
     * влияет на все ресурсы шаблона/сайта — нужна более широкая инвалидация
     * кэша, чем resource-cache одного контекста.
     */
    private function writeConfigField(array $address, $value): array
    {
        $level = (string)($address['level'] ?? 'resource');
        $resource = $this->resolveLevelResource($level, (int)($address['resourceId'] ?? 0));
        if (!$resource) {
            return $this->result(false, 'target resource for level "' . $level . '" not found');
        }

        $configJson = (string)$resource->getTVValue($this->configTvName);
        if ($configJson === '') {
            return $this->result(false, 'empty mpc_config for level "' . $level . '"');
        }

        $cfw = new ConfigFieldWriter();

        // Лексикон-aware: значение-ключ → пишем в лексикон, конфиг не трогаем;
        // медиа-запись → мерж по под-полям (ключи остаются, лексикон обновляется).
        if ($this->useLexicons) {
            $writer  = $this->lexiconWriter();
            $ident   = $writer->identifier((int)$resource->get('id'));
            $current = $cfw->getValue($configJson, $address)['data']['value'] ?? null;

            if ($this->isRecordValue($value)) {
                $value = $this->mergeRecordWithLexicon($writer, $ident, $current, $value);
            } elseif (is_string($current) && $current !== '' && !$this->isRecordValue($current)
                && $writer->has($ident, $current)) {
                if (!$writer->set($ident, $current, is_scalar($value) ? (string)$value : '')) {
                    return $this->result(false, 'failed to write lexicon entry');
                }
                $this->afterSave($resource, [
                    'type' => 'field', 'level' => $level, 'lexicon' => true,
                    'section' => (string)($address['section'] ?? ''),
                    'fieldName' => (string)($address['fieldName'] ?? ''),
                ]);
                return $this->result(true, 'saved', ['type' => 'field', 'level' => $level, 'lexicon' => true]);
            }
        }

        $res = $cfw->setValue($configJson, $address, $value);
        if (!$res['success']) {
            return $res;
        }
        if (!method_exists($resource, 'setTVValue')) {
            return $this->result(false, 'setTVValue unavailable on resource');
        }
        $resource->setTVValue($this->configTvName, $res['data']['json']);

        $this->afterSave($resource, [
            'type'      => 'field',
            'level'     => $level,
            'section'   => (string)($address['section'] ?? ''),
            'fieldName' => (string)($address['fieldName'] ?? ''),
        ]);

        return $this->result(true, 'saved', ['type' => 'field', 'level' => $level]);
    }

    /**
     * Резолвит ресурс-носитель mpc_config для уровня. PURE-ish (только getObject).
     */
    private function resolveLevelResource(string $level, int $resourceId)
    {
        // Алиасы старых имён уровней → новая терминология (global не переименовываем).
        $aliases = ['local' => 'resource', 'template' => 'type'];
        $level = $aliases[$level] ?? $level;

        switch ($level) {
            case 'global':
                return $this->modx->getObject('modResource', $this->staticBlocksPageId);
            case 'type':
                $resource = $this->modx->getObject('modResource', $resourceId);
                if (!$resource) {
                    return null;
                }
                $tpl = (int)$resource->get('template');
                return $this->modx->getObject('modResource', [
                    'parent'   => $this->staticBlocksPageId,
                    'template' => $tpl,
                ]);
            case 'resource':
            default:
                return $resourceId > 0 ? $this->modx->getObject('modResource', $resourceId) : null;
        }
    }

    private function lexiconWriter(): LexiconWriter
    {
        if ($this->lexWriterInstance === null) {
            $this->lexWriterInstance = new LexiconWriter($this->modx, $this->lexProps);
        }
        return $this->lexWriterInstance;
    }

    /** Значение — migx-запись (массив строк-объектов `[{...}]`)? */
    public function isRecordValue($v): bool
    {
        $d = is_string($v) ? json_decode($v, true) : $v;
        if (!is_array($d) || $d === []) {
            return false;
        }
        return is_array(reset($d));
    }

    private function decodeRecord($v): array
    {
        $d = is_string($v) ? json_decode($v, true) : $v;
        return is_array($d) ? $d : [];
    }

    /**
     * Мерж медиа-записи с лексиконом: для каждого под-поля строки, если ТЕКУЩЕЕ
     * значение — ключ лексикона, пишем туда новое значение и сохраняем ключ в
     * записи; иначе оставляем литерал из новой записи (так width/height и прочие
     * не-лексиконные под-поля обновляются прямо, а src/alt/title — в лексикон).
     *
     * @param object $writer LexiconWriter-совместимый (has/set)
     */
    public function mergeRecordWithLexicon($writer, string $ident, $current, $value): string
    {
        $curRows = $this->decodeRecord($current);
        $newRows = $this->decodeRecord($value);

        foreach ($newRows as $i => $newRow) {
            if (!is_array($newRow)) {
                continue;
            }
            $curRow = (isset($curRows[$i]) && is_array($curRows[$i])) ? $curRows[$i] : [];
            foreach ($newRow as $sub => $newSub) {
                $curSub = $curRow[$sub] ?? null;
                if (is_string($curSub) && $curSub !== '' && $writer->has($ident, $curSub)) {
                    $writer->set($ident, $curSub, is_scalar($newSub) ? (string)$newSub : '');
                    $newRows[$i][$sub] = $curSub; // сохраняем ключ в записи
                }
            }
        }

        return json_encode($newRows, JSON_UNESCAPED_UNICODE);
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
