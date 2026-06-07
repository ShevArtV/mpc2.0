<?php

namespace MpcServices\Handlers\Grabber;

use DiDom\Element;
use MpcServices\Handlers\Parser;
use MpcServices\Helpers\Response;

/**
 * Обработка секций: создание конфигураций MIGX, извлечение значений, запись в БД.
 */
class SectionProcessor
{
    /** Индекс контентного таба в formtabs mpc_base (0=настройки, 1=контент, 2=стили). */
    private const CONTENT_TAB_INDEX = 1;

    /** Служебные ключи секции — всегда берутся свежими из шаблона при перенарезке. */
    private const STRUCTURAL_SECTION_KEYS = [
        'MIGX_id', 'MIGX_formname', 'id', 'section_name', 'lexicon_prefix', 'file_name', 'is_static',
    ];

    public array $properties;

    /** Имена mpc_auto_*-конфигов, созданных при нарезке текущей секции (для GC). */
    private array $createdAutoConfigs = [];

    /**
     * Имена полей mpc_base из НЕ-контентных табов (настройки/стили): уже
     * определены для каждой секции, поэтому одноимённые data-mpc-field НЕ
     * добавляем в контент-таб (иначе дубль — inline_styles/class_names и т.п.).
     */
    private array $reservedFieldNames = [];

    private \modX $modx;
    private Parser $parser;
    private ContentParser $contentParser;
    private LexiconManager $lexiconManager;
    private MediaDownloader $mediaDownloader;
    private Response $response;

    public function __construct(
        \modX $modx,
        array $properties,
        Parser $parser,
        ContentParser $contentParser,
        LexiconManager $lexiconManager,
        MediaDownloader $mediaDownloader,
        Response $response
    ) {
        $this->modx = $modx;
        $this->properties = $properties;
        $this->parser = $parser;
        $this->contentParser = $contentParser;
        $this->lexiconManager = $lexiconManager;
        $this->mediaDownloader = $mediaDownloader;
        $this->response = $response;
    }

