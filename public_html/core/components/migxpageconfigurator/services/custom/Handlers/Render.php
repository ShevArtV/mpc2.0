<?php

/**
 * Сервис для отрисовки секций.
 */

namespace MpcServices\Handlers;

/**
 * @author Arthur Shevchenko (https://t.me/ShevArtV)
 */
class Render extends Base
{
    /**
     * @var \modX|object
     */
    private object $pdo;
    /**
     * @var string
     */
    public string $wrapperTpl;
    /**
     * @var array
     */
    public array $contacts;
    /**
     * @return void
     */
    protected function initialize(): void
    {
        parent::initialize();
        $excludeFieldsPath = $this->modx->getOption(
            'mpc_exclude_fields_path',
            null,
            $this->properties['corePath'] . 'components/migxpageconfigurator/elements/fields/exclude_fields.json'
        );
        $properties = [
            'commonConfigTvId' => $this->modx->getOption('mpc_config_tv_id', '', 0),
            'extension' => $this->modx->getOption('mpc_tpl_file_extension', '', '.tpl'),
        ];

        $this->properties = array_merge($this->properties, $properties);
        if (file_exists($excludeFieldsPath) && $excludeFields = file_get_contents($excludeFieldsPath)) {
            $this->properties['excludeFields'] = json_decode($excludeFields, 1);
        }
        $this->pdo = $this->modx->getService('pdoTools') ?? $this->modx;
        // Фолбэк на $modx (нет pdoTools) не содержит ключа elementsPath —
        // ?? '' убирает undefined-index/deprecation, поведение прежнее (пустой путь).
        $this->pdo->config['elementsPath'] = str_replace('\\', '/', $this->pdo->config['elementsPath'] ?? '');
    }

    /**
     * Индекс контентного таба в формтабсе конфигуратора. По соглашению нарезки
     * (SectionProcessor пишет data-mpc-field в defaultFormTabs[1]) контент всегда
     * лежит в табе [1]; прочие табы («Настройки», «Стили» и кастомные) — каскадные.
     */
    private const CONTENT_TAB_INDEX = 1;

    /**
     * @param array $resourceData
     * @return bool
     */
    public function handle(array $resourceData): bool
    {
        $resourceData = $this->prepareResourceData($resourceData);
        return !empty($resourceData['sections']) && $this->putToFile($resourceData);
    }

    /**
     * Общая подготовка resourceData (контакты, wrapper, TV, уровень type,
     * парсинг секций, событие mpcOnBeforeRender). Используется handle() и
     * renderEditMode(). В edit-mode parseConfig берёт _edit-чанки.
     */
    private function prepareResourceData(array $resourceData): array
    {
        $this->contacts = $this->getContacts();
        $this->wrapperTpl = $this->getWrapperTpl($resourceData['template']);

        $resourceData = $this->updateResourceData($resourceData);
        $resourceData['tvs'] = $this->getResourceTVs($resourceData['id']);
        // Уровень «тип страницы» (mpcType — по шаблону) — база, поверх которой
        // ложатся данные самого ресурса. Приоритет рендера: resource > type.
        if ($type = $this->getTypeResource($resourceData['template'])) {
            $typeResourceData = $this->updateResourceData($type->toArray());
            $resourceData = array_merge($typeResourceData, $resourceData);
            $resourceData['tvs'] = array_merge($this->getResourceTVs($typeResourceData['id'], true), $resourceData['tvs']);
        }
        $resourceData['contacts'] = $this->contacts;
        $resourceData['sbp_id'] = $this->properties['staticBlocksPageId']; // id ресурса со статичными блоками
        $resourceData['cp_id'] = $this->properties['contactsPageId']; // id ресурса с контактами
        $resourceData['sections'] = $this->parseConfig($resourceData);

        $this->modx->invokeEvent('mpcOnBeforeRender', [
            'resourceData' => $resourceData,
            'Render' => $this,
        ]);
        $resourceData = isset($this->modx->event->returnedValues) && !empty($this->modx->event->returnedValues['resourceData'])
            ? $this->modx->event->returnedValues['resourceData'] : $resourceData;

        return $resourceData;
    }

