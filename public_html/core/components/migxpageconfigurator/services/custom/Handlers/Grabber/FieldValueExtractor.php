<?php

namespace MpcServices\Handlers\Grabber;

use DiDom\Element;
use MpcServices\Handlers\Parser;

/**
 * Извлечение значений из конкретных DOM-элементов.
 * Зависит от MediaDownloader и LexiconManager.
 */
class FieldValueExtractor
{
    private array $downloadMethodsByTagName = [
        'picture' => 'downloadImage',
        'video'   => 'downloadVideo',
        'audio'   => 'downloadAudio',
    ];

    private array           $properties;
    private MediaDownloader $mediaDownloader;
    private LexiconManager  $lexiconManager;
    private Parser          $parser;

    public function __construct(
        array           $properties,
        MediaDownloader $mediaDownloader,
        LexiconManager  $lexiconManager,
        Parser          $parser
    ) {
        $this->properties      = $properties;
        $this->mediaDownloader = $mediaDownloader;
        $this->lexiconManager  = $lexiconManager;
        $this->parser          = $parser;
    }

    public function getImageValue(Element $row, ?array $options = []): string
    {
        $attrs   = ['src', 'alt', 'width', 'height'];
        $value[0]['MIGX_id'] = 1;

        foreach ($attrs as $attr) {
            $attrValue = $row->getAttribute($attr);

            if ($attr === 'src' && strpos($attrValue, 'http') !== false) {
                $attrValue = $this->mediaDownloader->downloadImage($attrValue);
            }
            if ($attr === 'src') {
                $attrValue = in_array('image', $this->properties['translatableContentTypes'])
                    ? $this->lexiconManager->setLexicons($attrValue, $options) : $attrValue;
            }
            if ($attr === 'alt') {
                $options['fieldName'] = ($options['fieldName'] ?? '') . '_alt';
                $attrValue = in_array('text', $this->properties['translatableContentTypes'])
                    ? $this->lexiconManager->setLexicons($attrValue, $options) : $attrValue;
            }
            $value[0][$attr] = $attrValue;
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE);
    }

    public function getSourceValue(Element $row, ?int $idx = 1, ?bool $isPicture = true): array
    {
        $attrs = ['type', 'media'];
        if (!$isPicture) {
            $attrs[] = 'src';
        } else {
            $attrs = array_merge($attrs, ['srcset', 'sizes', 'height', 'width']);
        }

        $parent = $row->parent();
        if ($parent->tagName() === $row->tagName()) {
            $parent = $parent->parent();
        }

        $downloadMethod  = $this->downloadMethodsByTagName[$parent->tagName()];
        $value['MIGX_id'] = $idx;

        foreach ($attrs as $attr) {
            $attrValue = $row->getAttribute($attr);
            if (in_array($attr, ['srcset', 'src']) && strpos($row->getAttribute($attr), 'http') !== false) {
                $attrValue = method_exists($this->mediaDownloader, $downloadMethod)
                    ? $this->mediaDownloader->$downloadMethod($attrValue)
                    : $attrValue;
            }
            $value[$attr] = $attrValue;
        }

        return $value;
    }

    public function getPictureValue(Element $element, ?array $options = []): string
    {
        $picture[0]['MIGX_id']  = 1;
        $picture[0]['preview']  = '';
        $picture[0]['img']      = [];
        $picture[0]['sources']  = [];

        if ($img = $element->first('img')) {
            $picture[0]['img']     = $this->getImageValue($img, $options);
            $picture[0]['preview'] = $img->getAttribute('src');
        }

        if ($sources = $element->find('source')) {
            $options['parentFieldName'] = $options['idx'] ? "{$options['fieldName']}_{$options['idx']}" : $options['fieldName'];
            $options['fieldName']       = 'source';
            foreach ($sources as $k => $source) {
                $options['idx']                    = $k;
                $picture[0]['sources'][$k]         = $this->getSourceValue($source, $k + 1);
                $picture[0]['sources'][$k]['srcset'] = in_array('image', $this->properties['translatableContentTypes'])
                    ? $this->lexiconManager->setLexicons($picture[0]['sources'][$k]['srcset'], $options)
                    : $picture[0]['sources'][$k]['srcset'];
            }
        }

        return json_encode($picture);
    }