    /**
     * Главный метод обработки секций.
     */
    public function handleSections(string $html): void
    {
        $sections = $this->parser->findByAttribute($html, '[data-mpc-section]');
        if (!count($sections)) {
            return;
        }

        if (!$staticBlocksResource = $this->modx->getObject('modResource', (int)$this->properties['staticBlocksPageId'])) {
            return;
        }

        $sbpResourceConfig = $staticBlocksResource->getTVValue($this->properties['commonConfigTvName']);
        $this->properties['sbpSectionValues'] = json_decode($sbpResourceConfig, true) ?: [];

        $result = $this->getObject('migxConfig', ['name' => $this->properties['baseSectionName']], true);
        if (!$result['success']) {
            return;
        }

        $defaultFormTabs = json_decode($result['data']['object']['formtabs'], true);
        $this->reservedFieldNames = $this->collectReservedFieldNames(is_array($defaultFormTabs) ? $defaultFormTabs : []);
        $result = $this->getObject('migxConfig', ['name' => $this->properties['commonConfigTvName']]);
        if (!$result['success']) {
            return;
        }

        $commonConfig = $result['data']['object'];
        $commonConfigData = $commonConfig->toArray();
        $this->properties['multipleFormtabs'] = explode('||', $commonConfigData['extended']['multiple_formtabs']);

        $i = 0;
        $sectionValues = [];

        // Существующий конфиг ресурса — для умного мержа при перенарезке: правки
        // админа сохраняем, новые поля добавляем, ушедшие удаляем (см.
        // mergeSectionFields). Индекс по имени секции.
        $existingResourceSections = $this->indexConfigBySection(
            json_decode((string)$this->properties['resource']->getTVValue($this->properties['commonConfigTvName']), true) ?: []
        );

        foreach ($sections as $section) {
            $this->mediaDownloader->setCurrentSectionName('');
            $i++;
            $sectionName = trim((string)$section->getAttribute('data-mpc-section'));
            $fileName = $sectionName . $this->properties['extension'];
            // Путь к чанку секции храним ОТНОСИТЕЛЬНО папки элементов pdoTools
            // (`pdotools_elements_path` — корень для @FILE): file_name напрямую
            // годится в parseChunk (см. Render::getSectionChunkBinding) и не
            // протухает при переезде сайта/смене core_path.
            $fileNameVis = $this->properties['pathToSections'] . $fileName;

            $properties = [
                'defaultFormTabs' => $defaultFormTabs,
                'sectionName' => $sectionName,
                'isCopy' => $section->hasAttribute('data-mpc-copy'),
                'fileNameVis' => $fileNameVis,
                'fileName' => $fileName,
            ];

            if (!$properties['isCopy'] && !empty($defaultFormTabs)) {
                $result = $this->createSectionConfig($section, $properties);
                if ($result['success']) {
                    $this->properties['multipleFormtabs'][] = $result['data']['id'];
                }
            }

            $values = $this->grabSection($section, $properties, $i);
            $existing = $existingResourceSections[$this->sectionMergeKey($values)] ?? [];
            $sectionValues[$i] = $this->mergeSectionFields(
                $values, $existing, $this->reservedFieldNames, !empty($this->properties['updContent'])
            );
        }

        // Имя файла секции — служебка типа страницы. Для mpcType пишем в
        // file_name (mpc_type), для прочих ресурсов — в introtext (легаси).
        $grabResource = $this->properties['resource'];
        $fileName = $this->properties['fileName'] ?? '';
        if ($grabResource->get('class_key') === 'mpcType') {
            $grabResource->set('file_name', $fileName);
        } else {
            $grabResource->set('introtext', $fileName);
        }
        $grabResource->save();

        // Грабим data-mpc-rfield / data-mpc-tv в нативные поля / TV ресурса.
        // Это данные уровня type (ресурс-тип), которые при рендере перекрывает
        // контент-ресурс (приоритет resource > type). Overwrite, кроме
        // защищённых полей (alias/uri/template + структурный минимум).
        if ($this->properties['updContent']) {
            // Медиа из rfield/tv складываем в подпапку "resource" источника.
            $this->mediaDownloader->setCurrentSectionName('resource');
            (new ResourceFieldGrabber(
                $this->parser,
                $this->getProtectedResourceFields(),
                $this->mediaDownloader,
                $this->lexiconManager,
                !empty($this->properties['useLexicons'])
            ))->grab($html, $grabResource);
            $grabResource->save();
        }

        // Пишем ВСЕГДА (не только при updContent): без updContent это умный мерж
        // (правки сохранены, ушедшие поля/секции убраны, новые добавлены), с
        // updContent — перезапись контентом шаблона. Решает mergeSectionFields.
        if (!empty($sectionValues)) {
            // array_values: ключи $sectionValues начинаются с 1 ($i++ в начале
            // цикла) → json_encode дал бы объект {"1":…}. Нормализуем к чистому
            // JSON-массиву секций с 0-based ключами.
            $this->properties['resource']->setTVValue(
                $this->properties['commonConfigTvName'],
                json_encode(array_values($sectionValues), JSON_UNESCAPED_UNICODE)
            );
        }

        if (!empty($this->properties['sbpSectionValues'])) {
            $staticBlocksResource->setTVValue(
                $this->properties['commonConfigTvName'],
                json_encode(array_values($this->properties['sbpSectionValues']), JSON_UNESCAPED_UNICODE)
            );
        }

        $commonConfigData['extended']['multiple_formtabs'] = implode('||', array_unique($this->properties['multipleFormtabs']));
        $commonConfig->fromArray($commonConfigData);
        if (!$commonConfig->save()) {
            $this->response->error(__METHOD__, 'Failed to save configuration.');
            return;
        }

        if (!$this->properties['fromPlugin']) {
            // overwrite=updContent: без `1` существующие переводы сохраняем (мерж),
            // с `1` — шаблон перезаписывает (как контент секций).
            $this->lexiconManager->createLexicons(
                $this->lexiconManager->lexicons,
                !empty($this->properties['updContent'])
            );
        }

        $this->rebuildTrackedFields($html);

        $this->response->success(__METHOD__, 'Section processing is complete.');
    }

    /**
     * Собирает манифест трекаемых полей шаблона из data-mpc-* маркеров и
     * перезаписывает строки этого шаблона в таблице mpc_tracked_fields (источник
     * для админ-лога и фильтров модалки истории). Глобальный союз — по template_id.
     * field — верхний уровень секции (вложенные списки помечаем kind=list, дифф
     * строк делает лог); rfield/tv — по всему html. Уровень тут не храним.
     */
    private function rebuildTrackedFields(string $html): void
    {
        $templateId = (int)$this->properties['resource']->get('template');
        if ($templateId <= 0) {
            return;
        }
        $rows = [];

        foreach ($this->parser->findByAttribute($html, '[data-mpc-section]') as $section) {
            $sectionName = trim((string)$section->getAttribute('data-mpc-section'));
            $prefix = trim((string)$section->getAttribute('data-mpc-lexicon')) ?: $sectionName;
            $seen = [];
            foreach ($this->parser->findByAttribute($this->parser->getHTMLString($section), '[data-mpc-field]') as $e) {
                $name = trim((string)$e->getAttribute('data-mpc-field'));
                if ($name === '' || isset($seen[$name]) || isset($this->reservedFieldNames[$name])) {
                    continue;
                }
                $seen[$name] = true;
                $kind = trim((string)$e->getAttribute('data-mpc-ftype'));
                if ($kind === '' && $this->parser->findByAttribute($this->parser->getHTMLString($e), '[data-mpc-item]')) {
                    $kind = 'list';
                }
                $rows[] = [
                    'type' => 'field', 'section' => $sectionName,
                    'lexicon_prefix' => $prefix, 'field' => $name, 'kind' => $kind,
                ];
            }
        }

        foreach (['rfield' => 'data-mpc-rfield', 'tv' => 'data-mpc-tv'] as $type => $attr) {
            $seen = [];
            foreach ($this->parser->findByAttribute($html, '[' . $attr . ']') as $e) {
                $name = trim((string)$e->getAttribute($attr));
                if ($name === '' || isset($seen[$name])) {
                    continue;
                }
                $seen[$name] = true;
                $rows[] = [
                    'type' => $type, 'section' => '', 'lexicon_prefix' => '',
                    'field' => $name, 'kind' => trim((string)$e->getAttribute('data-mpc-ftype')),
                ];
            }
        }

        (new \MpcServices\Handlers\TrackedFields($this->modx))->replaceForTemplate($templateId, $rows);
    }