    /**
     * Ресурс уровня «тип страницы» (mpcType): донор с тем же шаблоном под
     * коллекцией (staticBlocksPage). База, поверх которой ложится сам ресурс
     * (приоритет resource > type). Связь по шаблону — как и было.
     *
     * @param mixed $template id шаблона ресурса
     * @return \modResource|null
     */
    private function getTypeResource($template)
    {
        if (empty($template)) {
            return null;
        }
        return $this->modx->getObject('modResource', [
            'parent'   => $this->properties['staticBlocksPageId'],
            'template' => $template,
        ]);
    }

    /**
     * @return array
     */
    public function getContacts(): array
    {
        $output = [];
        $rid = $this->properties['contactsPageId'];
        $tvId = $this->properties['contactsTvId'];
        $contacts = $this->getTVById($rid, $tvId);
        $contacts = json_decode($contacts, true);

        if (is_array($contacts) && !empty($contacts)) {
            foreach ($contacts as $item) {
                $placement = 'default';
                if ($contactInfo = json_decode($item['contact_info'], true)) {
                    foreach ($contactInfo as $v) {
                        $placement = $v['placement'];
                        $output[$placement][$item['ckey']] = [
                            'caption' => $v['caption'],
                            'fvalue' => $item['fvalue'],
                            'attributes' => $v['attributes'],
                            'placement' => $v['placement'],
                            'value' => $item['value'],
                            'type' => $item['type'],
                        ];
                    }
                } else {
                    $output[$placement][$item['ckey']] = [
                        'value' => $item['value'],
                        'fvalue' => $item['fvalue'],
                        'type' => $item['type'],
                    ];
                }
            }
        }

        return $output;
    }

    /**
     * @param int $rid
     * @param int $tvId
     * @return string
     */
    public function getTVById(int $rid, int $tvId): string
    {
        $q = $this->modx->newQuery('modTemplateVarResource');
        $q->select('value');
        $q->where([
            'tmplvarid' => $tvId,
            'contentid' => $rid
        ]);

        if ($value = $this->execute($q, [\PDO::FETCH_COLUMN])) {
            return $value[0];
        }

        return '';
    }

    /**
     * @param int $templateId
     * @return string
     */
    private function getWrapperTpl(int $templateId): string
    {
        $q = $this->modx->newQuery('modTemplate');
        $q->select('description');
        $q->where(['id' => $templateId]);
        if ($result = $this->execute($q, [\PDO::FETCH_COLUMN])) {
            return $result[0];
        }
        return '';
    }

    /**
     * @param array $resourceData
     * @return array
     */
    private function updateResourceData(array $resourceData): array
    {
        foreach ($resourceData as $k => $v) {
            if (strpos($k, 'tv') === 0) {
                unset($resourceData[$k]);
            }
        }

        return $resourceData;
    }

    /**
     * @param int $rid
     * @param bool $isDonor
     * @return array
     */
    private function getResourceTVs(int $rid, bool $isDonor = false): array
    {
        $q = $this->modx->newQuery('modTemplateVar');
        $q->setClassAlias('TV');
        $q->leftJoin('modTemplateVarResource', 'TVResource', 'TV.id = TVResource.tmplvarid');
        $q->select('TV.name as name, TVResource.value as value');
        $q->where([
            'TVResource.contentid' => $rid,
        ]);
        if ($tvs = $this->execute($q)) {
            if (!empty($tvs)) {
                return $this->reformatTVs($tvs, $isDonor);
            }
        }

        return [];
    }

