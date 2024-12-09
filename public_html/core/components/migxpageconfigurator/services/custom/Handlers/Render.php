<?php

/**
 * Сервис для отрисовки секций.
 */

namespace CustomServices\Handlers;

/**
 * @author Arthur Shevchenko (https://t.me/ShevArtV)
 */
class Render extends Base
{
    /**
     * @var \modX|object
     */
    private object $pdo;
    private string $wrapperTpl;

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
            'defaultLangKey' => $this->modx->getOption('polylang_visitor_default_language', '', false),
        ];
        $this->properties = array_merge($this->properties, $properties);
        if (file_exists($excludeFieldsPath) && $excludeFields = file_get_contents($excludeFieldsPath)) {
            $this->properties['excludeFields'] = json_decode($excludeFields, 1);
        }
        $this->properties['sectionChunkPrefix'] = '@FILE ' . $this->properties['pathToSections'];

        $this->pdo = $this->modx->getService('pdoTools') ?? $this->modx;
        $this->pdo->config['elementsPath'] = str_replace('\\', '/', $this->pdo->config['elementsPath']);
    }

    public function handle(\modResource $resource): bool
    {
        $resourceData = $resource->toArray();
        $this->wrapperTpl = $this->getWrapperTpl($resourceData['template']);
        $resourceData['tvs'] = [];
        foreach ($resourceData as $k => $v) {
            if (strpos($k, 'tv') === 0) {
                unset($resourceData[$k]);
            }
        }
        $rid = $resourceData['id'];
        $donorConfig = '';
        $staticConfig = '';

        if ($donor = $this->modx->getObject('modResource', ['parent' => $this->properties['staticBlocksPageId'], 'template' => $resourceData['template']])) {
            $donorConfig = $donor->getTVValue($this->properties['commonConfigName']);
            foreach ($resourceData as $k => $v) {
                if (!$v) {
                    unset($resourceData[$k]);
                }
            }
            $resourceData = array_merge($donor->toArray(), $resourceData);
            $resourceData['tvs'] = $this->getResourceTVs($donor->get('id'));
        }
        if ($staticResource = $this->modx->getObject('modResource', $this->properties['staticBlocksPageId'])) {
            $staticConfig = $staticResource->getTVValue($this->properties['commonConfigName']);
        }
        if ($contactsResource = $this->modx->getObject('modResource', $this->properties['contactsPageId'])) {
            $contacts = $contactsResource->getTVValue($this->properties['contactsTvName']);
            //$contacts = $this->getContacts($contacts);
        }

        $resourceData['tvs'] = array_merge($resourceData['tvs']??[], $this->getResourceTVs($rid));
        $pathToFile = $this->properties['pdotoolsElementsPath'] . $this->properties['pathToDist'] . $rid . $this->properties['extension'];
        $config = $resource->getTVValue($this->properties['commonConfigName']) ?: $donorConfig;
        if ($config) { // если в ресурсе есть поле с конфигурацией
            $this->parseConfig($config, $rid, $resourceData, $donorConfig, $staticConfig); // парсим её и генерируем файл
        } else { // если конфигурации нет
            if (file_exists($pathToFile)) { // проверяем есть ли файл с распарсенной конфигурацией
                unlink($pathToFile); // и удаляем его
            }
        }
        return true;
    }

    /**
     *
     * @param int $rid
     * @param string $langKey
     * @return bool
     */
    public function handlePolylangConfig(int $rid, string $langKey): bool
    {
        $resourceData['id'] = $rid;
        $resourceData['tvs'] = [];
        if ($polylangContent = $this->modx->getObject('PolylangContent', ['content_id' => $rid, 'culture_key' => $langKey])) {
            $resourceData = array_merge($polylangContent->toArray(), $resourceData);
        }

        if ($resource = $this->modx->getObject('modResource', $rid)) {
            $resourceData = array_merge($resource->toArray(), $resourceData);
        }

        $donor_config = '';
        if ($donor = $this->modx->getObject('modResource', array('parent' => $this->properties['staticBlocksPageId'], 'template' => $resourceData['template']))) {
            $donor_config = $this->getPolylangConfig($donor->get('id'), $langKey);
            foreach ($resourceData as $k => $v) {
                if (!$v) {
                    unset($resourceData[$k]);
                }
            }
            $resourceData = array_merge($donor->toArray(), $resourceData);
            $resourceData['tvs'] = $this->getResourceTVs($donor->get('id'), $langKey);
        }
        if (!$donor_config) {
            if ($donor = $this->modx->getObject('modResource', array('parent' => $this->properties['staticBlocksPageId'], 'template' => $resourceData['template']))) {
                $donor_config = $donor->getTVValue($this->properties['commonConfigName']);
            }
        }
        if (!$static_config = $this->getPolylangConfig($this->properties['staticBlocksPageId'], $langKey)) {
            if ($static_resource = $this->modx->getObject('modResource', $this->properties['staticBlocksPageId'])) {
                $static_config = $static_resource->getTVValue($this->properties['commonConfigName']);
            }
        }

        $resourceData['tvs'] = array_merge($resourceData['tvs'], $this->getResourceTVs($rid, $langKey));

        $config = $this->getPolylangConfig($rid, $langKey) ?: $donor_config;
        if ($config) { // если в ресурсе есть поле с конфигурацией
            $this->parseConfig($config, $rid, $resourceData, $donor_config, $static_config, $langKey); // парсим её и генерируем файл
        } else { // если конфигурации нет
            $path_to_file = $this->properties['pdotoolsElementsPath'] . $this->properties['pathToDist'] . $rid . $langKey . $this->properties['extension'];
            if (file_exists($path_to_file)) { // проверяем есть ли файл с распарсенной конфигурацией
                unlink($path_to_file); // и удаляем его
            }
        }
        return true;
    }

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
     *
     * @param int $rid
     * @param string|null $langKey
     * @return array
     */
    public function getResourceTVs(int $rid, ?string $langKey = ''): array
    {
        $resourceTvs = [];
        $polylangTvs = [];

        $q = $this->modx->newQuery('modTemplateVar');
        $q->setClassAlias('TV');
        $q->leftJoin('modTemplateVarResource', 'TVResource', 'TV.id = TVResource.tmplvarid');
        $q->select('TV.name as name, TVResource.value as value');
        $q->where([
            'TVResource.contentid' => $rid,
            'TVResource.tmplvarid !=' => $this->properties['configTVid']
        ]);
        if ($tvs = $this->execute($q)) {
            if (!empty($tvs)) {
                foreach ($tvs as $tv) {
                    $resourceTvs[$tv['name']] = $tv['value'];
                }

                if ($this->properties['defaultLangKey'] && $langKey !== $this->properties['defaultLangKey']) {
                    $q = $this->modx->newQuery('modTemplateVar');
                    $q->setClassAlias('TV');
                    $q->leftJoin('PolylangTv', 'PolylangTv', 'TV.id = PolylangTv.tmplvarid');
                    $q->select('TV.name as name, PolylangTv.value as value');
                    $q->where([
                        'PolylangTv.content_id' => $rid,
                        'PolylangTv.tmplvarid !=' => $this->properties['configTVid'],
                        'PolylangTv.culture_key' => $langKey
                    ]);
                    if ($tvs = $this->execute($q)) {
                        foreach ($tvs as $tv) {
                            $polylangTvs[$tv['name']] = $tv['value'];
                        }
                    }
                }
            }
        }

        return array_merge($resourceTvs, $polylangTvs);
    }

    /**
     * @param string $config
     * @param int $rid
     * @param array $resourceData
     * @param string $donorConfig
     * @param string $staticConfig
     * @param string|null $langKey
     */
    private function parseConfig(string $config, int $rid, array $resourceData, string $donorConfig, string $staticConfig, ?string $langKey = '')
    {
        $config = $this->reformatConfig(json_decode($config, 1)); // декодируем конфиг в массив
        if ($staticConfig) {
            $staticConfig = $this->reformatConfig(json_decode($staticConfig, 1)); // декодируем конфиг в массив
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

        $donorConfig = $donorConfig ? $this->reformatConfig(json_decode($donorConfig, 1)) : []; // декодируем конфиг в массив
        $sections = array_merge($donorConfig, $config);
        uasort($sections, function ($a, $b) {
            if ($a['position'] == $b['position']) {
                return 0;
            }
            return ($a['position'] < $b['position']) ? -1 : 1;
        });

        $pathToFile = $this->properties['pdotoolsElementsPath'] . $this->properties['pathToDist'] . $rid . $langKey . $this->properties['extension']; // формируем имя файла
        $sectionsHtml = [];
        $i = 1;
        if (!empty($sections)) {
            foreach ($sections as $section) {
                // пропускаем базовую секцию или ту, которую нужно скрыть
                if ($section['MIGX_formname'] === $this->properties['baseSectionName'] || $section['hide_section']) {
                    continue;
                }

                if ($section['is_static'] && $staticConfig) {
                    $section = $staticConfig[$section['section_name']];
                }
                //$section['contacts'] = $this->getContacts();
                $section['rid'] = $rid; // передаем на страницу id текущего ресурса
                $section['idx'] = $i; // передаем на страницу порядковый номер секции
                $section['sbp_id'] = $this->properties['staticBlocksPageId']; // передаем на страницу id ресурса со статичными блоками
                $section['cp_id'] = $this->properties['contactsPageId']; // передаем на страницу id ресурса с контактами

                $sets = PHP_EOL."{set \$section = '!getStaticSection'| snippet:['section_name' => '{$section['MIGX_formname']}', 'lang_key' => '{$langKey}']}{if \$section}";

                foreach ($section as $key => $value) {
                    if (is_string($value) && strpos($value, '[{') !== false) {
                        $section[$key] = $this->jsonDecodeValue(json_decode($value, 1)); // преобразуем поля типа migx в массив
                    }

                    if ($section['is_static']) {
                        $keys = array_keys($this->properties['excludeFields']);
                        if (!in_array($key, $keys)) {
                            $sets .= "{set \$$key = \$section.$key}";
                        }
                    }
                }
                $sets .= '{/if}'.PHP_EOL;
                $section['resource'] = $resourceData; // передаем на страницу все поля ресурса
                /** TODO Переключение со статичной на не статичную секции через админку. Сейчас это не работает потому что в разметке есть ## */
                $chunkName = $section['MIGX_formname']; // получаем имя чанка
                $chunk = $this->properties['sectionChunkPrefix'] . strtolower($chunkName) . $this->properties['extension']; // получаем путь к чанку
                $tmp = $this->pdo->parseChunk($chunk, $section); // парсим чанк
                if ($section['is_static']) {
                    $tmp = $sets . $tmp;
                }
                $sectionsHtml[] = str_replace('##', '{', $tmp); // чтобы на фронте работал парсер pdoTools

                $i++;
            }


            if ($this->wrapperTpl) {
                $this->modx->setPlaceholder('mpc_sections', $sectionsHtml);
                $html = $this->pdo->parseChunk($this->wrapperTpl, $resourceData);
                $html = str_replace('##', '{', $html);
            } else {
                $html = implode('\n', $sectionsHtml);
            }

            // генерируем файл
            if (!file_put_contents($pathToFile, $html)) {
                $this->response->error(__METHOD__, 'Failed to save section file' . $pathToFile);
            }
        }
    }

    /**
     * @param array $config
     * @return array
     */
    private function reformatConfig(array $config): array
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
                    }else{
                        $item[$k] = $this->jsonDecodeValue($v);
                    }
                }
                $value[$key] = $item;
            }
        }

        return $value;
    }

    /**
     *
     * @param int|null $rid
     * @param string|null $tvname
     * @param string|null $langKey
     * @return array
     */
    public function getContacts(?int $rid = 0, ?string $tvname = '', ?string $langKey = ''): array
    {
        $output = [];
        $rid = $rid ?: $this->properties['contactsPageId'];

        $tvname = $tvname ?: $this->properties['configTVName'];
        if (!$tv = $this->modx->getObject('modTemplateVar', ['name' => $tvname])) {
            $this->response->error(__METHOD__, 'Не удалось получить TV со списком контактов.');
        }
        if ($resource = $this->modx->getObject('modResource', $rid)) {
            $polylangContacts = $langKey ? $this->getPolylangConfig($rid, $langKey, $tv->get('id')) : [];
            $contacts = !empty($polylangContacts) ? $polylangContacts : $resource->getTVValue($tvname);
            $contacts = json_decode($contacts, 1);
            if (is_array($contacts) && !empty($contacts)) {
                foreach ($contacts as $item) {
                    if ($contactInfo = json_decode($item['contаct_info'], true)) {
                        foreach ($contactInfo as $v) {
                            $output[$v['placement']][$item['value']] = [
                                'caption' => $v['caption'],
                                'fvalue' => $item['fvalue'],
                                'attributes' => $v['attributes'],
                                'placement' => $v['placement'],
                                'value' => $item['value'],
                                'formattedValue' => $item['formattedValue'],
                                'type' => $item['type'],
                            ];
                        }
                    } else {
                        $output[$item['value']] = [
                            'value' => $item['value'],
                            'formattedValue' => $item['formattedValue'],
                            'type' => $item['type'],
                        ];
                    }
                }
            }
        }

        return $output;
    }

    public function getPolylangConfig(int $rid, string $langKey, ?int $tvId = 0)
    {
        if ($config_polylang = $this->modx->getObject('PolylangTv', [
            'tmplvarid' => $tvId ?: $this->properties['configTVid'],
            'culture_key' => $langKey,
            'content_id' => $rid
        ])) {
            return $config_polylang->get('value');
        }
        return '';
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