    private function createSectionConfig(Element $section, array $properties): array
    {
        $properties['defaultFormTabs'][1]['fields'] = $this->getSectionFields($section, $properties['defaultFormTabs'][1]['fields'], (string)$properties['sectionName']);
        $properties['defaultFormTabs'][0]['fields'][2]['default'] = $properties['fileNameVis'];
        $properties['defaultFormTabs'][0]['fields'][2]['useDefaultIfEmpty'] = 1;
        $properties['defaultFormTabs'][0]['fields'][1]['default'] = $section->getAttribute('data-mpc-name');
        $properties['defaultFormTabs'][0]['fields'][1]['useDefaultIfEmpty'] = 1;
        $properties['defaultFormTabs'][0]['fields'][0]['default'] = $properties['sectionName'];
        $properties['defaultFormTabs'][0]['fields'][0]['useDefaultIfEmpty'] = 1;

        $defaultConfigData['formtabs'] = json_encode($properties['defaultFormTabs']);
        $defaultConfigData['name'] = $properties['sectionName'];
        $defaultConfigData['extended']['multiple_formtabs_optionstext'] = $section->getAttribute('data-mpc-name');
        $defaultConfigData['editedon'] = date('Y-m-d H:i:s');

        if (!$config = $this->modx->getObject('migxConfig', ['name' => $properties['sectionName']])) {
            $config = $this->modx->newObject('migxConfig');
        }

        $config->fromArray($defaultConfigData);
        if (!$config->save()) {
            return $this->response->error(__METHOD__, 'Failed to save configuration.', $properties);
        }

        return $this->response->success(__METHOD__, 'Configuration saved successfully.', ['id' => $config->get('id')]);
    }

    /**
     * Строит поля контент-таба конфига секции из data-mpc-field маркеров.
     *
     * Произвольное имя поля разрешается в определение по ТИПУ-прототипу из
     * mpc_base (clone-by-type): тип задаётся data-mpc-ftype (имя прототипа),
     * по умолчанию — text. Если имя поля само совпало с прототипом и ftype не
     * задан — берём прототип как есть (прежнее поведение). caption/description
     * переопределяются data-mpc-fcap/data-mpc-fdesc. См. makeFieldDef.
     */
    /**
     * Мерж значений секции при перенарезке (PURE, юнит-тестируемо).
     *
     * @param array $grabbed   значения из ШАБЛОНА (текущее состояние полей)
     * @param array $existing  значения из ТЕКУЩЕГО конфига (правки админа)
     * @param array $reserved  имена admin-полей настроек/стилей (collectReservedFieldNames)
     * @param bool  $overwrite updContent: true → шаблон перезаписывает контент
     *
     * Без overwrite: служебные ключи — свежие из шаблона; контент-поле, что уже
     * есть в конфиге → СОХРАНЯЕМ старое значение; новое поле → его контент из
     * шаблона; поле, ушедшее из шаблона → выпадает (удаляется), КРОМЕ admin-полей
     * настроек/стилей (их шаблон не задаёт — сохраняем).
     * С overwrite: шаблон побеждает, но admin-поля настроек/стилей вне шаблона
     * тоже сохраняются (иначе сбрасывались бы hide_section/position и т.п.).
     */
    public function mergeSectionFields(array $grabbed, array $existing, array $reserved, bool $overwrite): array
    {
        if ($overwrite) {
            $result = $grabbed;
            foreach ($existing as $k => $v) {
                if (isset($reserved[$k]) && !array_key_exists($k, $grabbed)) {
                    $result[$k] = $v;
                }
            }
            return $result;
        }

        $result = [];
        foreach ($grabbed as $k => $v) {
            if (in_array($k, self::STRUCTURAL_SECTION_KEYS, true)) {
                $result[$k] = $v;                       // служебное → свежее
            } elseif (array_key_exists($k, $existing)) {
                $result[$k] = $existing[$k];            // сохраняем старое значение
            } else {
                $result[$k] = $v;                       // новое поле → его контент
            }
        }
        foreach ($existing as $k => $v) {
            if (isset($reserved[$k]) && !array_key_exists($k, $result)) {
                $result[$k] = $v;                       // admin-настройки/стили вне шаблона
            }
        }
        return $result;
    }

