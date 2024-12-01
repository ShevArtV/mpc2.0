<?php

/**
 * Сервис для работы извлечения данных из шаблона и записи их в соответствующие конфигурации.
 */

namespace CustomServices\Handlers;

use CustomServices\Helpers\Logging;
use CustomServices\Helpers\Response;
use CustomServices\Processors\Template;

/**
 * @author Arthur Shevchenko (https://t.me/ShevArtV)
 */
class Grabber
{
    /**
     * @var Logging
     */
    private Logging $logging;
    /**
     * @var \modX
     */
    public \modX $modx;
    /**
     * @var array|mixed
     */
    public array $properties = [];
    /**
     * @var array
     */
    public array $resourceValues = [];
    /**
     * @var Parser
     */
    private Parser $parser;
    /**
     * @var bool
     */
    public bool $updContent = false;
    /**
     * @var bool
     */
    public bool $debug = true;
    /**
     * @var Response
     */
    private Response $response;

    /**
     * @param \modX $modx
     * @param array|null $properties
     */
    public function __construct(\modX $modx, ?array $properties = [])
    {
        $this->modx = $modx;
        $this->properties = $properties;
        $this->initialize();
    }

    /**
     * @return void
     */
    private function initialize(): void
    {
        $this->properties = array_merge($this->properties, [
            'extension' => $this->modx->getOption('mpc_tpl_file_extension', null, '.tpl'),
            'pathToSections' => $this->modx->getOption('mpc_path_to_sections', null, 'sections/'),
            'commonConfigName' => $this->modx->getOption('mpc_common_config_name', null, 'config'),
            'baseSectionName' => $this->modx->getOption('mpc_base_section_name', null, 'base'),
            'staticBlocksPageId' => (int)$this->modx->getOption('mpc_static_block_page_id', null, 1),
            'contactsPageId' => (int)$this->modx->getOption('mpc_contacts_page_id', null, 1),
            'startPageId' => $this->modx->getOption('site_start', null, 1),
            'serviceInfoTvId' => $this->modx->getOption('mpc_service_info_tv_id', null, 0),
            'serviceInfoTvName' => $this->modx->getOption('mpc_service_info_tv_name', null, 'service_info'),
            'contactsTvId' => $this->modx->getOption('mpc_contacts_tv_id', null, 0),
            'contactsTvName' => $this->modx->getOption('mpc_contacts_tv_name', null, 'contacts'),

        ]);
        $this->modx->addPackage('migx', $this->properties['corePath'] . 'components/migx/model/');

        $this->logging = new Logging();
        $logFileName = str_replace('\\', '-', self::class) . '.txt';
        $this->logging->setPath($logFileName);
        $this->response = new Response($this->logging);
        $this->parser = new Parser();
    }

    /**
     * @param $fileName
     * @return array
     */
    public function handle($fileName): array
    {
        if ($this->debug) {
            $this->logging->write(__METHOD__, "Handle file $fileName");
            $this->logging->write(__METHOD__, "Properties:", $this->properties);
        }
        $filePath = $this->properties['pdotoolsElementsPath'] . $this->properties['pathToSrc'] . $fileName;
        if ($this->debug) {
            $this->logging->write(__METHOD__, "Path to file is $filePath");
        }
        if (!file_exists($filePath)) {
            return $this->response->error(__METHOD__, "File not found $filePath");
        }

        if (!$html = file_get_contents($filePath)) {
            return $this->response->error(__METHOD__, "File $filePath is empty");
        }

        $this->handleInformation($html);
        $this->handleContacts($html);
        if (strpos($fileName, 'wrapper') === false) {
            $this->handleTemplate($html);
            $this->handleSections($html);
        }

        return $this->response->success(__METHOD__, "Processing of file $fileName is completed");
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

        if (!$resource = $this->getResource((int)$this->properties['startPageId'])) {
            return;
        }

        $serviceInfo = $resource->getTVValue($this->properties['serviceInfoTvName']);
        $serviceInfo = $serviceInfo ? json_decode($serviceInfo, true) : [['MIGX_id' => 1]];
        foreach ($items as $item) {
            switch ($item->nodeName) {
                case 'link':
                    $serviceInfo[0][$item->getAttribute('data-mpc-info')] = $item->getAttribute('href');
                    break;
                case 'img':
                    $serviceInfo[0][$item->getAttribute('data-mpc-info')] = $item->getAttribute('src');
                    break;
                default:
                    $serviceInfo[0][$item->getAttribute('data-mpc-info')] = $item->nodeValue;
                    break;
            }
        }

        $resource->setTVValue($this->properties['serviceInfoTvName'], json_encode($serviceInfo));
        if ($this->debug) {
            $this->logging->write(__METHOD__, "Info was updated", $serviceInfo);
        }
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
            foreach ($fields as $field) {
                $key = $field->getAttribute('data-mpc-cfield');
                if ($key === 'attributes') {
                    $str = $this->parser->getHTMLString($field);
                    $tmp[$key] = str_replace('data-mpc-cfield="attributes"', '', $str);
                } else {
                    $tmp[$key] = trim($field->textContent);
                }
            }
            if (!$tmp['value']) {
                continue;
            }

            $contacts[$tmp['value']]['type'] = $tmp['type'];
            $contacts[$tmp['value']]['value'] = $tmp['value'];
            $contacts[$tmp['value']]['contаct_info'][$tmp['placement']] = [
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
                $contactInfo = json_decode($oldContacts[$value]['contаct_info'], true) ?: [];
                $contactInfo = $this->reformatMigx($contactInfo, 'placement');
                $contactInfo = array_merge($contactInfo, $item['contаct_info']);
                $oldContacts[$value]['contаct_info'] = $contactInfo;
            } else {
                $oldContacts[$value] = $item;
            }
        }

