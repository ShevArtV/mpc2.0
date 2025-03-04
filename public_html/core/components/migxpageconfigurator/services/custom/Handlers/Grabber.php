<?php

/**
 * Сервис для извлечения данных из шаблона и записи их в соответствующие конфигурации.
 */

namespace MpcServices\Handlers;

use MpcServices\Helpers\Logging;
use MpcServices\Helpers\Response;
use MpcServices\Processors\Template;

/**
 * @author Arthur Shevchenko (https://t.me/ShevArtV)
 */
class Grabber extends Base
{
    /**
     * @var array
     */
    public array $resourceValues = [];
    /**
     * @var bool
     */
    public bool $updContent = false;
    /**
     * @var string
     */
    private string $fileName = '';

    /**
     * @var bool
     */
    public bool $fromPlugin = false;

    /**
     * @var bool
     */
    private bool $sectionIsStatic = false;

    /**
     * @var string
     */
    private string $imagesPath = '';

    /**
     * @var array
     */
    public array $lexicons = [];

    /**
     * @var string
     */
    public string $sectionLexiconPrefix = '';

    /**
     * @return void
     */
    protected function initialize(): void
    {
        parent::initialize();

        $excludeLexiconFields = $this->modx->getOption('mpc_exclude_lexicons_fields', '', '');
        $excludeLexiconFields = array_merge([
            'MIGX_id',
            'MIGX_formname',
            'id',
            'section_name',
            'file_name',
            'is_static',
            'limit',
            'target',
            'resources'
        ], explode(',', $excludeLexiconFields));

        $properties = array_merge($this->properties, [
            'startPageId' => $this->modx->getOption('site_start', null, 1),
            'phoneRegExp' => $this->modx->getOption('mpc_phone_regexp', '', '/(\d)(\d{3})(\d{3})(\d{2})(\d{2})$/'),
            'phoneFormat' => $this->modx->getOption('mpc_phone_format', '', '8 (\2) \3-\4-\5'),
            'imagesPath' => $this->modx->getOption('mpc_images_path', '', ''),
            'excludeLexiconFields' => $excludeLexiconFields,
        ]);

        if ($this->properties['useLexicons']) {
            /** TODO Добавить возможность создавать файлы словарей по псевдониму ресурса */
            $properties['basePathToLexiconFile'] = $this->properties['corePath'] . 'components/migxpageconfigurator/lexicon/' . $properties['langKey'] . '/';
            $this->lexicons[$properties['staticBlocksPageId']] = $this->getLexicons($properties['staticBlocksPageId'], $properties['basePathToLexiconFile']);
        }
        $this->properties = array_merge($this->properties, $properties);
        $this->modx->addPackage('migx', $this->properties['corePath'] . 'components/migx/model/');
        if ($this->debug) {
            $this->logging->write(__METHOD__, "Properties:", $this->properties);
        }
    }

    /**
     * @param string $fileName
     * @return array
     */
    public function handle(string $fileName): array
    {
        $this->fileName = $fileName;
        if ($this->debug) {
            $this->logging->write(__METHOD__, "Handle file $this->fileName");
        }

        if (!$html = $this->getFileContent($this->fileName)) {
            return $this->response->error(__METHOD__, "File $this->fileName is empty");
        }

        $this->handleInformation($html);
        $this->handleContacts($html);
        if (strpos($this->fileName, 'wrapper') === false) {
            $this->handleTemplate($html);
            $this->handleSections($html);
        }

        return $this->response->success(__METHOD__, "Processing of file $this->fileName is completed", ['resource' => $this->properties['resource']]);
    }

    /**
     * @param string $html
     * @return void
     */
    private function handleInformation(string $html): void
    {
        if (!$this->updContent) {
            return;
        }

        if (!$items = $this->getItems($html, '[data-mpc-info]')) {
            return;
        }

        foreach ($items as $item) {
            $infoKey = $item->getAttribute('data-mpc-info');
            $ctx = $item->getAttribute('data-mpc-ctx') ?: $this->modx->context->get('key') ?: 'web';
            if (!$item->hasAttribute('data-mpc-ctx')) {
                if (!$setting = $this->modx->getObject('modSystemSetting', ['key' => $infoKey])) {
                    if (!$setting = $this->getClientConfigSetting($infoKey)) {
                        continue;
                    }
                }
            } else {
                if (!$setting = $this->modx->getObject('modContextSetting', ['key' => $infoKey, 'context_key' => $ctx])) {
                    if (!$setting = $this->modx->getObject('modSystemSetting', ['key' => $infoKey])) {
                        if (!$setting = $this->getClientConfigSetting($infoKey, $ctx)) {
                            continue;
                        }
                    }
                }
            }
            $data = [
                'context' => $ctx,
                'context_key' => $ctx,
                'key' => $infoKey,
                'value' => ''
            ];

            switch ($item->nodeName) {
                case 'link':
                    $data['value'] = $item->getAttribute('href');
                    break;
                case 'img':
                    $data['value'] = $item->getAttribute('src');
                    break;
                default:
                    //$value = $item->hasAttribute('data-mpc-unwrap') ? $item->nodeValue : $this->parser->getHTMLString($item);
                    $data['value'] = str_replace('{', '{ ', $item->nodeValue);
                    break;
            }

            $setting->fromArray($data, '', true);
            $setting->save();
            if ($this->debug) {
                $this->logging->write(__METHOD__, "Info was updated", $data);
            }
        }
    }