    /** Индексирует массив секций конфига по имени (MIGX_formname → секция). */
    private function indexConfigBySection(array $config): array
    {
        $map = [];
        foreach ($config as $sec) {
            if (is_array($sec)) {
                $key = $this->sectionMergeKey($sec);
                if (trim($key, '|') !== '') {
                    $map[$key] = $sec;
                }
            }
        }
        return $map;
    }

    /**
     * Ключ секции для умного мержа при перенарезке. = MIGX_formname + lexicon_prefix.
     * Префикс ОБЯЗАТЕЛЕН: две секции с одинаковым data-mpc-section различаются
     * data-mpc-lexicon (оригинал "features" vs копия "features_copy"). Без префикса
     * в ключе мерж путал их — правка/значение копии затирали оригинал (одинаковый
     * MIGX_formname). lexicon_prefix всегда непуст (фоллбэк на sectionName, см.
     * grabSection), поэтому однотипные секции по-прежнему матчатся стабильно.
     */
    private function sectionMergeKey(array $sec): string
    {
        $name = (string)($sec['MIGX_formname'] ?? ($sec['section_name'] ?? ''));
        $lex  = (string)($sec['lexicon_prefix'] ?? '');
        return $name . '|' . $lex;
    }

    /**
     * Имена полей из НЕ-контентных табов mpc_base (настройки + стили). Эти поля
     * уже есть у каждой секции, одноимённые data-mpc-field в контент-таб не идут.
     */
    private function collectReservedFieldNames(array $formTabs): array
    {
        $reserved = [];
        foreach ($formTabs as $idx => $tab) {
            if ((int)$idx === self::CONTENT_TAB_INDEX) {
                continue; // контент-таб → штатный путь (clone/synthesize)
            }
            foreach ($tab['fields'] ?? [] as $f) {
                if (!empty($f['field'])) {
                    $reserved[$f['field']] = true;
                }
            }
        }
        return $reserved;
    }

    private function getSectionFields(Element $section, array $defaultFields, string $sectionName): array
    {
        $entries = $this->parser->findByAttribute($this->parser->getHTMLString($section), '[data-mpc-field]');
        if (!count($entries)) {
            return [];
        }

        $byName = $this->indexByField($defaultFields);
        $this->createdAutoConfigs = [];

        $fields = [];
        $seen   = [];
        foreach ($entries as $entry) {
            $name = trim((string)$entry->getAttribute('data-mpc-field'));
            if ($name === '' || isset($seen[$name])) {
                continue;
            }
            $seen[$name] = true;

            // Поле уже определено в mpc_base (таб настроек/стилей) — НЕ дублируем
            // его в контент-таб секции. Значение всё равно грабится (ContentParser),
            // определение остаётся одно — в своём табе mpc_base.
            if (isset($this->reservedFieldNames[$name])) {
                continue;
            }

            $attrs = $this->fieldAttrs($entry);

            // Произвольный (не из mpc_base, без ftype) структурный список с
            // data-mpc-item → динамически генерим row-конфиг из его полей.
            // Имена из mpc_base и ftype НЕ трогаем — идут штатным путём, так что
            // предопределённые прототипы остаются целы.
            if ($attrs['ftype'] === '' && !isset($byName[$name]) && $this->isStructuralList($entry, 1)) {
                $configName = $this->buildListConfig($entry, 1, $sectionName . '_' . $name, $byName, $sectionName);
                $fields[] = $this->listFieldDef($name, $configName, $attrs, count($fields) + 1);
                continue;
            }

            $fields[] = $this->makeFieldDef($name, $attrs, $byName, $this->elementKind($entry), count($fields) + 1);

            // Штатные img/img_mob: добавляем поля размеров, если они есть в разметке.
            if ($name === 'img' || $name === 'img_mob') {
                foreach (['_w' => 'width', '_h' => 'height'] as $suf => $attr) {
                    $dim = $name . $suf;
                    if ($entry->getAttribute($attr) && isset($byName[$dim]) && !isset($seen[$dim])) {
                        $fields[]    = $byName[$dim];
                        $seen[$dim]  = true;
                    }
                }
            }
        }

        $this->gcAutoConfigs($sectionName);

        return $fields;
    }