    /**
     * @param array $tvs
     * @param bool $isDonor
     * @return array
     */
    private function reformatTVs(array $tvs, bool $isDonor = false): array
    {
        $resourceTVs = [];
        foreach ($tvs as $tv) {
            if ($tv['name'] === $this->properties['commonConfigTvName'] && $isDonor) {
                $resourceTVs['donor_' . $tv['name']] = $tv['value'];
            } else {
                $resourceTVs[$tv['name']] = $tv['value'];
            }
        }
        return $resourceTVs;
    }

    /**
     * @param array $resourceData
     * @return array
     */
    private function parseConfig(array $resourceData): array
    {
        $staticConfig = '';
        // resourceConfig — конфиг самого ресурса; typeConfig — конфиг типа
        // страницы (mpcType; ключ TV исторически с префиксом donor_).
        // ?? '' на случай отсутствия TV-ключа: json_decode('') → null →
        // reformatConfig() → [] (прежнее поведение «пустой конфиг»), но без
        // undefined-index warning при ресурсе без TV конфигуратора.
        $cfgTv    = $this->properties['commonConfigTvName'];
        $donorTv  = 'donor_' . $cfgTv;
        $resourceConfig = $this->reformatConfig(json_decode((string)($resourceData['tvs'][$cfgTv] ?? ''), true));
        $typeConfig = !empty($resourceData['tvs'][$donorTv])
            ? $this->reformatConfig(json_decode((string)$resourceData['tvs'][$donorTv], true)) : [];
        // Приоритет рендера: тип — база, ресурс перекрывает (resource > type).
        $sections = array_merge($typeConfig, $resourceConfig);

        $this->modx->invokeEvent('mpcOnBeforeParseConfig', [
            'sections' => $sections,
            'Render' => $this
        ]);

        $sections = isset($this->modx->event->returnedValues) && !empty($this->modx->event->returnedValues['sections'])
            ? $this->modx->event->returnedValues['sections'] : $sections;

        if (empty($sections)) {
            return [];
        }
        uasort($sections, function ($a, $b) {
            if ($a['position'] == $b['position']) {
                return 0;
            }
            return ($a['position'] < $b['position']) ? -1 : 1;
        });

        if ($staticResource = $this->modx->getObject('modResource', $this->properties['staticBlocksPageId'])) {
            if ($staticConfig = $staticResource->getTVValue($this->properties['commonConfigTvName'])) {
                $staticConfig = $this->reformatConfig(json_decode($staticConfig, true)); // декодируем конфиг в массив
            }
        }

        if (file_exists($this->properties['corePath'] . 'components/minishop2/')) {
            if ($files = $this->modx->getIterator('msProductFile', ['product_id' => $resourceData['id'], 'parent:!=' => 0])) {
                foreach ($files as $file) {
                    $fileData = $file->toArray();
                    $type = str_replace('image/', '', $fileData['properties']['mime']);
                    $width = $fileData['properties']['width'];
                    $height = $fileData['properties']['height'];
                    $resourceData['gallery']["{$width}x{$height}"][$type][] = $file->toArray();
                }
            }
        }

        $cascadeFields = $this->getCascadeFieldsMap();

        return $this->renderSections($sections, $resourceData, $staticConfig, $cascadeFields);
    }