        $newContacts = [];
        $i = 0;
        foreach ($oldContacts as $item) {
            $item['MIGX_id'] = ++$i;
            if (!empty($item['contаct_info'])) {
                $item['contаct_info'] = !is_array($item['contаct_info']) ? json_decode($item['contаct_info'], true) : $item['contаct_info'];
                $j = 0;
                $contactInfo = [];
                foreach ($item['contаct_info'] as $info) {
                    $info['MIGX_id'] = ++$j;
                    $contactInfo[] = $info;
                }
                $item['contаct_info'] = json_encode($contactInfo, JSON_UNESCAPED_UNICODE);
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

        if (!$tplData['icon']) {
            $tplData['icon'] = "icon-gears";
        }

        $Template = new Template($this->modx);
        if (!$template = $Template->update($tplData)) {
            $this->response->error(__METHOD__, "Template was not updated or create.");
            return;
        }

        if (!$resource = $this->modx->getObject('modResource', ['pagetitle' => $tplData['pagetitle'], 'parent' => $this->properties['staticBlocksPageId']])) {
            $resource = $this->modx->newObject('modResource');
        }
        $resource->fromArray([
            'pagetitle' => $tplData['pagetitle'],
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
        $sbpResourceConfig = $staticBlocksResource->getTVValue($this->properties['commonConfigName']);
        $this->properties['sbpSectionValues'] = json_decode($sbpResourceConfig, true) ?: [];

        // получаем базовую конфигурацию mpc_base
        $result = $this->getObject('migxConfig', ['name' => $this->properties['baseSectionName']], true);
        if (!$result['success']) {
            return;
        }

        $defaultFormTabs = json_decode($result['data']['object']['formtabs'], true); // получаем вкладки формы базовой конфигурации
        $result = $this->getObject('migxConfig', ['name' => $this->properties['commonConfigName']]); // получаем mpc_config
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
                    $this->properties['multipleFormtabs'][] = $result['object']['id'];
                }
            }

            $values = $this->grabSection($section, $properties, $i);
            $sectionValues[$i] = $values;
        }

        // обновляем или заполняем контент типовой страницы.
        if ($this->updContent && !empty($sectionValues)) {
            if($this->debug){
                $this->logging->write(__METHOD__, 'Section values', $sectionValues);
            }
            $this->properties['resource']->setTVValue($this->properties['commonConfigName'], json_encode($sectionValues, JSON_UNESCAPED_UNICODE));
            if (!$this->properties['resource']->save()) {
                $this->response->error(__METHOD__, 'Failed to save resource.');
                return;
            }

            $staticBlocksResource->setTVValue($this->properties['commonConfigName'], json_encode($this->properties['sbpSectionValues'], JSON_UNESCAPED_UNICODE));
        }

        $commonConfigData['extended']['multiple_formtabs'] = implode('||', array_unique($this->properties['multipleFormtabs']));
        $commonConfig->fromArray($commonConfigData);
        if (!$commonConfig->save()) {
            $this->response->error(__METHOD__, 'Failed to save configuration.');
            return;
        }

        if ($this->updContent && !empty($this->resourceValues)) {
            if($this->debug){
                $this->logging->write(__METHOD__, 'Resource values', $this->resourceValues);
            }
            foreach ($this->resourceValues as $rid => $data) {
                $this->updateResourceData((int)$rid, $data);
            }
        }

        $this->response->success(__METHOD__, 'Section processing is complete.');
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
     * @param string $html
     * @param string $selector
     * @return \DOMNodeList|null
     */
    private function getItems(string $html, string $selector): ?\DOMNodeList
    {
        $items = $this->parser->findByAttribute($html, $selector);
        if (!$items->count()) {
            return null;
        }
        return $items;
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
        return $this->response->success(__METHOD__, 'Configuration saved successfully.');
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
     * @param int $i
     * @return array
     */
    private function grabSection(\DOMElement $section, array $properties, int $i = 1): array
    {
        $sectionIsStatic = $section->hasAttribute('data-mpc-static');
        $sectionId = $properties['sectionName'] . '_' . str_replace(['.', ',', ' '], '', microtime(true));

        // заполняем содержимое полей
        $fieldsValues = $this->getFieldsValues($this->parser->getHTMLString($section));
        $fieldsValues['is_static'] = $sectionIsStatic;
        $fieldsValues = array_merge([
            'MIGX_id' => $i,
            'MIGX_formname' => $properties['sectionName'],
            'id' => $sectionId,
            'section_name' => trim($section->getAttribute('data-mpc-name')),
            'file_name' => $properties['fileNameVis'],
        ], $fieldsValues);
        if (!$properties['isCopy']) {
            $this->updateStaticSectionValues($fieldsValues, $properties['sectionName']);
        }

        return $fieldsValues;
    }

    /**
     * @param array $sectionFieldsValues
     * @param string $sectionName
     * @return void
     */
    private function updateStaticSectionValues(array $sectionFieldsValues, string $sectionName)
    {
        $upd = false;
        $i = 0;
        if (!empty($this->properties['sbpSectionValues'])) {
            foreach ($this->properties['sbpSectionValues'] as $k => $sectionValue) {
                if ($sectionValue['MIGX_formname'] === $sectionName) {
                    $this->properties['sbpSectionValues'][$k] = $sectionFieldsValues;
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
     * @param string $fieldAttrName
     * @param string $itemAttrName
     * @param int $level
     * @return array
     */
    private function parseHTML(string $html, string $fieldAttrName = 'data-mpc-field', string $itemAttrName = 'data-mpc-item', int $level = 0): array
    {
        if (!$entries = $this->getItems($html, '[' . $fieldAttrName . ']')) {
            return [];
        }
        $fields = [];
        $level++;
        $nextFieldAttr = 'data-mpc-field-' . $level;
        $nextItemAttr = 'data-mpc-item-' . $level;
        $sectionImages = [];
        $sectionPictures = [];
        foreach ($entries as $key => $row) {
            $table = $row->getAttribute('data-mpc-table') ?: 'config';
            $rid = (int)$row->getAttribute('data-mpc-rid') ?: $this->properties['rid'];

            $fieldName = $row->getAttribute($fieldAttrName);
            if (strpos($fieldName, 'list_images') !== false) {
                $sectionImages[] = $row;
                continue;
            }
            if (strpos($fieldName, 'list_pictures') !== false) {
                $sectionPictures[] = $row;
                continue;
            }

            if ($items = $this->getItems($this->parser->getHTMLString($row), '[' . $itemAttrName . ']')) {
                if (strpos($fieldName, 'list') !== false) {
                    foreach ($items as $k => $item) {
                        $fields[$fieldName][$k]['MIGX_id'] = $k + 1;
                        $fields[$fieldName][$k] = array_merge(
                            $fields[$fieldName][$k],
                            $this->parseHTML($this->parser->getHTMLString($item), $nextFieldAttr, $nextItemAttr, $level)
                        );
                    }
                }
            } else {
                if ($values = $this->getItems($this->parser->getHTMLString($row), '[data-mpc-value]')) {
                    if (strpos($fieldName, 'list') !== false) {
                        $arr = [];
                        foreach ($values as $value) {
                            $arr[] = $value->textContent;
                        }
                        $fields[$fieldName] = !empty($arr) ? implode('||', $arr) : '';
                    }
                } else {
                    $fields[$fieldName] = $this->getFieldsData($row, $fieldName);
                }
                if ($fieldName === 'img') {
                    $fields['img_w'] = $row->getAttribute('width');
                    $fields['img_h'] = $row->getAttribute('height');
                    $fields['img_a'] = $row->getAttribute('alt');
                }
            }


            if ($table !== 'config') {
                $this->resourceValues[$rid][$table][$fieldName] = $fields[$fieldName];
            }
        }
        if (!empty($sectionImages)) {
            foreach ($sectionImages as $k => $row) {
                $table = $row->getAttribute('data-mpc-table') ?: 'config';
                $rid = (int)$row->getAttribute('data-mpc-rid') ?: $this->properties['rid'];
                $fields['list_images'][$k]['MIGX_id'] = $k + 1;
                $attrNames = ['src', 'alt', 'width', 'height'];
                foreach ($attrNames as $attrName) {
                    $fields['list_images'][$k][$attrName] = $row->getAttribute($attrName);
                }

                if ($table !== 'config') {
                    $this->resourceValues[$rid][$table]['list_images'] = $fields['list_images'];
                }
            }
        }

        if (!empty($sectionPictures)) {
            foreach ($sectionPictures as $k => $row) {
                $table = $row->getAttribute('data-mpc-table') ?: 'config';
                $rid = (int)$row->getAttribute('data-mpc-rid') ?: $this->properties['rid'];
                $img = $row->getElementsByTagName('img')[0];

                $fields['list_pictures'][$k]['MIGX_id'] = $k + 1;
                $fields['list_pictures'][$k]['picture'] = $this->getPictureSources($row);
                $attrNames = ['alt', 'width', 'height'];
                foreach ($attrNames as $attrName) {
                    $fields['list_pictures'][$k][$attrName] = $img->getAttribute($attrName);
                }

                if ($table !== 'config') {
                    $this->resourceValues[$rid][$table]['list_pictures'] = $fields['list_pictures'];
                }
            }
        }

        return $fields;
    }

    /**
     * @param \DOMElement $row
     * @param string $fieldName
     * @return string
     */
    private function getFieldsData(\DOMElement $row, string $fieldName): string
    {
        $result = '';
        if ($src = $row->getAttribute('src')) {
            $result = $src;
        } elseif ($fieldName === 'picture') {
            $result = $this->getPictureSources($row);
        } elseif ($href = $row->getAttribute('href')) {
            $result = $href;
        } elseif ($style = $row->getAttribute('style')) {
            preg_match('/url\(\'(.*?)\'\)/', $style, $matches);
            $result = $matches[1];
        } else {
            if ($row->childNodes->count()) {
                foreach ($row->childNodes as $childNode) {
                    $result .= $this->parser->getHTMLString($childNode);
                }
            } else {
                $result = $row->nodeValue;
            }

            if ($style = $row->getAttribute('style')) {
                if (strpos($style, 'background') !== false) {
                    preg_match('/(background|background\-image):.*?url\(\'(.*?)\'\)/', $style, $matches);
                    $result = $matches[2];
                }
            }
        }

        return $result;
    }

    /**
     * @param \DOMElement $picture
     * @return string
     */
    private function getPictureSources(\DOMElement $picture): string
    {
        $pictures = [];
        if ($sources = $picture->getElementsByTagName('source')) {
            foreach ($sources as $k => $source) {
                $pictures[] = [
                    'MIGX_id' => $k + 1,
                    'srcset' => $source->getAttribute('srcset'),
                    'type' => $source->getAttribute('type'),
                    'media' => $source->getAttribute('media'),
                ];
            }
        }
        return json_encode($pictures);
    }

    /**
     * @param int $rid
     * @param array $data
     * @return void
     */
    private function updateResourceData(int $rid, array $data): void
    {
        if (!$resource = $this->getResource($rid)) {
            $this->response->error(__METHOD__, "Failed to get resource ID = $rid");
            return;
        }

        if ($resource->get('parent') === $this->properties['staticBlocksPageId']) {
            unset($data['res']['pagetitle']);
        }

        $resource->fromArray($data['res']);
        $resource->save();
        if (!empty($data['tv'])) {
            foreach ($data['tv'] as $key => $value) {
                if (is_array($value)) {
                    $value = json_encode($value, JSON_UNESCAPED_UNICODE);
                }
                $resource->setTVValue($key, $value);
            }
        }

        $this->response->success(__METHOD__, "Resource ID = $rid data updated successfully");
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
}