    /**
     * Удаляет осиротевшие mpc_auto_*-конфиги ЭТОЙ секции: те, что принадлежат
     * секции (extended.mpc_owner), но не были пересозданы в текущую нарезку
     * (поле-список удалили/переименовали). Владелец в extended исключает
     * коллизии префиксов имён секций.
     */
    private function gcAutoConfigs(string $sectionName): void
    {
        $kept = array_flip($this->createdAutoConfigs);
        $collection = $this->modx->getCollection('migxConfig', ['name:LIKE' => 'mpc_auto_%']);
        foreach ($collection as $config) {
            if (isset($kept[$config->get('name')])) {
                continue;
            }
            $ext = $config->get('extended');
            $owner = is_array($ext) ? (string)($ext['mpc_owner'] ?? '') : '';
            if ($owner === $sectionName) {
                $config->remove();
            }
        }
    }

    /** Индекс field-определений по имени поля. */
    private function indexByField(array $fields): array
    {
        $byName = [];
        foreach ($fields as $v) {
            if (isset($v['field']) && $v['field'] !== '') {
                $byName[$v['field']] = $v;
            }
        }
        return $byName;
    }

    /** Читает authoring-хинты поля (ftype/fcap/fdesc) с элемента. */
    private function fieldAttrs(Element $el): array
    {
        return [
            'ftype'  => trim((string)$el->getAttribute('data-mpc-ftype')),
            'fcap'   => (string)$el->getAttribute('data-mpc-fcap'),
            'fdesc'  => (string)$el->getAttribute('data-mpc-fdesc'),
            // Опции listbox (migx-формат "Caption==key||..." или "@SELECT ..."):
            // вставляем КАК ЕСТЬ в inputOptionValues, migx сам разбирает. См. makeFieldDef.
            'values' => trim((string)$el->getAttribute('data-mpc-values')),
            // Лимит записей списочного поля → extended.maxRecords авто-конфига.
            'max'    => trim((string)$el->getAttribute('data-mpc-max')),
        ];
    }

    /**
     * Элемент — структурный список уровня $rowLevel (его строки помечены
     * data-mpc-item для уровня 1, data-mpc-item-{rowLevel-1} для вложенных).
     */
    private function isStructuralList(Element $el, int $rowLevel): bool
    {
        $itemAttr = $rowLevel === 1 ? 'data-mpc-item' : 'data-mpc-item-' . ($rowLevel - 1);
        return (bool)$this->parser->findByAttribute($this->parser->getHTMLString($el), '[' . $itemAttr . ']');
    }

    /**
     * Динамически собирает row-конфиг произвольного списка из его полей и
     * сохраняет в БД (modx_migx), возвращая имя конфига. Поля строки берём по
     * всем data-mpc-field-{rowLevel} внутри списка (union по строкам, dedupe по
     * имени); вложенные списки разрешаются рекурсивно (configs → под-конфиг).
     *
     * @param array $byName прототипы mpc_base
     */
    private function buildListConfig(Element $listEl, int $rowLevel, string $namePath, array $byName, string $sectionName): string
    {
        $fieldAttr = 'data-mpc-field-' . $rowLevel;
        $entries   = $this->parser->findByAttribute($this->parser->getHTMLString($listEl), '[' . $fieldAttr . ']');

        $fields = [];
        $seen   = [];
        foreach ($entries as $e) {
            $name = trim((string)$e->getAttribute($fieldAttr));
            if ($name === '' || isset($seen[$name])) {
                continue;
            }
            $seen[$name] = true;

            $attrs = $this->fieldAttrs($e);
            $pos   = count($fields) + 1;

            if ($attrs['ftype'] === '' && !isset($byName[$name]) && $this->isStructuralList($e, $rowLevel + 1)) {
                $subName  = $this->buildListConfig($e, $rowLevel + 1, $namePath . '_' . $name, $byName, $sectionName);
                $fields[] = $this->listFieldDef($name, $subName, $attrs, $pos);
            } else {
                $fields[] = $this->makeFieldDef($name, $attrs, $byName, $this->elementKind($e), $pos);
            }
        }

        // data-mpc-max на контейнере списка → лимит числа записей (extended.maxRecords).
        $max = trim((string)$listEl->getAttribute('data-mpc-max'));

        $configName = $this->autoListName($namePath);
        $this->saveListConfig($configName, $fields, $sectionName, $max);
        $this->createdAutoConfigs[] = $configName;
        return $configName;
    }