    /**
     * Рендер-цикл секций: пропуск базовой/скрытой, каскад настроек static-секции,
     * служебные поля (rid/idx/sbp_id/cp_id/contacts/resource), {set}-блок для
     * static, привязка и parseChunk чанка, конверсия ##→{ + кавычки параметров,
     * событие mpcOnGetSectionHtml. Возвращает массив HTML секций по порядку.
     */
    private function renderSections(array $sections, array $resourceData, $staticConfig, array $cascadeFields): array
    {
        $sectionsHtml = [];
        $i = 1;
        foreach ($sections as $section) {
            // пропускаем базовую секцию или ту, которую нужно скрыть
            if (($section['MIGX_formname'] ?? '') === $this->properties['baseSectionName'] || !empty($section['hide_section'])) {
                continue;
            }

            if (!empty($section['is_static']) && !empty($staticConfig[$section['section_name'] ?? ''])) {
                // Контент static-секции — из staticBlocksPage; настройки/стили
                // (каскадные поля) — из ресурсного конфига с наследованием.
                // Покрывает eager-поля, запекаемые parseChunk на рендере;
                // отложенные (## → getStaticSection) каскадятся в самом сниппете.
                $section = $this->applyCascadeOverrides(
                    $staticConfig[$section['section_name']],
                    $section,
                    $cascadeFields
                );
            }
            $section['contacts'] = $this->contacts;
            $section['rid'] = $resourceData['id']; // передаем на страницу id текущего ресурса
            $section['idx'] = $i; // передаем на страницу порядковый номер секции
            $section['sbp_id'] = $this->properties['staticBlocksPageId']; // передаем на страницу id ресурса со статичными блоками
            $section['cp_id'] = $this->properties['contactsPageId']; // передаем на страницу id ресурса с контактами

            // имя секции вставляется в одинарно-кавыченный Fenom-литерал —
            // экранируем `\` и `'`, чтобы значение не разорвало литерал. Внутри
            // '...' Fenom не интерполирует {$...}, поэтому единственный вектор
            // пробоя — закрывающая кавычка. Символы НЕ вырезаем: имя секции может
            // содержать пробелы/кириллицу и должно совпасть в getStaticSection.
            $formname = str_replace(['\\', "'"], ['\\\\', "\\'"], (string)($section['MIGX_formname'] ?? ''));
            $sets = PHP_EOL . "{set \$section = '!getStaticSection'| snippet:['section_name' => '{$formname}']}{if \$section}";

            foreach ($section as $key => $value) {
                if (is_string($value) && strpos($value, '[{') !== false) {
                    $section[$key] = $this->jsonDecodeValue(json_decode($value, true)); // преобразуем поля типа migx в массив
                }

                if ($section['is_static']) {
                    $keys = array_keys($this->properties['excludeFields']);
                    if (!in_array($key, $keys)) {
                        $sets .= "{set \$$key = \$section.$key}";
                    }
                }
            }
            $sets .= '{/if}' . PHP_EOL;
            $section['resource'] = $resourceData; // передаем на страницу все поля ресурса
            // Привязка чанка: file_name секции (путь от папки элементов pdoTools)
            // приоритетнее деривации из MIGX_formname; _unstatic-вариант для
            // не-статичной секции выбирается внутри. Edit-mode рендерится тем же
            // путём (при mpc_edit_mode каттер не режет data-mpc-* в base/_unstatic).
            $chunk = $this->getSectionChunkBinding($section);

            $tmp = $this->pdo->parseChunk($chunk, $section); // парсим чанк
            if ($section['is_static']) {
                $tmp = $sets . $tmp;
            }
            $tmp = FenomFormatter::convertStaticHashToBrace($tmp); // ##→{ для фронт-парсера pdoTools (не трогая data-mpc-*)
            $tmp = FenomFormatter::quoteSnippetParamValues($tmp);  // голые значения параметров (## eager-резолв) → в кавычки

            $this->modx->invokeEvent('mpcOnGetSectionHtml', [
                'section' => $section,
                'html' => $tmp,
                'Render' => $this,
            ]);
            $tmp = isset($this->modx->event->returnedValues) && !empty($this->modx->event->returnedValues['html'])
                ? $this->modx->event->returnedValues['html'] : $tmp;

            $sectionsHtml[] = $tmp;
            $i++;
        }
        return $sectionsHtml;
    }