    public function getMediaValue(Element $element, ?array $options = []): array
    {
        $useLexicons = in_array('video', $this->properties['translatableContentTypes'])
            || in_array('audio', $this->properties['translatableContentTypes']);

        $media[0]['MIGX_id'] = 1;
        $attrs = [
            'src'      => 'string',
            'autoplay' => 'boolean',
            'controls' => 'boolean',
            'loop'     => 'boolean',
            'muted'    => 'boolean',
            'preload'  => 'boolean',
        ];

        if ($element->tagName() === 'video') {
            $attrs = array_merge($attrs, [
                'src'    => 'string',
                'width'  => 'number',
                'height' => 'number',
                'poster' => 'string',
            ]);
        }

        $media[0]['sources'] = [];
        foreach ($attrs as $attr => $type) {
            if ($type === 'boolean') {
                $media[0][$attr] = $element->hasAttribute($attr) ? 1 : 0;
            } else {
                $media[0][$attr] = $element->getAttribute($attr) ?: '';
            }

            if ($attr === 'poster') {
                if (strpos($media[0][$attr], 'http') !== false) {
                    $media[0][$attr] = $this->mediaDownloader->downloadImage($media[0][$attr]);
                }
                $parentFieldName = $this->getParentFieldName($options);
                $lexiconOptions  = ['fieldName' => 'poster', 'parentFieldName' => $parentFieldName, 'idx' => 0];
                $media[0][$attr] = in_array('poster', $this->properties['translatableContentTypes'])
                    ? $this->lexiconManager->setLexicons($media[0][$attr], $lexiconOptions)
                    : $media[0][$attr];
            }

            if ($attr === 'src') {
                if (strpos($media[0][$attr], 'http') !== false) {
                    $downloadMethod  = $this->downloadMethodsByTagName[$element->tagName()];
                    $media[0][$attr] = method_exists($this->mediaDownloader, $downloadMethod)
                        ? $this->mediaDownloader->$downloadMethod($media[0][$attr])
                        : $media[0][$attr];
                }
                $media[0][$attr] = $useLexicons
                    ? $this->lexiconManager->setLexicons($media[0][$attr], $options)
                    : $media[0][$attr];
            }
        }

        if ($sources = $element->find('source')) {
            $parentFieldName = $this->getParentFieldName($options);
            $lexiconOptions  = ['fieldName' => 'source', 'parentFieldName' => $parentFieldName];
            foreach ($sources as $k => $source) {
                $lexiconOptions['idx']              = $k;
                $media[0]['sources'][$k]            = $this->getSourceValue($source, $k + 1, false);
                $media[0]['sources'][$k]['src']     = $useLexicons
                    ? $this->lexiconManager->setLexicons($media[0]['sources'][$k]['src'], $lexiconOptions)
                    : $media[0]['sources'][$k]['src'];
            }
        }

        if (!$media[0]['src']) {
            $media[0]['src'] = $media[0]['sources'][0]['src'] ?? '';
        }

        return $media;
    }

    public function getBackgroundValue(Element $element, ?array $options = []): string
    {
        if ($style = $element->getAttribute('style')) {
            if (strpos($style, 'background') !== false) {
                preg_match('/(background|background\-image):.*?url\(\'(.*?)\'\)/', $style, $matches);
                $value = $matches[2] ?? '';
                if (strpos($value, 'http') !== false) {
                    $value = $this->mediaDownloader->downloadImage($value);
                }
                return in_array('image', $this->properties['translatableContentTypes'])
                    ? $this->lexiconManager->setLexicons($value, $options) : $value;
            }
        }
        return '';
    }

    public function getValue(Element $element, ?array $options = []): string
    {
        if ($href = $element->getAttribute('href')) {
            $result = $href;
        } elseif ($children = $element->children()) {
            $tmp = [];
            foreach ($children as $childNode) {
                $tmp[] = trim($this->parser->getHTMLString($childNode));
            }
            $result = implode(' ', $tmp);
        } else {
            $result = trim($element->innerHtml());
        }

        return in_array('text', $this->properties['translatableContentTypes']) && !empty($options)
            ? $this->lexiconManager->setLexicons($result, $options) : $result;
    }

    public function getParentFieldName(array $options): string
    {
        $fieldName       = $options['fieldName'] ?? '';
        $parentFieldName = $options['parentFieldName'] ?? '';
        $idx             = $options['idx'] ?? 0;

        if ($parentFieldName) {
            return $idx
                ? $parentFieldName . '_' . "{$fieldName}_{$idx}"
                : $parentFieldName . '_' . $fieldName;
        }
        return $idx ? "{$fieldName}_{$idx}" : $fieldName;
    }
}