    /** migx-поле (список), ссылающееся на под-конфиг по имени. */
    private function listFieldDef(string $name, string $configName, array $attrs, int $pos): array
    {
        $def = $this->blankFieldDef();
        $def['field']       = $name;
        $def['inputTVtype'] = 'migx';
        $def['configs']     = $configName;
        $fcap               = (string)($attrs['fcap'] ?? '');
        $def['caption']     = $fcap !== '' ? $fcap : ('Список (' . $name . ')');
        $def['description'] = (string)($attrs['fdesc'] ?? '');
        $def['MIGX_id']     = $pos;
        $def['pos']         = $pos;
        return $def;
    }

    /** Колонки грида из полей строки (картинки — рендер превью, списки — скрыты). */
    private function buildColumns(array $fields): array
    {
        $columns = [];
        $i = 1;
        foreach ($fields as $f) {
            $type = (string)($f['inputTVtype'] ?? '');
            $columns[] = [
                'MIGX_id'        => $i,
                'header'         => $f['caption'] ?? $f['field'],
                'dataIndex'      => $f['field'],
                'width'          => '',
                'sortable'       => 'false',
                'show_in_grid'   => $type === 'migx' ? 0 : 1,
                'customrenderer' => '',
                'renderer'       => $type === 'image' ? 'this.renderImage' : '',
                'clickaction'    => '',
                'selectorconfig' => '',
                'renderchunktpl' => '',
                'renderoptions'  => '',
                'editor'         => '',
            ];
            $i++;
        }
        return $columns;
    }

    /** Детерминированное имя авто-конфига списка (перенарезка апдейтит тот же). */
    private function autoListName(string $namePath): string
    {
        $slug = preg_replace('/[^a-z0-9_]+/', '_', strtolower($namePath));
        return 'mpc_auto_' . trim((string)$slug, '_');
    }

    /** Дефолтный extended-блоб для авто-конфига списка. */
    private function listExtendedDefault(): array
    {
        return [
            'addNewItemAt'        => 'bottom',
            'actionbuttonsperrow' => '4',
            'filtersperrow'       => '4',
            'use_custom_prefix'   => '0',
            'maxRecords'          => '',
        ];
    }

    /** Создаёт/обновляет migx-конфиг строки списка в БД. */
    private function saveListConfig(string $name, array $fields, string $sectionName, string $max = ''): void
    {
        $extended = $this->listExtendedDefault();
        $extended['mpc_owner'] = $sectionName; // владелец — для GC осиротевших конфигов
        if ($max !== '') {
            $extended['maxRecords'] = $max; // data-mpc-max → лимит записей migx
        }

        $data = [
            'name'     => $name,
            'formtabs' => json_encode([[
                'MIGX_id'           => 1,
                'caption'           => '',
                'print_before_tabs' => '0',
                'fields'            => $fields,
                'pos'               => 1,
            ]]),
            'columns'  => json_encode($this->buildColumns($fields)),
            'extended' => $extended,
            'editedon' => date('Y-m-d H:i:s'),
        ];

        $config = $this->modx->getObject('migxConfig', ['name' => $name]) ?: $this->modx->newObject('migxConfig');
        $config->fromArray($data);
        $config->save();
    }