    /**
     * Возвращает @FILE-привязку чанка секции для parseChunk.
     *
     * Если в данных секции задан file_name — берём его как путь к файлу-чанку
     * ОТНОСИТЕЛЬНО папки элементов pdoTools (`pdotools_elements_path` — корень
     * для @FILE). Так секцию можно нацелить на произвольный чанк, не завязываясь
     * на MIGX_formname. Грабер/легаси могли сохранить file_name полным путём —
     * срезаем абсолютный префикс папки элементов, если он присутствует. Без
     * file_name — прежняя деривация имени из MIGX_formname.
     *
     * Для НЕ-статичной секции предпочитаем _unstatic-вариант (как и раньше),
     * если соответствующий файл существует.
     *
     * @param array $section
     * @return string
     */
    private function getSectionChunkBinding(array $section): string
    {
        $elementsPath = $this->properties['pdotoolsElementsPath'];
        $ext          = $this->properties['extension'];

        $fileName = trim((string)($section['file_name'] ?? ''));
        if ($fileName !== '') {
            $relPath = str_replace('\\', '/', $fileName);
            if ($elementsPath !== '' && strpos($relPath, $elementsPath) === 0) {
                $relPath = substr($relPath, strlen($elementsPath));
            }
            $relPath = ltrim($relPath, '/');
        } else {
            $relPath = $this->properties['pathToSections']
                . strtolower((string)$section['MIGX_formname']) . $ext;
        }

        // Не-статичная секция → _unstatic-вариант, если он есть рядом с базовым.
        if (empty($section['is_static'])) {
            $base        = (substr($relPath, -strlen($ext)) === $ext)
                ? substr($relPath, 0, -strlen($ext))
                : $relPath;
            $unstaticRel = $base . '_unstatic' . $ext;
            if (file_exists($elementsPath . $unstaticRel)) {
                $relPath = $unstaticRel;
            }
        }

        return '@FILE ' . $relPath;
    }

    /**
     * @param array|null $config
     * @return array
     */
    private function reformatConfig(?array $config): array
    {
        $result = [];
        if (!empty($config)) {
            $c = 1;
            foreach ($config as $item) {
                $key = $item['section_name'];
                $item['position'] = (int)$item['position'] ?: $c++;
                $item['copy_from_origin'] = 0;
                $result[$key] = $item;
            }
        }
        return $result;
    }

    /**
     * Множество имён каскадных полей (настройки/стили) — все поля табов
     * конфигуратора, КРОМЕ контентного (self::CONTENT_TAB_INDEX). Берём из
     * ЖИВОГО конфига mpc_base в БД, а не из сида: на сайте в табы «Настройки»/
     * «Стили» могли добавить свои поля. Для static-секции контент идёт из
     * staticBlocksPage, а каскадные поля — из ресурсного конфига, что и
     * позволяет менять отображение одних и тех же данных per-resource/type.
     *
     * Пустой результат (нет конфига/формтабса) → merge-split вырождается в
     * прежнее поведение (полная замена статикой) — безопасный фоллбэк.
     *
     * @return array<string,true> ключ => true для быстрой проверки isset()
     */
    public function getCascadeFieldsMap(): array
    {
        // Кешируем на запрос: getStaticSection создаёт new Mpc на каждую секцию,
        // поэтому кеш функционально-статический (переживает разные экземпляры).
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        // migxConfig — из пакета migx; регистрируется в Base::initialize (PackageBootstrap).

        $map = [];
        if (!$base = $this->modx->getObject('migxConfig', ['name' => $this->properties['baseSectionName']])) {
            return $cache = $map;
        }
        $tabs = json_decode((string)$base->get('formtabs'), true);
        if (!is_array($tabs)) {
            return $cache = $map;
        }
        foreach ($tabs as $idx => $tab) {
            if ((int)$idx === self::CONTENT_TAB_INDEX) {
                continue; // контентный таб → значения из staticBlocksPage
            }
            foreach ($tab['fields'] ?? [] as $field) {
                if (!empty($field['field'])) {
                    $map[$field['field']] = true;
                }
            }
        }
        return $cache = $map;
    }