    /**
     * @param string $key
     * @param string|null $ctx
     * @return object|null
     */
    private function getClientConfigSetting(string $key, ?string $ctx = null): ?object
    {
        if ($ctx) {
            $q = $this->modx->newQuery('cgContextValue');
            $q->leftJoin('cgSetting', 'Setting');
            $q->where([
                'Setting.key' => $key,
                'cgContextValue.context' => $ctx
            ]);
            return $this->modx->getObject('cgContextValue', $q);
        }
        $q = $this->modx->newQuery('cgSetting');
        $q->where([
            '`key`' => $key,
        ]);
        return $this->modx->getObject('cgSetting', $q);
    }

    /**
     * @param string $html
     * @return void
     */
    private function handleContacts(string $html): void
    {
        if (!$this->updContent) {
            return;
        }

        if (!$items = $this->getItems($html, '[data-mpc-contact]')) {
            return;
        }

        $contacts = [];
        foreach ($items as $item) {
            if (!$fields = $this->getItems($this->parser->getHTMLString($item), '[data-mpc-cfield]')) {
                continue;
            }

            $contactAttrValue = explode('|', trim($item->getAttribute('data-mpc-contact')));
            $tmp = [
                'type' => $contactAttrValue[0],
                'placement' => $contactAttrValue[1] ?? 'default',
            ];
            /** TODO Создавать лексиконы для контактов */
            foreach ($fields as $field) {
                $key = $field->getAttribute('data-mpc-cfield');
                if ($key === 'fvalue') {
                    continue;
                }

                if ($key === 'value') {
                    if ($href = $field->getAttribute('href')) {
                        $tmp[$key] = $href;
                    } else {
                        $tmp[$key] = trim($field->textContent);
                    }
                } else {
                    $tmp[$key] = $field->getAttribute('src') ?: $this->parser->getHTMLString($field);
                }
            }
            if (!$tmp['value']) {
                continue;
            }

            if (!$tmp['key']) {
                $tmp['key'] = $this->getContactKey($tmp['value']);
            }
            if ($tmp['type'] === 'phone') {
                $tmp['value'] = preg_replace('/[^0-9]/', '', trim($tmp['value']));
                if (!$tmp['fvalue']) {
                    $tmp['fvalue'] = preg_replace($this->properties['phoneRegExp'], $this->properties['phoneFormat'], trim($tmp['value']));
                }
            } else {
                $tmp['fvalue'] = $tmp['value'];
            }

            $this->modx->invokeEvent('mpcOnHandleContact', [
                'contact' => [$tmp],
                'Grabber' => $this
            ]);

            $tmp = isset($this->modx->event->returnedValues) && !empty($this->modx->event->returnedValues['contact']) ? $this->modx->event->returnedValues['contact'] : $tmp;

            $contacts[$tmp['value']]['type'] = $tmp['type'];
            $contacts[$tmp['value']]['ckey'] = $tmp['key'];
            $contacts[$tmp['value']]['value'] = $tmp['value'];
            $contacts[$tmp['value']]['fvalue'] = $tmp['fvalue'];
            $contacts[$tmp['value']]['contact_info'][$tmp['placement']] = [
                'caption' => $tmp['caption'],
                'attributes' => $tmp['attributes'],
                'placement' => $tmp['placement'],
            ];
        }

        if (empty($contacts)) {
            if ($this->debug) {
                $this->logging->write(__METHOD__, "Contacts was not found");
            }
            return;
        }

        if (!$resource = $this->getResource((int)$this->properties['contactsPageId'])) {
            return;
        }

        if ($tvValue = json_decode($resource->getTVValue($this->properties['contactsTvName']), true)) {
            $oldContacts = $this->reformatMigx($tvValue, 'value');
        } else {
            $oldContacts = [];
        }

        foreach ($contacts as $value => $item) {
            if ($oldContacts[$value]) {
                $contactInfo = json_decode($oldContacts[$value]['contact_info'], true) ?: [];
                $contactInfo = $this->reformatMigx($contactInfo, 'placement');
                $contactInfo = array_merge($contactInfo, $item['contact_info']);
                $oldContacts[$value]['contact_info'] = $contactInfo;
            } else {
                $oldContacts[$value] = $item;
            }
        }

        $newContacts = [];
        $i = 0;
        foreach ($oldContacts as $item) {
            $item['MIGX_id'] = ++$i;
            if (!empty($item['contact_info'])) {
                $item['contact_info'] = !is_array($item['contact_info']) ? json_decode($item['contact_info'], true) : $item['contact_info'];
                $j = 0;
                $contactInfo = [];
                foreach ($item['contact_info'] as $info) {
                    $info['MIGX_id'] = ++$j;
                    $contactInfo[] = $info;
                }
                $item['contact_info'] = json_encode($contactInfo, JSON_UNESCAPED_UNICODE);
            }

            $newContacts[] = $item;
        }
        if ($this->debug) {
            $this->logging->write(__METHOD__, "Contacts was updated", $newContacts);
        }
        $resource->setTVValue($this->properties['contactsTvName'], json_encode($newContacts, JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param string $html
     * @return void
     */
    private function handleTemplate(string $html): void
    {
        preg_match('/<!--##(.*?)##-->/', $html, $tplDataJson);
        if (!$tplDataJson[1]) {
            $this->response->error(__METHOD__, "No template data", $tplDataJson);
            return;
        }
        $tplData = json_decode($tplDataJson[1], true);
        if (!is_array($tplData) || empty($tplData)) {
            $this->response->error(__METHOD__, "Template data is invalid", $tplData);
            return;
        }

        if (!$tplData['templatename']) {
            $this->response->error(__METHOD__, "Template name is empty");
            return;
        }

        $tplData['content'] = $tplData['include'] ? "{include '{$tplData['include']}'}" : "{include 'file:pages/index.tpl'}";
        $wrapperName = $tplData['wrapper'] ?? 'wrapper';
        $tplData['description'] = "@FILE {$this->properties['pathToSections']}$wrapperName{$this->properties['extension']}";

        if (!$tplData['icon']) {
            $tplData['icon'] = "icon-gears";
        }

        $Template = new Template($this->modx);
        if (!$template = $Template->update($tplData)) {
            $this->response->error(__METHOD__, "Template was not updated or create.");
            return;
        }

        if (!$resource = $this->modx->getObject('modResource', ['pagetitle' => 'Шаблон ' . $tplData['templatename'], 'parent' => $this->properties['staticBlocksPageId']])) {
            $resource = $this->modx->newObject('modResource');
        }
        $resource->fromArray([
            'pagetitle' => 'Шаблон ' . $tplData['templatename'],
            'parent' => $this->properties['staticBlocksPageId'],
            'template' => $template->get('id'),
            'hidemenu' => 1
        ]);
        if ($resource->save()) {
            $this->properties['resource'] = $resource;
        }

        $Template->addTemplateVariables($template->get('id'), $tplData['template_var_ids']);

        $this->response->success(__METHOD__, "Template was updated or created.");
    }

    /**
     * @param string $html
     * @return void
     */
    private function handleSections(string $html): void
    {
        if (!$sections = $this->getItems($html, '[data-mpc-section]')) {
            return;
        }

        if (!$staticBlocksResource = $this->getResource((int)$this->properties['staticBlocksPageId'])) {
            return;
        }

        // получаем список всех секций из ресурса Типы страниц
        $sbpResourceConfig = $staticBlocksResource->getTVValue($this->properties['commonConfigTvName']);
        $this->properties['sbpSectionValues'] = json_decode($sbpResourceConfig, true) ?: [];

        // получаем базовую конфигурацию mpc_base
        $result = $this->getObject('migxConfig', ['name' => $this->properties['baseSectionName']], true);
        if (!$result['success']) {
            return;
        }

        $defaultFormTabs = json_decode($result['data']['object']['formtabs'], true); // получаем вкладки формы базовой конфигурации
        $result = $this->getObject('migxConfig', ['name' => $this->properties['commonConfigTvName']]); // получаем mpc_config
        if (!$result['success']) {
            return;
        }
        $commonConfig = $result['data']['object'];
        $commonConfigData = $commonConfig->toArray(); // преобразуем mpc_config в массив
        $this->properties['multipleFormtabs'] = explode('||', $commonConfigData['extended']['multiple_formtabs']); // получаем список конфигураций для выбора

        $i = 0;
        $sectionValues = [];
        foreach ($sections as $section) {
            $i++;
            $sectionName = trim($section->getAttribute('data-mpc-section'));
            $fileName = $sectionName . $this->properties['extension'];
            $fileNameVis = $this->properties['pdotoolsElementsPath'] . $this->properties['pathToSections'] . $fileName;
            $properties = [
                'defaultFormTabs' => $defaultFormTabs,
                'sectionName' => $sectionName,
                'isCopy' => $section->hasAttribute('data-mpc-copy'),
                'fileNameVis' => $fileNameVis,
                'fileName' => $fileName
            ];

            if (!$properties['isCopy'] && !empty($defaultFormTabs)) {
                // обновляем или создаём конфигурацию для секции.
                $result = $this->createSectionConfig($section, $properties);
                if ($result['success']) {
                    $this->properties['multipleFormtabs'][] = $result['data']['id'];
                }
            }

            $values = $this->grabSection($section, $properties, $i);
            $sectionValues[$i] = $values;
        }

        // обновляем или заполняем контент типовой страницы.
        $this->properties['resource']->set('introtext', $this->fileName);
        $this->properties['resource']->save();
        if ($this->updContent && !empty($sectionValues)) {
            if ($this->debug) {
                $this->logging->write(__METHOD__, 'Section values', $sectionValues);
            }
            $this->properties['resource']->setTVValue($this->properties['commonConfigTvName'], json_encode($sectionValues, JSON_UNESCAPED_UNICODE));
        }
        if (!empty($this->properties['sbpSectionValues'])) {
            $staticBlocksResource->setTVValue($this->properties['commonConfigTvName'], json_encode($this->properties['sbpSectionValues'], JSON_UNESCAPED_UNICODE));
        }

        $commonConfigData['extended']['multiple_formtabs'] = implode('||', array_unique($this->properties['multipleFormtabs']));
        $commonConfig->fromArray($commonConfigData);
        if (!$commonConfig->save()) {
            $this->response->error(__METHOD__, 'Failed to save configuration.');
            return;
        }

        $this->createLexicons($this->lexicons);

        $this->response->success(__METHOD__, 'Section processing is complete.');
    }

    public function createLexicons(array $allLexicons)
    {
        $basePathToLexiconFile = $this->properties['basePathToLexiconFile'];
        foreach ($allLexicons as $rid => $lexicons) {
            $pathToLexiconFile = $basePathToLexiconFile . $rid . '.inc.php';

            if (!empty($lexicons)) {
                $content = '<?php' . PHP_EOL;
                foreach ($lexicons as $k => $v) {
                    $content .= '$_lang[\'' . $k . '\'] = \'' . $v . '\';' . PHP_EOL;
                }
                file_put_contents($pathToLexiconFile, $content);
            } else {
                unlink($pathToLexiconFile);
            }
        }
    }

    /**
     * @param array $data
     * @param string $key
     * @return array
     */
    private function reformatMigx(array $data, string $key): array
    {
        $result = [];
        foreach ($data as $item) {
            $result[$item[$key]] = $item;
        }
        return $result;
    }

    /**
     * @param int $id
     * @return object|null
     */
    private function getResource(int $id): ?object
    {
        $resource = $this->modx->getObject('modResource', $id);
        if (!$resource) {
            if ($this->debug) {
                $this->logging->write(__METHOD__, "Resource with ID = $id not found");
            }
            return null;
        }
        return $resource;
    }


    /**
     * @param \DOMElement $section
     * @param array $properties
     * @return array
     */
    private function createSectionConfig(\DOMElement $section, array $properties): array
    {
        $properties['defaultFormTabs'][1]['fields'] = $this->getSectionFields($section, $properties['defaultFormTabs'][1]['fields']);
        $properties['defaultFormTabs'][0]['fields'][2]['default'] = $properties['fileNameVis']; // устанавливаем имя файла секции
        $properties['defaultFormTabs'][0]['fields'][2]['useDefaultIfEmpty'] = 1;
        $properties['defaultFormTabs'][0]['fields'][1]['default'] = $section->getAttribute('data-mpc-name'); // устанавливаем имя секции
        $properties['defaultFormTabs'][0]['fields'][1]['useDefaultIfEmpty'] = 1;
        $properties['defaultFormTabs'][0]['fields'][0]['default'] = $properties['sectionName']; // устанавливаем id секции
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
     * @param \DOMElement $section
     * @param array $defaultFields
     * @return array
     */
    private function getSectionFields(\DOMElement $section, array $defaultFields): array
    {
        $result = array();
        if (!$entries = $this->getItems($this->parser->getHTMLString($section), '[data-mpc-field]')) {
            return [];
        }

        foreach ($entries as $entry) {
            $fieldName = $entry->getAttribute('data-mpc-field');
            $width = $entry->getAttribute('width');
            $height = $entry->getAttribute('height');
            $result[] = $fieldName;
            if ($fieldName === 'img') {
                $result[] = $width ? 'img_w' : '';
                $result[] = $height ? 'img_h' : '';
            }
            if ($fieldName === 'img_mob') {
                $result[] = $width ? 'img_mob_w' : '';
                $result[] = $height ? 'img_mob_h' : '';
            }
        }

        return $this->deleteUndueFields($defaultFields, $result);
    }

    /**
     *
     * @param array $defaultFields
     * @param array $needFields
     * @return array
     */
    private function deleteUndueFields(array $defaultFields, array $needFields): array
    {
        $fields = array();

        foreach ($defaultFields as $v) {
            if (in_array($v['field'], $needFields)) {
                $fields[] = $v;
            }
        }

        return $fields;
    }

    /**
     * @param \DOMElement $section
     * @param array $properties
     * @param int|null $i
     * @return array
     */
    private function grabSection(\DOMElement $section, array $properties, ?int $i = 1): array
    {
        $sectionName = trim($section->getAttribute('data-mpc-name'));
        $this->sectionLexiconPrefix = trim($section->getAttribute('data-mpc-lexicon')) ?? $properties['sectionName'];
        $this->sectionIsStatic = empty($this->staticSectionNames) ? $section->hasAttribute('data-mpc-static') : in_array($sectionName, $this->staticSectionNames);
        $sectionId = $properties['sectionName'] . '_' . str_replace(['.', ',', ' '], '', microtime(true));
        $this->imagesPath = $this->properties['imagesPath'] . $properties['sectionName'] . '/';

        // заполняем содержимое полей
        $fieldsValues = $this->getFieldsValues($this->parser->getHTMLString($section));
        $fieldsValues['is_static'] = $this->sectionIsStatic;
        $fieldsValues = array_merge([
            'MIGX_id' => $i,
            'MIGX_formname' => $properties['sectionName'],
            'id' => $sectionId,
            'section_name' => $sectionName,
            'lexicon_prefix' => $this->sectionLexiconPrefix,
            'file_name' => $properties['fileNameVis'],
        ], $fieldsValues);

        $this->modx->invokeEvent('mpcOnGetSectionFieldsValues', [
            'sectionKey' => $properties['sectionName'],
            'fieldsValues' => $fieldsValues,
            'section' => $section,
            'Grabber' => $this
        ]);

        $fieldsValues = isset($this->modx->event->returnedValues) && !empty($this->modx->event->returnedValues['fieldsValues'])
            ? $this->modx->event->returnedValues['fieldsValues'] : $fieldsValues;

        if (!$properties['isCopy'] && $this->sectionIsStatic) {
            $this->updateStaticSectionValues($fieldsValues, $properties['sectionName']);
        }

        return $fieldsValues;
    }

    /**
     * @param string $url
     * @param string $path
     * @return string
     */
    public function download(string $url, string $path)
    {
        if (!$path) {
            return '';
        }
        $fullPath = dirname(__FILE__, 7) . $path;
        if (file_exists($fullPath)) {
            return $path;
        }
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_HEADER, false);
        $content = curl_exec($ch);
        curl_close($ch);

        return ($content && file_put_contents($fullPath, $content)) ? $path : '';
    }

    /**
     * @param array $sectionFieldsValues
     * @param string $sectionName
     * @return void
     */
    private function updateStaticSectionValues(array $sectionFieldsValues, string $sectionName): void
    {
        $upd = false;
        $i = 0;
        if (!empty($this->properties['sbpSectionValues'])) {
            foreach ($this->properties['sbpSectionValues'] as $k => $sectionValue) {
                if ($sectionValue['MIGX_formname'] === $sectionName) {
                    if (!$this->fromPlugin) {
                        $this->properties['sbpSectionValues'][$k] = $sectionFieldsValues;
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
     * @param string $html
     * @return array
     */
    private function getFieldsValues(string $html): array
    {
        $fields = $this->parseHTML($html);
        foreach ($fields as $k => $v) {
            if (is_array($v)) {
                $fields[$k] = json_encode($v);
            }
        }
        return $fields;
    }

    /**
     * @param string $html
     * @param array|null $options
     * @return array
     */
    private function parseHTML(string $html, ?array $options = []): array
    {
        $level = $options['level'] ?? 0;
        $fieldAttrName = $options['fieldAttrName'] ?? 'data-mpc-field';
        $itemAttrName = $options['itemAttrName'] ?? 'data-mpc-item';
        $idx = $options['idx'] ?? 0;
        $parentFieldName = $options['parentFieldName'] ?? '';

        if (!$entries = $this->getItems($html, '[' . $fieldAttrName . ']')) {
            return [];
        }

        $fields = [];
        $level++;
        $nextFieldAttr = 'data-mpc-field-' . $level;
        $nextItemAttr = 'data-mpc-item-' . $level;
        $mediaLists = [];
        foreach ($entries as $key => $row) {
            $fieldName = $row->getAttribute($fieldAttrName);
            $lexiconOptions = ['fieldName' => $options['fieldName'] ?? $fieldName, 'parentFieldName' => $parentFieldName, 'idx' => $idx];
            if ($fieldName === 'img') {
                $fields[$fieldName] = $this->getImageValue($row, $lexiconOptions);
            } elseif ($fieldName === 'picture') {
                $fields[$fieldName] = $this->getPictureValue($row, $lexiconOptions);
            } elseif ($fieldName === 'bg_img') {
                $fields[$fieldName] = $this->getBackgroundValue($row, $lexiconOptions);
            } elseif (in_array($fieldName, ['video', 'audio'])) {
                $fields[$fieldName] = $this->getMediaValue($row, $lexiconOptions);
            } elseif (in_array($fieldName, ['list_images', 'list_pictures', 'list_audios', 'list_videos'])) {
                $mediaLists[$fieldName][] = $row;
            } elseif ($items = $this->getItems($this->parser->getHTMLString($row), '[' . $itemAttrName . ']')) {
                foreach ($items as $k => $item) {
                    $parentFieldName = $k ? "{$fieldName}_{$k}" : $fieldName;
                    $parentFieldName = $lexiconOptions['parentFieldName'] ? "{$lexiconOptions['parentFieldName']}_$parentFieldName" : $parentFieldName;
                    $fields[$fieldName][$k]['MIGX_id'] = $k + 1;
                    $value = $this->parseHTML($this->parser->getHTMLString($item), [
                        'fieldAttrName' => $nextFieldAttr,
                        'itemAttrName' => $nextItemAttr,
                        'level' => $level,
                        'idx' => $k,
                        'parentFieldName' => $parentFieldName,
                        'fieldName' => $options['fieldName']
                    ]);

                    $fields[$fieldName][$k] = array_merge(
                        $fields[$fieldName][$k],
                        $value
                    );
                }
            } else {
                $fields[$fieldName] = $this->getValue($row, $lexiconOptions);
            }
        }

        foreach ($mediaLists as $fieldName => $items) {
            switch ($fieldName) {
                case 'list_images':
                    $valueKey = 'img';
                    $previewKey = 'preview';
                    $pathKey = 'src';
                    $method = 'getImageValue';
                    break;
                case 'list_pictures':
                    $valueKey = 'picture';
                    $previewKey = 'preview';
                    $pathKey = 'preview';
                    $method = 'getPictureValue';
                    break;
                case 'list_audios':
                    $valueKey = 'audio';
                    $previewKey = 'path';
                    $pathKey = 'src';
                    $method = 'getMediaValue';
                    break;
                case 'list_videos':
                    $valueKey = 'video';
                    $previewKey = 'path';
                    $pathKey = 'src';
                    $method = 'getMediaValue';
                    break;
            }
            foreach ($items as $k => $row) {
                if (!method_exists($this, $method)) {
                    continue;
                }
                $lexiconOptions['idx'] = $k;
                $lexiconOptions['fieldName'] = $fieldName;

                $value = $this->$method($row, $lexiconOptions);
                $value = !is_array($value) ? json_decode($value, true) : $value;
                $preview = $value[0][$pathKey];
                $value = json_encode($value, JSON_UNESCAPED_UNICODE);
                $fields[$fieldName][$k] = [
                    'MIGX_id' => $k + 1,
                    $valueKey => $value,
                    $previewKey => $preview
                ];
            }
        }

        return $fields;
    }

    /**
     * @param \DOMElement $row
     * @param array|null $options
     * @return string
     */
    private function getImageValue(\DOMElement $row, ?array $options = []): string
    {
        $attrs = ['src', 'alt', 'width', 'height'];
        $value[0]['MIGX_id'] = 1;
        foreach ($attrs as $attr) {
            $attrValue = $row->getAttribute($attr);
            if ($attr === 'src' && strpos($attrValue, 'http') !== false) {
                $attrValue = $this->downloadImage($attrValue);
            }
            if ($attr === 'src') {
                $attrValue = in_array('image', $this->properties['translatableContentTypes']) ? $this->setLexicons($attrValue, $options) : $attrValue;
            }

            $value[0][$attr] = $attrValue;
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param \DOMElement $row
     * @param int|null $idx
     * @param bool|null $isPicture
     * @return array
     */
    private function getSourceValue(\DOMElement $row, ?int $idx = 1, ?bool $isPicture = true): array
    {
        $attrs = ['type', 'media'];
        if (!$isPicture) {
            $attrs[] = 'src';
        } else {
            $attrs = array_merge($attrs, ['srcset', 'sizes', 'height', 'width']);
        }

        $value['MIGX_id'] = $idx;
        foreach ($attrs as $attr) {
            $attrValue = $row->getAttribute($attr);
            if ($attr === 'srcset' && strpos($row->getAttribute($attr), 'http') !== false) {
                $attrValue = $this->downloadImage($attrValue);
            }
            $value[$attr] = $attrValue;
        }
        return $value;
    }

    /**
     * @param string $attrValue
     * @return string
     */
    private function downloadImage(string $attrValue): string
    {
        if (empty($this->properties['imagesPath'])) {
            return $attrValue;
        }

        $baseName = basename($attrValue);
        $this->modx->invokeEvent('mpcOnBeforeDownloadImage', [
            'baseName' => $baseName,
            'imagesPath' => $this->imagesPath,
            'Grabber' => $this
        ]);

        $baseName = isset($this->modx->event->returnedValues) && !empty($this->modx->event->returnedValues['baseName'])
            ? $this->modx->event->returnedValues['baseName'] : $baseName;
        $imagesPath = isset($this->modx->event->returnedValues) && !empty($this->modx->event->returnedValues['imagesPath'])
            ? $this->modx->event->returnedValues['imagesPath'] : $this->imagesPath;

        $fullPathToDir = dirname(__FILE__, 7) . $imagesPath;
        if (!file_exists($fullPathToDir)) {
            mkdir($fullPathToDir, 0777, true);
        }

        if ($path = $this->download($attrValue, $imagesPath . $baseName)) {
            $attrValue = $path;
        }
        return $attrValue;
    }

    /**
     * @param \DOMElement $element
     * @param array|null $options
     * @return string
     */
    private function getPictureValue(\DOMElement $element, ?array $options = []): string
    {
        $picture[0]['MIGX_id'] = 1;
        $picture[0]['preview'] = '';
        $picture[0]['img'] = [];
        $picture[0]['sources'] = [];
        if ($img = $element->getElementsByTagName('img')) {
            $picture[0]['img'] = $this->getImageValue($img[0], $options);
            $picture[0]['preview'] = $img[0]->getAttribute('src');
        }
        if ($sources = $element->getElementsByTagName('source')) {
            $options['parentFieldName'] = $options['idx'] ? "{$options['fieldName']}_{$options['idx']}" : $options['fieldName'];
            $options['fieldName'] = 'source';
            foreach ($sources as $k => $source) {
                $options['idx'] = $k;
                $picture[0]['sources'][$k] = $this->getSourceValue($source, $k + 1);
                $picture[0]['sources'][$k]['srcset'] = in_array('image', $this->properties['translatableContentTypes'])
                    ? $this->setLexicons($picture[0]['sources'][$k]['srcset'], $options) : $picture[0]['sources'][$k]['srcset'];
            }
        }

        return json_encode($picture);
    }

    /**
     * @param \DOMElement $element
     * @param array|null $options
     * @return array
     */
    private function getMediaValue(\DOMElement $element, ?array $options = []): array
    {
        $useLexicons = (in_array('video', $this->properties['translatableContentTypes']) || in_array('audio', $this->properties['translatableContentTypes']));

        $media[0]['MIGX_id'] = 1;
        $attrs = [
            'src' => 'string',
            'autoplay' => 'boolean',
            'controls' => 'boolean',
            'loop' => 'boolean',
            'muted' => 'boolean',
            'preload' => 'boolean',
        ];
        if ($element->nodeName === 'video') {
            $attrs = array_merge($attrs, [
                'src' => 'string',
                'width' => 'number',
                'height' => 'number',
                'poster' => 'string'
            ]);
        }

        $media[0]['sources'] = [];
        foreach ($attrs as $attr => $type) {
            if ($type === 'boolean') {
                $media[0][$attr] = $element->hasAttribute($attr) ? 1 : 0;
            } else {
                $media[0][$attr] = $element->getAttribute($attr);
            }
            if ($attr === 'poster') {
                $lexiconOptions = [
                    'fieldName' => 'poster',
                    'parentFieldName' => $options['idx'] ? "{$options['fieldName']}_{$options['idx']}" : $options['fieldName'],
                    'idx' => 0,
                ];
                $media[0][$attr] = in_array('image', $this->properties['translatableContentTypes']) ? $this->setLexicons($media[0][$attr], $lexiconOptions) : $media[0][$attr];
            }
            if ($attr === 'src') {
                $media[0][$attr] = $useLexicons ? $this->setLexicons($media[0][$attr], $options) : $media[0][$attr];
            }
        }
        if ($sources = $element->getElementsByTagName('source')) {
            $options['parentFieldName'] = $options['idx'] ? "{$options['fieldName']}_{$options['idx']}" : $options['fieldName'];
            $options['fieldName'] = 'source';
            foreach ($sources as $k => $source) {
                $options['idx'] = $k;
                $media[0]['sources'][$k] = $this->getSourceValue($source, $k + 1, false);
                $media[0]['sources'][$k]['src'] = $useLexicons ? $this->setLexicons($media[0]['sources'][$k]['src'], $options) : $media[0]['sources'][$k]['src'];
            }
        }
        if (!$media[0]['src']) {
            $media[0]['src'] = $media[0]['sources'][0]['src'];
        }
        return $media;
    }

    /**
     * @param \DOMElement $element
     * @param array|null $options
     * @return string
     */
    private function getBackgroundValue(\DOMElement $element, ?array $options = []): string
    {
        if ($style = $element->getAttribute('style')) {
            if (strpos($style, 'background') !== false) {
                preg_match('/(background|background\-image):.*?url\(\'(.*?)\'\)/', $style, $matches);
                $value = $matches[2];
                if (strpos($value, 'http') !== false) {
                    $value = $this->downloadImage($value);
                }
                return in_array('image', $this->properties['translatableContentTypes'])
                    ? $this->setLexicons($value, $options) : $value;
            }
        }
        return '';
    }

    /**
     * @param \DOMElement $element
     * @param array|null $options
     * @return string
     */
    private function getValue(\DOMElement $element, ?array $options = []): string
    {
        $result = '';
        if ($href = $element->getAttribute('href')) {
            $result = $href;
        } elseif ($element->childNodes->count()) {
            foreach ($element->childNodes as $childNode) {
                $result .= $this->parser->getHTMLString($childNode);
            }
        } else {
            $result = $element->nodeValue;
        }
        return in_array('text', $this->properties['translatableContentTypes']) ? $this->setLexicons($result, $options) : $result;
    }


    /**
     * @param string $value
     * @param string $fieldName
     * @return string
     */
    private function setLexicons(string $value, ?array $options = []): string
    {
        if (!$this->properties['useLexicons']) {
            return $value;
        }
        $fieldName = $options['fieldName'] ?? '';
        $parentFieldName = $options['parentFieldName'] ?? '';

        if (in_array($fieldName, $this->properties['excludeLexiconFields'])) {
            return $value;
        }

        if ($parentFieldName && in_array($parentFieldName, $this->properties['excludeLexiconFields'])) {
            return $value;
        }

        $options['prefix'] = $this->sectionLexiconPrefix;
        $lexiconKey = $this->getLexiconKey($options);

        $this->modx->invokeEvent('mpcOnGetLexiconKey', [
            'sectionLexiconPrefix' => $this->sectionLexiconPrefix,
            'lexiconKey' => $lexiconKey,
            'fieldName' => $fieldName,
            'Grabber' => $this
        ]);

        $lexiconKey = isset($this->modx->event->returnedValues) && !empty($this->modx->event->returnedValues['lexiconKey'])
            ? $this->modx->event->returnedValues['lexiconKey'] : $lexiconKey;

        if ($this->sectionIsStatic) {
            $rid = $this->properties['staticBlocksPageId'];
        } else {
            $rid = $this->properties['resource']->get('id');
        }

        $this->lexicons[$rid][$lexiconKey] = $value;

        return "{'$lexiconKey' | lexicon}";
    }


    /**
     * @param string $className
     * @param array $conditions
     * @param bool|null $asArray
     * @return array
     */
    protected function getObject(string $className, array $conditions, ?bool $asArray = false): array
    {
        if ($object = $this->modx->getObject($className, $conditions)) {
            if ($asArray) {
                return $this->response->success(__METHOD__, 'Object found', ['object' => $object->toArray()]);
            } else {
                return $this->response->success(__METHOD__, 'Object found', ['object' => $object]);
            }
        }
        return $this->response->error(__METHOD__, 'Object not found', ['conditions' => $conditions, 'className' => $className]);
    }

    /**
     * @param $rid
     * @param $basePath
     * @return array|mixed
     */
    public function getLexicons($rid, $basePath): array
    {
        $pathToLexiconFile = $basePath . $rid . '.inc.php';
        $lexicons[$rid] = [];
        if (file_exists($pathToLexiconFile)) {
            include $pathToLexiconFile;
            return $_lang ?? [];
        }
        return [];
    }
}