    /**
     * Разрешает определение поля по имени + хинтам (ftype/fcap/fdesc) и
     * прототипам mpc_base. PURE: не использует зависимости инстанса.
     *
     * @param array $attrs  ['ftype'=>..,'fcap'=>..,'fdesc'=>..]
     * @param array $byName прототипы mpc_base, индексированные по field
     * @param string $elKind img|picture|video|audio|bg|other (для фолбэка)
     */
    public function makeFieldDef(string $name, array $attrs, array $byName, string $elKind, int $pos = 1): array
    {
        $ftype = (string)($attrs['ftype'] ?? '');
        $fcap  = (string)($attrs['fcap'] ?? '');
        $fdesc = (string)($attrs['fdesc'] ?? '');

        // Имя уже есть в mpc_base — берём его определение БЕЗУСЛОВНО (даже если
        // задан ftype): не плодим дубликат одноимённого поля. ftype в этом случае
        // игнорируется (имя приоритетнее). fcap/fdesc переопределяют подпись/описание.
        if (isset($byName[$name])) {
            $def = $byName[$name];
            if ($fcap !== '') {
                $def['caption'] = $fcap;
            }
            if ($fdesc !== '') {
                $def['description'] = $fdesc;
            }
            return $def;
        }

        // Прототип: явный ftype, иначе дефолт по элементу (медиа-теги/фон → свой
        // тип, прочее → text).
        $protoName = $ftype !== '' ? $ftype : $this->defaultProtoForKind($elKind);
        $def = isset($byName[$protoName])
            ? $byName[$protoName]                          // клон существующего прототипа (img/list_*/…)
            : $this->synthesizeScalar($protoName, $elKind); // text/textarea/richtext/checkbox/number

        $protoCaption = (string)($def['caption'] ?? $protoName);

        $def['field']       = $name;
        $def['caption']     = $fcap !== '' ? $fcap : ($protoCaption . ' (' . $name . ')');
        $def['description'] = $fdesc !== '' ? $fdesc : (string)($def['description'] ?? '');
        $def['MIGX_id']     = $pos;
        $def['pos']         = $pos;

        // data-mpc-values → опции listbox. Вставляем значение КАК ЕСТЬ в
        // inputOptionValues (migx сам разбирает "Caption==key||..." и "@SELECT ...").
        // Тип: если автор не задал listbox* через ftype — переключаем на listbox
        // (наличие опций подразумевает выпадайку).
        $values = (string)($attrs['values'] ?? '');
        if ($values !== '') {
            // keyed → Caption==norm(value); list → norm(value) (транслит+lowercase);
            // @SELECT — как есть. Значения совпадают с ключами лексикона опций.
            $def['inputOptionValues'] = LexiconManager::normalizeInputOptionValues($values);
            // Опции подразумевают тип-с-выбором. Если автор уже задал такой тип
            // (listbox/-multiple/option/radio/checkbox) — сохраняем его; иначе
            // (например ftype не задан) — дефолтим в listbox (выпадайку).
            $it = (string)($def['inputTVtype'] ?? '');
            if (!in_array($it, ['listbox', 'listbox-multiple', 'option', 'checkbox'], true)) {
                $def['inputTVtype'] = 'listbox';
            }
        }

        return $def;
    }

    /**
     * Дефолтный тип-прототип по элементу, когда ftype не задан: медиа-теги и
     * фон отдают свой тип (img/picture/video/audio/bg_img), прочее — text.
     */
    private function defaultProtoForKind(string $elKind): string
    {
        switch ($elKind) {
            case 'img':     return 'img';
            case 'picture': return 'picture';
            case 'video':   return 'video';
            case 'audio':   return 'audio';
            case 'bg':      return 'bg_img';
            default:        return 'text';
        }
    }

    /**
     * Синтез определения скалярного типа для поля, которого нет среди прототипов
     * mpc_base. По конвенции `data-mpc-ftype` = имя input-типа MODX TV, поэтому
     * прокидываем его в `inputTVtype` НАПРЯМУЮ — без отдельного белого списка
     * типов (он лишь дублировал бы перечень типов MODX и требовал правки на каждый
     * новый тип). Сверх identity нужны только: человекочитаемый caption (косметика)
     * и спец-дефолты там, где тип их требует. Фолбэк: пустой/непонятный ftype на
     * медиа-элементе → image (иначе сам тип/text).
     */
    private function synthesizeScalar(string $type, string $elKind): array
    {
        // Косметические подписи известных типов; для прочих — сам тип с заглавной.
        $captions = [
            'text' => 'Текстовое поле', 'textarea' => 'Текстовая область',
            'richtext' => 'Форматированный текст', 'number' => 'Число', 'date' => 'Дата',
            'email' => 'E-mail', 'url' => 'URL', 'file' => 'Файл', 'tag' => 'Теги',
            'autotag' => 'Теги', 'checkbox' => 'Флажки', 'option' => 'Переключатель',
            'listbox' => 'Список (выбор)', 'listbox-multiple' => 'Список (мультивыбор)',
            'image' => 'Изображение',
        ];

        $isMedia = in_array($elKind, ['img', 'picture', 'bg'], true);
        if ($type === '') {
            // ftype не задан → дефолт по элементу: медиа → image, прочее → text.
            $type = $isMedia ? 'image' : 'text';
        } elseif (!isset($captions[$type]) && $isMedia) {
            // ftype не похож на тип картинки, но элемент медийный → image (защита).
            $type = 'image';
        }
        // Иначе ftype идёт в inputTVtype как есть (пасс-тру, без белого списка типов).

        $spec = ['inputTVtype' => $type, 'caption' => $captions[$type] ?? ucfirst($type)];
        // checkbox без опций пуст — даём одну дефолтную (data-mpc-values переопределит).
        if ($type === 'checkbox') {
            $spec['inputOptionValues'] = 'Да==1';
        }
        return array_merge($this->blankFieldDef(), $spec);
    }

    /** Пустой каркас field-определения migx (все ключи, как в mpc_base). */
    private function blankFieldDef(): array
    {
        return [
            'MIGX_id'             => 1,
            'field'               => '',
            'caption'             => '',
            'description'         => '',
            'description_is_code' => '0',
            'inputTV'             => '',
            'inputTVtype'         => 'text',
            'validation'          => '',
            'configs'             => '',
            'restrictive_condition' => '',
            'display'             => '',
            'sourceFrom'          => 'config',
            'sources'             => '',
            'inputOptionValues'   => '',
            'default'             => '',
            'useDefaultIfEmpty'   => '0',
            'pos'                 => 1,
        ];
    }

