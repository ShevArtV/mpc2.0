<?php

require_once dirname(__FILE__) . '/mpcbasehandler.class.php';

/**
 *
 */
class MpcGrabHandler extends MpcBaseHandler
{
    /**
     * @param $properties
     * @return void
     */
    protected function setProperties($properties)
    {
        $this->properties = [
            'corePath' => $this->modx->getOption('core_path', null, ''),
            'extension' => $this->modx->getOption('mpc_tpl_file_extension', null, '.tpl'),
            'pdotoolsElementsPath' => $this->modx->getOption('pdotools_elements_path', null, '{core_path}elements/'),
            'pathToSections' => $this->modx->getOption('mpc_path_to_sections', null, 'sections/'),
            'commonConfigName' => $this->modx->getOption('mpc_common_config_name', null, 'config'),
            'baseSectionName' => $this->modx->getOption('mpc_base_section_name', null, 'base'),
            'sbpId' => (int)$this->modx->getOption('mpc_static_block_page_id', null, 1),

        ];
        $this->properties['pdotoolsElementsPath'] = str_replace($this->properties['corePath'], '', $this->properties['pdotoolsElementsPath']);
        $this->properties = array_merge($this->properties, $properties);
        $this->modx->addPackage('migx', $this->properties['corePath'] . 'components/migx/model/');
    }

    /**
     * @return array|object|string
     */
    public function handle()
    {
        if (empty($this->properties['rid'])) {
            $this->modx->error->addError('[MpcGrabHandler::handle] Не передан ID ресурса.');
            return $this->modx->error->failure('', $this->properties);
        }

        if (!$this->properties['resource'] = $this->modx->getObject('modResource', $this->properties['rid'])) {
            $this->modx->error->addError('[MpcGrabHandler::handle] Не удалось получить ресурс с ID=' . $this->properties['rid']);
            return $this->modx->error->failure('', $this->properties);
        }

        if (empty($this->properties['html'])) {
            $this->modx->error->addError('[MpcGrabHandler::handle] Не передан HTML.');
            return $this->modx->error->failure('', $this->properties);
        }

        $sections = $this->findByAttribute($this->properties['html'], '[data-mpc-section]');
        if (empty($sections)) {
            $this->modx->error->addError('[MpcGrabHandler::handle] Не найдено ни одной секции.');
            return $this->modx->error->failure();
        }

        $sbpResource = $this->modx->getObject('modResource', $this->properties['sbpId']);
        $sbpResourceConfig = $sbpResource->getTVValue($this->properties['commonConfigName']);
        $this->properties['sbpSectionValues'] = json_decode($sbpResourceConfig, true) ?: [];

        $result = $this->getObjectData('migxConfig', array('name' => $this->properties['baseSectionName']));
        if (!$result['success']) {
            return $this->modx->error->failure('', $result['object']);
        }
        //$this->modx->log(1, print_r($result['object'], 1));
        $defaultFormTabs = json_decode($result['object']['formtabs'], true); // базовая вкладка формы
        if (!$commonConfig = $this->modx->getObject('migxConfig', array('name' => $this->properties['commonConfigName']))) {
            $this->modx->error->addError('[MpcGrabHandler::handle] Не удалось получить конфигурацию с именем ' . $this->properties['commonConfigName']);
            return $this->modx->error->failure('', $this->properties);
        }
        $commonConfigData = $commonConfig->toArray(); // получаем конфигурацию конфигураций
        $this->properties['multipleFormtabs'] = explode('||', $commonConfigData['extended']['multiple_formtabs']);

        $i = 0;
        $sectionValues = [];
        $this->resourceValues = [];
        foreach ($sections as $section) {
            $i++;
            $values = $this->grabSection($section, $defaultFormTabs, $i);
            $sectionValues[$i] = $values;
        }

        // обновляем или заполняем контент типовой страницы.
        if ($this->properties['updContent'] && !empty($sectionValues)) {
            $this->properties['resource']->setTVValue($this->properties['commonConfigName'], json_encode($sectionValues, JSON_UNESCAPED_UNICODE));
            if (!$this->properties['resource']->save()) {
                $this->modx->error->addError('[MpcGrabHandler::handle] Не удалось сохранить ресурс с ID ' . $this->properties['resource']->get('id'));
                return $this->modx->error->failure('', $result);
            }
            $sbpResource->setTVValue($this->properties['commonConfigName'], json_encode($this->properties['sbpSectionValues'], JSON_UNESCAPED_UNICODE));
            if (!$sbpResource->save()) {
                $this->modx->error->addError('[MpcGrabHandler::handle] Не удалось сохранить ресурс с ID ' . $sbpResource->get('id'));
                return $this->modx->error->failure('', $result);
            }
        }

        if ($this->properties['updContent'] && !empty($this->resourceValues)) {
            foreach ($this->resourceValues as $rid => $data) {
                $this->updateResourceData($rid, $data);
            }
        }

        $commonConfigData['extended']['multiple_formtabs'] = implode('||', array_unique($this->properties['multipleFormtabs']));
        $commonConfig->fromArray($commonConfigData);
        if (!$commonConfig->save()) {
            $this->modx->error->addError('[MpcGrabHandler::handle] Не удалось сохранить общую конфигурацию.');
            return $this->modx->error->failure('', $result);
        }
        return $this->modx->error->success('', $commonConfig);
    }

