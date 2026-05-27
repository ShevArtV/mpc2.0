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
            'copyConfigTvName' => $this->modx->getOption('mpc_copy_config_tv_name', '', ''),
            'extension' => $this->modx->getOption('mpc_tpl_file_extension', '', '.tpl'),
        ];

        $this->properties = array_merge($this->properties, $properties);
        if (file_exists($excludeFieldsPath) && $excludeFields = file_get_contents($excludeFieldsPath)) {
            $this->properties['excludeFields'] = json_decode($excludeFields, 1);
        }
        $this->properties['sectionChunkPrefix'] = '@FILE ' . $this->properties['pathToSections'];
        $this->pdo = $this->modx->getService('pdoTools') ?? $this->modx;
        $this->pdo->config['elementsPath'] = str_replace('\\', '/', $this->pdo->config['elementsPath']);
    }

    /** @var bool edit-mode рендер (mpcVE) — берёт _edit-чанки, собирает в память */
    private bool $editMode = false;

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
     * Edit-mode рендер для mpcVE: собирает страницу В ПАМЯТИ из _edit-чанков
     * (с сохранёнными data-mpc-* маркерами), без записи в parsed/. Возвращает
     * готовый HTML — фронт-редактор по маркерам находит поля и их адреса.
     * Прод-рендер (handle/putToFile) не затрагивается.
     */
    public function renderEditMode(array $resourceData): string
    {
        $this->editMode = true;
        $resourceData = $this->prepareResourceData($resourceData);
        if (empty($resourceData['sections'])) {
            return '';
        }
        if ($this->wrapperTpl) {
            $html = $this->pdo->parseChunk($this->wrapperTpl, $resourceData);
            $html = str_replace('##', '{', $html);
        } else {
            $html = implode("\n", $resourceData['sections']);
        }
        return $html;
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
        $resourceConfig = $this->reformatConfig(json_decode($resourceData['tvs'][$this->properties['commonConfigTvName']], true));
        $typeConfig = $resourceData['tvs']['donor_' . $this->properties['commonConfigTvName']]
            ? $this->reformatConfig(json_decode($resourceData['tvs']['donor_' . $this->properties['commonConfigTvName']], true)) : [];
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

        $sectionsHtml = [];
        $i = 1;
        foreach ($sections as $section) {
            // пропускаем базовую секцию или ту, которую нужно скрыть
            if ($section['MIGX_formname'] === $this->properties['baseSectionName'] || $section['hide_section']) {
                continue;
            }

            if ($section['is_static'] && $staticConfig[$section['section_name']]) {
                $section = $staticConfig[$section['section_name']];
            }
            $section['contacts'] = $this->contacts;
            $section['rid'] = $resourceData['id']; // передаем на страницу id текущего ресурса
            $section['idx'] = $i; // передаем на страницу порядковый номер секции
            $section['sbp_id'] = $this->properties['staticBlocksPageId']; // передаем на страницу id ресурса со статичными блоками
            $section['cp_id'] = $this->properties['contactsPageId']; // передаем на страницу id ресурса с контактами

            $sets = PHP_EOL . "{set \$section = '!getStaticSection'| snippet:['section_name' => '{$section['MIGX_formname']}']}{if \$section}";

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
            $chunkName = $section['MIGX_formname']; // получаем имя чанка
            $chunkBaseName = strtolower($chunkName);
            $sectionsDir = $this->properties['pdotoolsElementsPath'] . $this->properties['pathToSections'];
            $unstaticFilePath = $sectionsDir . $chunkBaseName . '_unstatic' . $this->properties['extension'];

            // Если секция была статичной (есть _unstatic), но в данном ресурсе она нестатичная — берём _unstatic чанк
            if (!$section['is_static'] && file_exists($unstaticFilePath)) {
                $chunkSuffix = $chunkBaseName . '_unstatic';
            } else {
                $chunkSuffix = $chunkBaseName;
            }
            // edit-mode: берём _edit-чанк (data-mpc-* маркеры сохранены), если он есть
            if ($this->editMode) {
                $editFilePath = $sectionsDir . $chunkBaseName . '_edit' . $this->properties['extension'];
                if (file_exists($editFilePath)) {
                    $chunkSuffix = $chunkBaseName . '_edit';
                }
            }
            $chunk = $this->properties['sectionChunkPrefix'] . $chunkSuffix . $this->properties['extension']; // получаем путь к чанку

            $tmp = $this->pdo->parseChunk($chunk, $section); // парсим чанк
            if ($section['is_static']) {
                $tmp = $sets . $tmp;
            }
            $tmp = str_replace('##', '{', $tmp); // чтобы на фронте работал парсер pdoTools

            // Только прод-рендер: в edit-mode (mpcVE) событие не дёргаем — плагин
            // мог бы переписать HTML секции и сломать data-mpc-маркеры редактора.
            if (!$this->editMode) {
                $this->modx->invokeEvent('mpcOnGetSectionHtml', [
                    'section' => $section,
                    'html' => $tmp,
                    'Render' => $this,
                ]);
                $tmp = isset($this->modx->event->returnedValues) && !empty($this->modx->event->returnedValues['html'])
                    ? $this->modx->event->returnedValues['html'] : $tmp;
            }

            $sectionsHtml[] = $tmp;
            $i++;
        }
        return $sectionsHtml;
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
                    if (!is_array($v) && strpos($v, '[{') !== false) {
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
            $html = str_replace('##', '{', $html);
        } else {
            $html = $resourceData['sections'] ? implode('\n', $resourceData['sections']) : $resourceData['content'];
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
                        $config = $resource->getTVValue($this->properties['commonConfigTvName']);
                        $copy_all = $resource->getTVValue($this->properties['copyConfigTvName']);
                        if (!$config && $copy_all) {
                            $resource->setTVValue($this->properties['commonConfigTvName'], $typeConfig); // копируем конфиг типа полностью
                            $resource->setTVValue($this->properties['copyConfigTvName'], false);
                        } else {
                            // копируем содержимое отдельных секций
                            $flag = false;
                            $config = json_decode($config, 1) ?: [];
                            $typeConfig = $this->reformatConfig(json_decode($typeConfig, 1));
                            if (!empty($config)) {
                                foreach ($config as $key => $item) {
                                    if ($item['copy_from_origin'] && $typeConfig[$item['section_name']]) {
                                        $flag = true;
                                        $config[$key] = array_merge($item, $typeConfig[$item['section_name']]);
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
            $fileNames = scandir($basePath);
            unset($fileNames[0], $fileNames[1]);
            foreach ($fileNames as $fileName) {
                unlink($basePath . $fileName);
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
        //$this->modx->log(1, print_r($q->toSQL(), 1));
        if ($q->stmt->execute()) {
            $this->modx->queryTime += microtime(true) - $tstart;
            $this->modx->executedQueries++;
            return $q->stmt->fetchAll(implode('|', $fetchType));
        }
        return [];
    }
}