    /** Грубая классификация элемента поля для фолбэка типа. */
    private function elementKind(Element $el): string
    {
        $tag = strtolower((string)$el->tagName());
        if (in_array($tag, ['img', 'picture', 'video', 'audio'], true)) {
            return $tag;
        }
        $style = (string)$el->getAttribute('style');
        if ($style !== '' && preg_match('/background(-image)?\s*:[^;]*url\s*\(/i', $style)) {
            return 'bg';
        }
        return 'other';
    }

    private function grabSection(Element $section, array $properties, ?int $i = 1): array
    {
        $sectionName = trim((string)$section->getAttribute('data-mpc-name'));
        $prefix = trim((string)$section->getAttribute('data-mpc-lexicon')) ?: $properties['sectionName'];
        $isStatic = $section->hasAttribute('data-mpc-static');

        $this->lexiconManager->setContext($prefix, $isStatic, !empty($properties['isCopy']));
        $this->mediaDownloader->setCurrentSectionName($properties['sectionName']);

        $sectionId = $properties['sectionName'] . '_' . str_replace(['.', ',', ' '], '', microtime(true));

        $fieldsValues = $this->contentParser->getFieldsValues($this->parser->getHTMLString($section));
        $fieldsValues['is_static'] = $isStatic;
        $fieldsValues = array_merge([
            'MIGX_id' => $i,
            'MIGX_formname' => $properties['sectionName'],
            'id' => $sectionId,
            'section_name' => $sectionName,
            'lexicon_prefix' => $prefix,
            'file_name' => $properties['fileNameVis'],
        ], $fieldsValues);

        $this->modx->invokeEvent('mpcOnGetSectionFieldsValues', [
            'sectionKey' => $properties['sectionName'],
            'fieldsValues' => $fieldsValues,
            'section' => $section,
            'Grabber' => $this,
        ]);

        $fieldsValues = isset($this->modx->event->returnedValues) && !empty($this->modx->event->returnedValues['fieldsValues'])
            ? $this->modx->event->returnedValues['fieldsValues'] : $fieldsValues;

        // Значения статик-секции мержим в конфиг статик-блоков (умный мерж внутри
        // updateStaticSectionValues: без updContent правки сохраняются, с — перезапись).
        if (!$properties['isCopy'] && $isStatic) {
            $this->updateStaticSectionValues($fieldsValues, $properties['sectionName']);
        }

        return $fieldsValues;
    }

    private function updateStaticSectionValues(array $sectionFieldsValues, string $sectionName): void
    {
        $upd = false;
        $i = 0;

        if (!empty($this->properties['sbpSectionValues'])) {
            foreach ($this->properties['sbpSectionValues'] as $k => $sectionValue) {
                if ($sectionValue['MIGX_formname'] === $sectionName) {
                    if (!$this->properties['fromPlugin']) {
                        // Умный мерж со старым значением секции (а не голая замена):
                        // правки сохраняются, ушедшие поля убираются, новые пишутся.
                        $this->properties['sbpSectionValues'][$k] = $this->mergeSectionFields(
                            $sectionFieldsValues, $sectionValue, $this->reservedFieldNames, !empty($this->properties['updContent'])
                        );
                    }
                    $upd = true;
                }
                $i = ++$k;
            }
        }

        if (!$upd) {
            $this->properties['sbpSectionValues'][$i] = $sectionFieldsValues;
        }
    }

    /**
     * Список защищённых от overwrite полей ресурса (rfield не пишет в них).
     * Настройка mpc_protected_resource_fields (csv). По умолчанию: alias/uri/template
     * + структурный минимум, чтобы разметка не сломала дерево/класс ресурса.
     */
    private function getProtectedResourceFields(): array
    {
        $list = $this->modx->getOption(
            'mpc_protected_resource_fields',
            null,
            'id,class_key,context_key,parent,uri_override,alias,uri,template'
        );
        return array_values(array_filter(array_map('trim', explode(',', (string)$list))));
    }

    private function getObject(string $className, array $conditions, ?bool $asArray = false): array
    {
        if ($object = $this->modx->getObject($className, $conditions)) {
            if ($asArray) {
                return $this->response->success(__METHOD__, 'Object found', ['object' => $object->toArray()]);
            }
            return $this->response->success(__METHOD__, 'Object found', ['object' => $object]);
        }
        return $this->response->error(__METHOD__, 'Object not found', ['conditions' => $conditions, 'className' => $className]);
    }
}