    /**
     * @param DOMElement $section
     * @param array $defaultFormTabs
     * @param int $i
     * @return array
     */
    private function grabSection(DOMElement $section, array $defaultFormTabs, int $i = 1): array
    {
        $sectionName = trim($section->getAttribute('data-mpc-section'));
        $isCopy = $section->hasAttribute('data-mpc-copy');

        $sectionIsStatic = $section->hasAttribute('data-mpc-static');
        $sectionId = $sectionName . '_' . str_replace(['.', ',', ' '], '', microtime(true));
        $fileName = $sectionName . $this->properties['extension'];
        $fileNameVis = $this->properties['pdotoolsElementsPath'] . $this->properties['pathToSections'] . $fileName;

        // заполняем содержимое полей
        $fieldsValues = $this->getFieldsValues($section);
        $fieldsValues['is_static'] = $sectionIsStatic;
        $fieldsValues = array_merge([
            'MIGX_id' => $i,
            'MIGX_formname' => $sectionName,
            'id' => $sectionId,
            'section_name' => trim($section->getAttribute('data-mpc-name')),
            'file_name' => $fileNameVis,
        ], $fieldsValues);

        if ($sectionIsStatic && !$isCopy) {
            $this->updateStaticSectionValues($fieldsValues, $sectionName);
        }

        if (!$isCopy) {
            // обновляем или создаём конфигурацию для секции.
            $this->properties['multipleFormtabs'][] = $this->createSectionConfig($section, $defaultFormTabs, $fileNameVis, $sectionName);
        }

        return $fieldsValues;
    }

    /**
     *
     * @param DOMElement $section
     * @return array
     */
    private function getFieldsValues(DOMElement $section): array
    {
        $fields = $this->parseHTML($this->getHTMLString($section));
        foreach ($fields as $k => $v) {
            if (is_array($v)) {
                $fields[$k] = json_encode($v);
            }
        }
        return $fields;
    }

