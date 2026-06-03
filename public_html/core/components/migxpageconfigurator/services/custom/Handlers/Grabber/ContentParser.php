<?php

namespace MpcServices\Handlers\Grabber;

use MpcServices\Handlers\Parser;

/**
 * Рекурсивный обход HTML, сбор значений всех полей → array.
 * Не зависит от modX.
 */
class ContentParser
{
    private Parser $parser;
    private FieldValueExtractor $fieldValueExtractor;

    public function __construct(Parser $parser, FieldValueExtractor $fieldValueExtractor)
    {
        $this->parser = $parser;
        $this->fieldValueExtractor = $fieldValueExtractor;
    }

    public function getFieldsValues(string $html): array
    {
        $fields = $this->parseHTML($html);
        foreach ($fields as $k => $v) {
            if (is_array($v)) {
                $fields[$k] = json_encode($v);
            }
        }
        return $fields;
    }

    private function parseHTML(string $html, ?array $options = []): array
    {
        $level = $options['level'] ?? 0;
        $fieldAttrName = $options['fieldAttrName'] ?? 'data-mpc-field';
        $itemAttrName = $options['itemAttrName'] ?? 'data-mpc-item';
        $idx = $options['idx'] ?? 0;

        $entries = $this->parser->findByAttribute($html, '[' . $fieldAttrName . ']');
        if (!count($entries)) {
            return [];
        }

        $fields = [];
        $level++;
        $nextFieldAttr = 'data-mpc-field-' . $level;
        $nextItemAttr = 'data-mpc-item-' . $level;
        $mediaLists = [];

        foreach ($entries as $key => $row) {
            $fieldName = $row->getAttribute($fieldAttrName);
            $lexiconOptions = [
                'fieldName' => $options['fieldName'] ?? $fieldName,
                'parentFieldName' => $options['parentFieldName'] ?? '',
                'idx' => $idx,
            ];

            if ($row->tagName() === 'img' && !in_array($fieldName, ['list_images', 'list_pictures'])) {
                $fields[$fieldName] = $this->fieldValueExtractor->getImageValue($row, $lexiconOptions);
            } elseif ($row->tagName() === 'picture' && !in_array($fieldName, ['list_images', 'list_pictures'])) {
                $fields[$fieldName] = $this->fieldValueExtractor->getPictureValue($row, $lexiconOptions);
            } elseif ($fieldName === 'bg_img') {
                $fields[$fieldName] = $this->fieldValueExtractor->getBackgroundValue($row, $lexiconOptions);
            } elseif (in_array($row->tagName(), ['video', 'audio']) && !in_array($fieldName, ['list_audios', 'list_videos'])) {
                $fields[$fieldName] = $this->fieldValueExtractor->getMediaValue($row, $lexiconOptions);
            } elseif (in_array($fieldName, ['list_images', 'list_pictures', 'list_audios', 'list_videos'])) {
                $mediaLists[$fieldName][] = $row;
            } elseif (!empty($items = $this->parser->findByAttribute($this->parser->getHTMLString($row), '[' . $itemAttrName . ']'))) {
                foreach ($items as $k => $item) {
                    // Конструкция parentFieldName — единая с редактором (LexiconManager).
                    $parentFieldName = LexiconManager::appendLexiconParent(
                        (string)($lexiconOptions['parentFieldName'] ?? ''), $fieldName, $k
                    );

                    $fields[$fieldName][$k]['MIGX_id'] = $k + 1;
                    $value = $this->parseHTML($this->parser->getHTMLString($item), [
                        'fieldAttrName' => $nextFieldAttr,
                        'itemAttrName' => $nextItemAttr,
                        'level' => $level,
                        'idx' => $k,
                        'parentFieldName' => $parentFieldName,
                        'fieldName' => $options['fieldName'] ?? null,
                    ]);
                    $fields[$fieldName][$k] = array_merge($fields[$fieldName][$k], $value);
                }
            } else {
                $fields[$fieldName] = $this->fieldValueExtractor->getValue($row, $lexiconOptions);
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
                default:
                    continue 2;
            }
            foreach ($items as $k => $row) {
                $lexiconOptions['idx'] = $k;
                $lexiconOptions['fieldName'] = $fieldName;
                $value = $this->fieldValueExtractor->$method($row, $lexiconOptions);
                $value = !is_array($value) ? json_decode($value, true) : $value;
                $preview = $value[0][$pathKey] ?? '';
                $value = json_encode($value, JSON_UNESCAPED_UNICODE);
                $fields[$fieldName][$k] = [
                    'MIGX_id' => $k + 1,
                    $valueKey => $value,
                    $previewKey => $preview,
                ];
            }
        }

        return $fields;
    }
}
