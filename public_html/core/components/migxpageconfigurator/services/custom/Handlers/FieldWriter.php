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
                if ($this->isMediaWithSources($value)) {
                    // picture/video/audio: запись с вложенным img (JSON-строка) и
                    // массивом sources → глубокий мерж лексикона (существующий ключ
                    // обновляем, новый srcset/src/alt/poster — генерим).
                    $value = $this->mergeMediaRecord(
                        $writer, $ident, $current, $value,
                        $this->sectionPrefix($configJson, (string)($address['section'] ?? '')),
                        (string)($address['fieldName'] ?? '')
                    );
                } elseif ($this->recordHasLexiconKeys($writer, $ident, $current)) {
                    // плоская img-запись с ключами → мерж по под-полям.
                    $value = $this->mergeRecordWithLexicon($writer, $ident, $current, $value);
                } else {
                    // новая плоская img-запись → генерим ключи src/alt/title.
                    $value = $this->newRecordWithLexiconKeys($writer, $ident, $configJson, $address, $value);
                }
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
            } elseif (is_string($value) && $value !== '' && !$this->isRecordValue($value)
                && ($current === null || $current === '' || (is_string($current) && !$writer->has($ident, $current)))
                && $this->fieldUsesLexiconKeys($configJson, $address, $writer, $ident)) {
                // НОВОЕ лексиконизируемое поле (напр. поле только что добавленной
                // строки): ключа ещё нет. Генерим ключ как грабер, пишем значение
                // в лексикон, в конфиг кладём КЛЮЧ — иначе чанк {$field|lexicon}
                // вернёт '' для литерала. Лексиконизируем только если поле реально
                // лексиконится (у соседних строк того же списка — ключи).
                $key = $this->makeLexiconKey($configJson, $address);
                if ($key !== '' && $writer->set($ident, $key, (string)$value)) {
                    $value = $key;
                }
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
     * Ключ лексикона для НОВОГО поля. ФОРМАТ — через ЕДИНУЮ функцию
     * {@see \MpcServices\Handlers\Grabber\LexiconManager::getLexiconKey} (та же,
     * что у грабера), чтобы ключи редактора и грабера не разъезжались (напр. idx=0
     * → без суффикса). prefix — из lexicon_prefix секции. '' если префикса нет.
     * Вложенность (путь >1) пока не генерим (редко; вернётся '' → литерал).
     */
    private function makeLexiconKey(string $configJson, array $address): string
    {
        $field  = (string)($address['fieldName'] ?? '');
        $prefix = $this->sectionPrefix($configJson, (string)($address['section'] ?? ''));
        if ($field === '' || $prefix === '') {
            return '';
        }

        $path = $this->addressPath($address);
        if (count($path) > 1) {
            return ''; // вложенные новые поля — отдельно
        }
        $options = ['prefix' => $prefix, 'fieldName' => $field];
        if ($path) {
            $options['parentFieldName'] = $path[0]['field'];
            $options['idx']             = $path[0]['idx'];
        }
        return \MpcServices\Handlers\Grabber\LexiconManager::getLexiconKey($options);
    }

    /** lexicon_prefix секции из конфига ('' если нет). */
    private function sectionPrefix(string $configJson, string $section): string
    {
        $config = json_decode($configJson, true);
        if (!is_array($config)) {
            return '';
        }
        foreach ($config as $s) {
            if (is_array($s) && (($s['section_name'] ?? null) === $section || ($s['MIGX_formname'] ?? null) === $section)) {
                return (string)($s['lexicon_prefix'] ?? '');
            }
        }
        return '';
    }

    /** Есть ли в media-записи лексикон-ключи (существующая запись vs новая). */
    private function recordHasLexiconKeys(LexiconWriter $writer, string $ident, $current): bool
    {
        foreach ($this->decodeRecord($current) as $row) {
            if (!is_array($row)) {
                continue;
            }
            foreach ($row as $v) {
                if (is_string($v) && $v !== '' && $writer->has($ident, $v)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * НОВАЯ media-запись (картинка добавленной строки): генерим ключи лексикона
     * для лексиконизируемых под-полей (src/srcset/alt/title) через ЕДИНУЮ
     * getLexiconKey, пишем значения в лексикон, в запись кладём КЛЮЧИ. width/height
     * и пр. — литералом. Без lexicon_prefix секции → литерал (не лексиконим).
     */
    private function newRecordWithLexiconKeys(LexiconWriter $writer, string $ident, string $configJson, array $address, $value): string
    {
        $rows   = $this->decodeRecord($value);
        $prefix = $this->sectionPrefix($configJson, (string)($address['section'] ?? ''));
        if ($prefix === '' || !$rows) {
            return json_encode($rows, JSON_UNESCAPED_UNICODE);
        }
        // База ключа = parentField (строка media-списка) либо fieldName (top-level img).
        $base = (string)($address['parentField'] ?? '');
        if ($base === '') {
            $base = (string)($address['fieldName'] ?? '');
        }
        $idx     = $address['idx'] ?? '';
        $lexSubs = ['src' => '', 'srcset' => '', 'alt' => '_alt', 'title' => '_title'];
        foreach ($rows as $i => $row) {
            if (!is_array($row)) {
                continue;
            }
            foreach ($lexSubs as $sub => $suffix) {
                if (isset($row[$sub]) && is_string($row[$sub]) && $row[$sub] !== '') {
                    $key = \MpcServices\Handlers\Grabber\LexiconManager::getLexiconKey([
                        'prefix' => $prefix, 'fieldName' => $base . $suffix, 'idx' => $idx,
                    ]);
                    if ($key !== '' && $writer->set($ident, $key, $row[$sub])) {
                        $rows[$i][$sub] = $key;
                    }
                }
            }
        }
        return json_encode($rows, JSON_UNESCAPED_UNICODE);
    }

    /** Запись — это media с вложенным img/sources (picture/video/audio)? */
    private function isMediaWithSources($value): bool
    {
        $rows = $this->decodeRecord($value);
        $row  = $rows[0] ?? null;
        return is_array($row) && (isset($row['sources']) || isset($row['img']));
    }

    /**
     * Глубокий лексикон-мерж media-записи (picture/video/audio): вложенный `img`
     * (JSON-строка картинки) и массив `sources`. Лексиконизируемые листья
     * (src/srcset/alt/title/poster): существующий ключ → обновляем значение в
     * лексиконе и сохраняем ключ; новый → генерим ключ единой getLexiconKey.
     * Прочее (preview/type/media/width…) — литералом.
     */
    private function mergeMediaRecord(LexiconWriter $writer, string $ident, $current, $value, string $prefix, string $field): string
    {
        $cur = $this->decodeRecord($current);
        $new = $this->decodeRecord($value);

        foreach ($new as $i => $row) {
            if (!is_array($row)) {
                continue;
            }
            $curRow = (isset($cur[$i]) && is_array($cur[$i])) ? $cur[$i] : [];

            // вложенная картинка img (JSON-строка записи): src/alt/title
            if (isset($row['img'])) {
                $new[$i]['img'] = $this->mergeImgKeys($writer, $ident, $curRow['img'] ?? '', $row['img'], $prefix, $field);
            }
            // массив sources: srcset (picture) / src (video/audio)
            if (isset($row['sources']) && is_array($row['sources'])) {
                $curSources = (isset($curRow['sources']) && is_array($curRow['sources'])) ? $curRow['sources'] : [];
                foreach ($row['sources'] as $k => $src) {
                    if (!is_array($src)) {
                        continue;
                    }
                    foreach (['srcset', 'src'] as $leaf) {
                        if (isset($src[$leaf]) && is_string($src[$leaf]) && $src[$leaf] !== '') {
                            $key = $this->resolveLeafKey($writer, $ident, $curSources[$k][$leaf] ?? null, $prefix, $field . '_source', $k);
                            if ($key !== '') {
                                // значение == ключ → фронт прислал ключ (источник
                                // не меняли) → лексикон НЕ трогаем, только ключ в конфиг.
                                if ($src[$leaf] !== $key) {
                                    $writer->set($ident, $key, $src[$leaf]);
                                }
                                $new[$i]['sources'][$k][$leaf] = $key;
                            }
                        }
                    }
                }
            }
            // верхнеуровневые листья video/audio: src / poster
            foreach (['src' => '_source', 'poster' => '_poster'] as $leaf => $suffix) {
                if (isset($row[$leaf]) && is_string($row[$leaf]) && $row[$leaf] !== '' && !$this->isRecordValue($row[$leaf])) {
                    $key = $this->resolveLeafKey($writer, $ident, $curRow[$leaf] ?? null, $prefix, $field . $suffix, '');
                    if ($key !== '') {
                        if ($row[$leaf] !== $key) {
                            $writer->set($ident, $key, $row[$leaf]);
                        }
                        $new[$i][$leaf] = $key;
                    }
                }
            }
        }

        return json_encode($new, JSON_UNESCAPED_UNICODE);
    }

    /** Мерж ключей вложенной картинки img (src/alt/title). */
    private function mergeImgKeys(LexiconWriter $writer, string $ident, $curImg, $newImg, string $prefix, string $field): string
    {
        $cur = $this->decodeRecord($curImg);
        $new = $this->decodeRecord($newImg);
        foreach ($new as $i => $row) {
            if (!is_array($row)) {
                continue;
            }
            $curRow = (isset($cur[$i]) && is_array($cur[$i])) ? $cur[$i] : [];
            foreach (['src' => '', 'alt' => '_alt', 'title' => '_title'] as $leaf => $suffix) {
                if (isset($row[$leaf]) && is_string($row[$leaf]) && $row[$leaf] !== '') {
                    $key = $this->resolveLeafKey($writer, $ident, $curRow[$leaf] ?? null, $prefix, $field . $suffix, '');
                    if ($key !== '') {
                        if ($row[$leaf] !== $key) {
                            $writer->set($ident, $key, $row[$leaf]);
                        }
                        $new[$i][$leaf] = $key;
                    }
                }
            }
        }
        return json_encode($new, JSON_UNESCAPED_UNICODE);
    }

    /** Ключ листа: существующий (если он лексикон-ключ) либо новый через getLexiconKey ('' если нет prefix). */
    private function resolveLeafKey(LexiconWriter $writer, string $ident, $currentLeaf, string $prefix, string $fieldName, $idx): string
    {
        if (is_string($currentLeaf) && $currentLeaf !== '' && $writer->has($ident, $currentLeaf)) {
            return $currentLeaf;
        }
        if ($prefix === '') {
            return '';
        }
        return \MpcServices\Handlers\Grabber\LexiconManager::getLexiconKey([
            'prefix' => $prefix, 'fieldName' => $fieldName, 'idx' => $idx,
        ]);
    }

    /**
     * Лексиконизируется ли поле — смотрим то же поле в ДРУГИХ строках того же
     * списка: если там лежит лексикон-ключ → да (тогда новое значение тоже под
     * ключом). Иначе (литералы / нет соседей) → false, пишем литералом.
     */
    private function fieldUsesLexiconKeys(string $configJson, array $address, LexiconWriter $writer, string $ident): bool
    {
        $path = $this->addressPath($address);
        if (!$path) {
            return false; // top-level: не угадываем, пишем литералом
        }
        $config = json_decode($configJson, true);
        if (!is_array($config)) {
            return false;
        }
        $section = (string)($address['section'] ?? '');
        $field   = (string)($address['fieldName'] ?? '');
        $container = null;
        foreach ($config as $s) {
            if (is_array($s) && (($s['section_name'] ?? null) === $section || ($s['MIGX_formname'] ?? null) === $section)) {
                $container = $s;
                break;
            }
        }
        if ($container === null) {
            return false;
        }

        // Спускаемся по пути до контейнера строк нашего поля (последний сегмент).
        for ($d = 0, $n = count($path); $d < $n; $d++) {
            $seg  = $path[$d];
            $rows = $container[$seg['field']] ?? null;
            if (is_string($rows)) {
                $rows = json_decode($rows, true);
            }
            if (!is_array($rows)) {
                return false;
            }
            if ($d === $n - 1) {
                foreach ($rows as $i => $row) {
                    if ($i === (int)$seg['idx'] || !is_array($row)) {
                        continue;
                    }
                    $v = $row[$field] ?? null;
                    if (is_string($v) && $v !== '' && $writer->has($ident, $v)) {
                        return true;
                    }
                }
                return false;
            }
            $container = $rows[(int)$seg['idx']] ?? [];
        }
        return false;
    }

    /** Нормализует адрес в путь [{field,idx},…] (из path либо parentField+idx). */
    private function addressPath(array $address): array
    {
        if (!empty($address['path']) && is_array($address['path'])) {
            $path = [];
            foreach ($address['path'] as $seg) {
                if (is_array($seg) && isset($seg['field'], $seg['idx']) && $seg['field'] !== '') {
                    $path[] = ['field' => (string)$seg['field'], 'idx' => (int)$seg['idx']];
                }
            }
            return $path;
        }
        $pf  = (string)($address['parentField'] ?? '');
        $idx = $address['idx'] ?? null;
        if ($pf !== '' && $idx !== null && $idx !== '') {
            return [['field' => $pf, 'idx' => (int)$idx]];
        }
        return [];
    }

    /**
     * Структурная операция над строками поля-списка: add | delete | move.
     * Адрес: level, resourceId, section, parentField (имя списка), op, + idx
     * (delete) / fromIdx,toIdx (move). Лексиконы не трогаем — значения строк
     * (вкл. лексикон-ключи) едут вместе со строкой при перестановке/удалении.
     */
    public function writeRowOp(array $address): array
    {
        $op       = (string)($address['op'] ?? '');
        $level    = (string)($address['level'] ?? 'resource');
        $resource = $this->resolveLevelResource($level, (int)($address['resourceId'] ?? 0));
        if (!$resource) {
            return $this->result(false, 'target resource for level "' . $level . '" not found');
        }

        $configJson = (string)$resource->getTVValue($this->configTvName);
        if ($configJson === '') {
            return $this->result(false, 'empty mpc_config for level "' . $level . '"');
        }

        $cfw = new ConfigFieldWriter();
        switch ($op) {
            case 'add':    $res = $cfw->addRow($configJson, $address); break;
            case 'delete': $res = $cfw->deleteRow($configJson, $address); break;
            case 'move':   $res = $cfw->moveRow($configJson, $address); break;
            default:       return $this->result(false, 'unknown row op: ' . $op);
        }
        if (!$res['success']) {
            return $res;
        }
        if (!method_exists($resource, 'setTVValue')) {
            return $this->result(false, 'setTVValue unavailable on resource');
        }
        $resource->setTVValue($this->configTvName, $res['data']['json']);

        $this->afterSave($resource, [
            'type'        => 'row',
            'op'          => $op,
            'level'       => $level,
            'section'     => (string)($address['section'] ?? ''),
            'fieldName'   => (string)($address['parentField'] ?? ''),
        ]);

        return $this->result(true, 'saved', ['type' => 'row', 'op' => $op, 'level' => $level]);
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

    /**
     * Прочитать декодированный mpc_config указанного уровня (read-only).
     *
     * Нужен редактору (mpcVE) для правки СКРЫТЫХ полей: те, что вырезаны из
     * страницы (`data-mpc-remove`) или вспомогательные — DOM-маркера не имеют,
     * но значение в конфиге есть. Возвращаем весь конфиг секций уровня, фронт
     * сам вычитает уже видимые поля. Переиспользует резолв уровня и имя TV —
     * единый источник правды с записью. Запись идёт прежним путём
     * (`writeConfigField` через address parentField/idx/fieldName).
     *
     * @return array ['success'=>bool,'message'=>string,'data'=>['config'=>array]]
     */
    public function readConfig(string $level, int $resourceId): array
    {
        $resource = $this->resolveLevelResource($level, $resourceId);
        if (!$resource) {
            return $this->result(false, 'target resource for level "' . $level . '" not found');
        }

        $json = (string)$resource->getTVValue($this->configTvName);
        if ($json === '') {
            return $this->result(true, 'empty', ['config' => []]);
        }

        $config = json_decode($json, true);
        if (!is_array($config)) {
            return $this->result(false, 'invalid mpc_config JSON for level "' . $level . '"');
        }

        return $this->result(true, 'ok', ['config' => $config]);
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