    /**
     * Накладывает каскадные поля (настройки/стили) из секции-источника
     * (ресурс/тип) на статичный контент с наследованием: значение источника
     * перекрывает статику только если задано (пусто/отсутствует → статика).
     * Работает и для строковых, и для массивных полей (передаём как есть).
     *
     * @param array $staticSection статичный контент (из staticBlocksPage)
     * @param array $sourceSection секция-источник каскада (ресурс/тип)
     * @param array<string,true> $cascadeFields карта каскадных полей
     * @return array
     */
    public function applyCascadeOverrides(array $staticSection, array $sourceSection, array $cascadeFields): array
    {
        $overrides = [];
        foreach ($cascadeFields as $field => $_) {
            if (isset($sourceSection[$field]) && $sourceSection[$field] !== '' && $sourceSection[$field] !== null) {
                $overrides[$field] = $sourceSection[$field];
            }
        }
        return array_merge($staticSection, $overrides);
    }

    /**
     * Каскад настроек/стилей для static-секции на ЭТАПЕ РЕНДЕРА ФРОНТА
     * (вызывается из сниппета getStaticSection): отложенные поля static-секции
     * резолвятся на фронте через getStaticSection, поэтому каскад нужно
     * применить именно там, иначе ресурсные стили перетираются статикой.
     * Приоритет как в parseConfig: тип — база, ресурс перекрывает.
     *
     * @param array $staticSection секция из staticBlocksPage
     * @param int $resourceId id текущего ресурса
     * @return array
     */
    public function applyResourceCascade(array $staticSection, int $resourceId): array
    {
        $key = $staticSection['section_name'] ?? '';
        if (!$key || !$resourceId) {
            return $staticSection;
        }

        // Кеш конфигов по ресурсу (тот же мотив, что и в getCascadeFieldsMap).
        static $sourceCache = [];
        if (!array_key_exists($resourceId, $sourceCache)) {
            $sourceCache[$resourceId] = [];
            if ($resource = $this->modx->getObject('modResource', $resourceId)) {
                $resourceConfig = $this->reformatConfig(json_decode((string)$resource->getTVValue($this->properties['commonConfigTvName']), true));
                $typeConfig = [];
                if ($type = $this->getTypeResource($resource->get('template'))) {
                    $typeConfig = $this->reformatConfig(json_decode((string)$type->getTVValue($this->properties['commonConfigTvName']), true));
                }
                // тип — база, ресурс перекрывает (как $sections в parseConfig)
                foreach ($resourceConfig as $k => $sec) {
                    $sourceCache[$resourceId][$k] = array_merge($typeConfig[$k] ?? [], $sec);
                }
                foreach ($typeConfig as $k => $sec) {
                    $sourceCache[$resourceId][$k] = $sourceCache[$resourceId][$k] ?? $sec;
                }
            }
        }

        if (empty($sourceCache[$resourceId][$key])) {
            return $staticSection;
        }
        return $this->applyCascadeOverrides($staticSection, $sourceCache[$resourceId][$key], $this->getCascadeFieldsMap());
    }