    /**
     *
     * @param string $html
     * @param string $fieldAttrName
     * @param string $itemAttrName
     * @param int $level
     * @return array
     */
    private function parseHTML(string $html, string $fieldAttrName = 'data-mpc-field', string $itemAttrName = 'data-mpc-item', int $level = 0): array
    {
        $entries = $this->findByAttribute($html, '[' . $fieldAttrName . ']');
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
            $items = $this->findByAttribute($this->getHTMLString($row), '[' . $itemAttrName . ']');
            if (strpos($fieldName, 'list_images') !== false) {
                $sectionImages[] = $row;
                continue;
            }
            if (strpos($fieldName, 'list_pictures') !== false) {
                $sectionPictures[] = $row;
                continue;
            }

            if ($items->count() && strpos($fieldName, 'list') !== false) {
                foreach ($items as $k => $item) {
                    $fields[$fieldName][$k]['MIGX_id'] = $k + 1;
                    $fields[$fieldName][$k] = array_merge($fields[$fieldName][$k], $this->parseHTML($this->getHTMLString($item), $nextFieldAttr, $nextItemAttr, $level));
                }
            } else {
                $values = $this->findByAttribute($this->getHTMLString($row), '[data-mpc-value]');
                if ($values->count() && strpos($fieldName, 'list') !== false) {
                    $arr = [];
                    foreach ($values as $value) {
                        $arr[] = $value->textContent;
                    }
                    $fields[$fieldName] = !empty($arr) ? implode('||', $arr) : '';
                } else {
                    $fields[$fieldName] = $this->getFieldsData($row, $fieldName);
                }

                if ($fieldName === 'img') {
                    $fields['img_w'] = $row->getAttribute('width');
                    $fields['img_h'] = $row->getAttribute('height');
                }
                if ($fieldName === 'img_mob') {
                    $fields['img_mob_w'] = $row->getAttribute('width');
                    $fields['img_mob_h'] = $row->getAttribute('height');
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
     * @param DOMElement $row
     * @param string $fieldName
     * @return string
     */
    private function getFieldsData(DOMElement $row, string $fieldName): string
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
                    $result .= $this->getHTMLString($childNode);
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
     * @param DOMElement $picture
     * @return string
     */
    private function getPictureSources(DOMElement $picture): string
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
     *
     * @param array $sectionFieldsValues
     * @param string $sectionName
     * @return void
     */
    private function updateStaticSectionValues(array $sectionFieldsValues, string $sectionName)
    {
        $upd = false;
        $i = 1;
        if (!empty($this->properties['sbpSectionValues'])) {
            foreach ($this->properties['sbpSectionValues'] as $k => $sectionValue) {
                if ($sectionValue['MIGX_formname'] === $sectionName) {
                    $this->properties['sbpSectionValues'][$k] = $sectionFieldsValues;
                    $upd = true;
                }
                $i = $k++;
            }
        }
        if (!$upd) {
            $this->properties['sbpSectionValues'][$i] = $sectionFieldsValues;
        }
    }

    /**
     *
     * @param DOMElement $section
     * @param array $defaultFormTabs
     * @param string $fileNameVis
     * @param string $sectionName
     * @return int
     */
    private function createSectionConfig(DOMElement $section, array $defaultFormTabs, string $fileNameVis, string $sectionName): int
    {
        $defaultFormTabs[1]['fields'] = $this->getSectionFields($section, $defaultFormTabs[1]['fields']);
        $defaultFormTabs[0]['fields'][2]['default'] = $fileNameVis; // устанавливаем имя файла секции
        $defaultFormTabs[0]['fields'][2]['useDefaultIfEmpty'] = 1;
        $defaultFormTabs[0]['fields'][1]['default'] = $section->getAttribute('data-mpc-name'); // устанавливаем имя секции
        $defaultFormTabs[0]['fields'][1]['useDefaultIfEmpty'] = 1;
        $defaultFormTabs[0]['fields'][0]['default'] = $sectionName; // устанавливаем id секции
        $defaultFormTabs[0]['fields'][0]['useDefaultIfEmpty'] = 1;

        $defaultConfigData['formtabs'] = json_encode($defaultFormTabs);
        $defaultConfigData['name'] = $sectionName;
        $defaultConfigData['extended']['multiple_formtabs_optionstext'] = $section->getAttribute('data-mpc-name');
        $defaultConfigData['editedon'] = date('Y-m-d H:i:s');

        if (!$config = $this->modx->getObject('migxConfig', array('name' => $sectionName))) {
            $config = $this->modx->newObject('migxConfig');
        }

        $config->fromArray($defaultConfigData);
        if (!$config->save()) {
            $this->modx->error->addError('[MpcGrabHandler::createSectionConfig] Не удалось сохранить конфигурацию секции с именем ' . $sectionName);
            return $this->modx->error->failure('', $defaultConfigData);
        }

        return (int)$config->get('id');
    }

    /**
     * @param int $rid
     * @param array $data
     * @return bool
     */
    private function updateResourceData(int $rid, array $data): bool
    {
        if (!$resource = $this->modx->getObject('modResource', $rid)) {
            $this->modx->log(1, '[MpcGrabHandler::updateResourceData] не удалось получить ресурс c ID=' . $rid);
            return false;
        }
        if($resource->get('parent') === $this->properties['sbpId']) unset($data['res']['pagetitle']);

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

        return true;
    }

    /**
     *
     * @param DOMElement $section
     * @param array $default_fields
     * @return array
     */
    private function getSectionFields(DOMElement $section, array $default_fields): array
    {
        $result = array();
        $entries = $this->findByAttribute($this->getHTMLString($section), '[data-mpc-field]');
        if (count($entries)) {
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
        }
        return $this->deleteUndueFields($default_fields, $result);
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

        foreach ($defaultFields as $k => $v) {
            if (in_array($v['field'], $needFields)) {
                $fields[] = $v;
            }
        }

        return $fields;
    }
}