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
    public array $properties;

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
        $result = $this->getObject('migxConfig', ['name' => $this->properties['commonConfigTvName']]);
        if (!$result['success']) {
            return;
        }

        $commonConfig = $result['data']['object'];
        $commonConfigData = $commonConfig->toArray();
        $this->properties['multipleFormtabs'] = explode('||', $commonConfigData['extended']['multiple_formtabs']);

        $i = 0;
        $sectionValues = [];

        foreach ($sections as $section) {
            $this->mediaDownloader->setCurrentSectionName('');
            $i++;
            $sectionName = trim((string)$section->getAttribute('data-mpc-section'));
            $fileName = $sectionName . $this->properties['extension'];
            $fileNameVis = $this->properties['pdotoolsElementsPath'] . $this->properties['pathToSections'] . $fileName;

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
            $sectionValues[$i] = $values;
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

        if ($this->properties['updContent'] && !empty($sectionValues)) {
            $this->properties['resource']->setTVValue(
                $this->properties['commonConfigTvName'],
                json_encode($sectionValues, JSON_UNESCAPED_UNICODE)
            );
        }

        if (!empty($this->properties['sbpSectionValues'])) {
            $staticBlocksResource->setTVValue(
                $this->properties['commonConfigTvName'],
                json_encode($this->properties['sbpSectionValues'], JSON_UNESCAPED_UNICODE)
            );
        }

        $commonConfigData['extended']['multiple_formtabs'] = implode('||', array_unique($this->properties['multipleFormtabs']));
        $commonConfig->fromArray($commonConfigData);
        if (!$commonConfig->save()) {
            $this->response->error(__METHOD__, 'Failed to save configuration.');
            return;
        }

        if (!$this->properties['fromPlugin']) {
            $this->lexiconManager->createLexicons($this->lexiconManager->lexicons);
        }

        $this->response->success(__METHOD__, 'Section processing is complete.');
    }

    private function createSectionConfig(Element $section, array $properties): array
    {
        $properties['defaultFormTabs'][1]['fields'] = $this->getSectionFields($section, $properties['defaultFormTabs'][1]['fields']);
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

    private function getSectionFields(Element $section, array $defaultFields): array
    {
        $entries = $this->parser->findByAttribute($this->parser->getHTMLString($section), '[data-mpc-field]');
        if (!count($entries)) {
            return [];
        }

        $result = [];
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

    private function deleteUndueFields(array $defaultFields, array $needFields): array
    {
        $fields = [];
        foreach ($defaultFields as $v) {
            if (in_array($v['field'], $needFields)) {
                $fields[] = $v;
            }
        }
        return $fields;
    }

    private function grabSection(Element $section, array $properties, ?int $i = 1): array
    {
        $sectionName = trim((string)$section->getAttribute('data-mpc-name'));
        $prefix = trim((string)$section->getAttribute('data-mpc-lexicon')) ?: $properties['sectionName'];
        $isStatic = $section->hasAttribute('data-mpc-static');

        $this->lexiconManager->setContext($prefix, $isStatic);
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