    /**
     * @param $value
     * @return mixed
     */
    public function jsonDecodeValue($value)
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                if (!is_array($item)) {
                    continue;
                }
                foreach ($item as $k => $v) {
                    if (is_string($v) && strpos($v, '[{') !== false) {
                        $item[$k] = json_decode($v, 1); // преобразуем поля типа migx в массив
                        $item[$k] = $this->jsonDecodeValue($item[$k]);
                    } else {
                        $item[$k] = $this->jsonDecodeValue($v);
                    }
                }
                $value[$key] = $item;
            }
        }

        return $value;
    }

    /**
     * @param array $resourceData
     * @return bool
     */
    private function putToFile(array $resourceData): bool
    {
        if (!file_exists($this->properties['pdotoolsElementsPath'] . $this->properties['pathToDist'])) {
            mkdir($this->properties['pdotoolsElementsPath'] . $this->properties['pathToDist'], 0777, true);
        }

        $pathToFile = $this->properties['pdotoolsElementsPath'] . $this->properties['pathToDist'] . $resourceData['id'] . $this->properties['extension'];
        if ($this->wrapperTpl) {
            $html = $this->pdo->parseChunk($this->wrapperTpl, $resourceData);
            $html = FenomFormatter::convertStaticHashToBrace($html);
            $html = FenomFormatter::quoteSnippetParamValues($html);
        } else {
            $html = $resourceData['sections'] ? implode("\n", $resourceData['sections']) : $resourceData['content'];
        }
        $html = preg_replace('/ => {(.*?)\| lexicon}/', ' => ($1| lexicon)', $html);
        if (!file_put_contents($pathToFile, $html)) {
            $this->response->error(__METHOD__, 'Failed to save section file' . $pathToFile);
            return false;
        }
        return true;
    }

    /**
     * @param \modResource $resource
     * @return void
     */
    public function copyConfig(\modResource $resource): void
    {
        $template = $resource->get('template');
        $parent = $resource->get('parent');
        if ($parent !== $this->properties['staticBlocksPageId']) {
            if ($template && $this->modx->getCount('modTemplateVarTemplate', array('tmplvarid' => $this->properties['commonConfigTvId'], 'templateid' => $template))) {
                if ($type = $this->getTypeResource($template)) {
                    if ($typeConfig = $type->getTVValue($this->properties['commonConfigTvName'])) {
                        // Копируем содержимое отдельных секций с флагом copy_from_origin
                        // из типа в ресурс. (Полное копирование по галочке copy_sections
                        // убрано — заменено кнопками тулбара грида mpc_config.)
                        $config = $resource->getTVValue($this->properties['commonConfigTvName']);
                        $flag = false;
                        $config = json_decode($config, 1) ?: [];
                        $typeConfig = $this->reformatConfig(json_decode($typeConfig, 1));
                        if (!empty($config)) {
                            foreach ($config as $key => $item) {
                                $sectionName = $item['section_name'] ?? '';
                                if (!empty($item['copy_from_origin']) && !empty($typeConfig[$sectionName])) {
                                    $flag = true;
                                    $config[$key] = array_merge($item, $typeConfig[$sectionName]);
                                }
                            }
                        }
                        if ($flag) {
                            $config = json_encode($config);
                            $resource->setTVValue($this->properties['commonConfigTvName'], $config);
                        }
                    }
                }
            }
        }
    }

    /**
     * @param string|null $ids
     * @return void
     */
    public function clearCache(?string $ids = ''): void
    {
        $basePath = $this->properties['pdotoolsElementsPath'] . $this->properties['pathToDist'];
        if (!file_exists($basePath)) {
            return;
        }
        if ($ids) {
            $ids = explode(',', $ids);
            foreach ($ids as $id) {
                $this->deleteParsedConfigFile($id);
            }
        } else {
            foreach (scandir($basePath) as $fileName) {
                if ($fileName === '.' || $fileName === '..') {
                    continue;
                }
                $full = $basePath . $fileName;
                if (is_file($full)) {
                    unlink($full);
                }
            }
        }
    }

    /**
     * @param int $rid
     * @return void
     */
    public function deleteParsedConfigFile(int $rid): void
    {
        $basePath = $this->properties['pdotoolsElementsPath'] . $this->properties['pathToDist'];
        if (file_exists($basePath . $rid . $this->properties['extension'])) {
            unlink($basePath . $rid . $this->properties['extension']);
        }
    }

    /**
     * @param \xPDOQuery $q
     * @param array $fetchType
     * @return array
     */
    protected function execute(\xPDOQuery $q, array $fetchType = [\PDO::FETCH_ASSOC]): array
    {
        $tstart = microtime(true);
        $q->prepare();
        if ($q->stmt->execute()) {
            $this->modx->queryTime += microtime(true) - $tstart;
            $this->modx->executedQueries++;
            // spread, а не implode('|'): несколько констант давали строку "7|0"
            // вместо корректного int-режима PDO. fetchAll(mode, ...args).
            return $q->stmt->fetchAll(...$fetchType);
        }
        return [];
    }
}
