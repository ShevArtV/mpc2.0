<?php

/**
 * Сервис для нарезки шаблона на чанки и секции и расстановки плейсхолдеров.
 */

namespace MpcServices\Handlers;

use Couchbase\ThresholdLoggingTracer;

/**
 * @author Arthur Shevchenko (https://t.me/ShevArtV)
 */
class Cutter extends Base
{
    private string $html = '';

    /**
     * @return void
     */
    protected function initialize(): void
    {
        parent::initialize();

        $properties = [
            'pathToChunks' => $this->modx->getOption('mpc_path_to_chunks', null, 'chunks/'),
            'chunkNames' => [],
            'pattern' => '/(\s)*?data-mpc-(nolazy|copy|symbol|if|static|name|item|unwrap|section|snippet|chunk|include|parse|remove|attr|field|cfield|contact|ctx|info|lim|off)(-){0,1}([0-9]*)(=".*?"){0,1}(\s)*?/',
            'wrapperName' => $this->modx->getOption('mpc_wrapper_name', null, 'wrapper'),
            'thumbFormat' => $this->modx->getOption('mpc_thumb_format', null, 'png'),
            'fakeImgPath' => $this->modx->getOption('mpc_fake_img_path', null, 'assets/components/migxpageconfigurator/images/fake-img.png'),
            'pathToPresets' => $this->modx->getOption('mpc_path_to_presets', null, 'components/migxpageconfigurator/elements/presets/'),
            'pathToSamples' => $this->modx->getOption('mpc_path_to_samples', null, 'components/migxpageconfigurator/elements/samples/'),
            'presets' => [],
        ];
        $this->properties = array_merge($this->properties, $properties);
        $this->getPresets();
        $this->getSamples();
    }

    /**
     * @return void
     */
    private function getPresets()
    {
        $pathToPresets = $this->properties['pdotoolsElementsPath'] . $this->properties['pathToPresets'];
        if (file_exists($pathToPresets)) {
            $files = scandir($pathToPresets);
            unset($files[0], $files[1]);
            if (count($files)) {
                foreach ($files as $file) {
                    $presetsFilePath = $pathToPresets . $file;
                    $this->properties['presets'][str_replace('.inc.php', '', $file)] = include($presetsFilePath);
                }
            }
        }
    }

    private function getSamples()
    {
        $pathToSamples = $this->properties['corePath'] . $this->properties['pathToSamples'];
        if (file_exists($pathToSamples)) {
            $files = scandir($pathToSamples);
            unset($files[0], $files[1]);
            if (count($files)) {
                foreach ($files as $file) {
                    $presetsFilePath = $pathToSamples . $file;
                    $this->properties['samples'][str_replace('.tpl', '', $file)] = file_get_contents($presetsFilePath);
                }
            }
        }
    }

    public function handle(string $fileName): array
    {
        if ($this->debug) {
            $this->logging->write(__METHOD__, "Handle file $fileName");
        }

        if (!$this->html = $this->getFileContent($fileName)) {
            return $this->response->error(__METHOD__, "File $fileName is empty");
        }

        $this->handleInformation();
        $this->handleContacts();
        $this->handleSections();

        return $this->response->success(__METHOD__, "Processing of file $fileName is completed");
    }

    private function handleInformation()
    {
        if (!$items = $this->getItems($this->html, '[data-mpc-info]')) {
            return;
        }
        foreach ($items as $item) {
            $infoKey = $item->getAttribute('data-mpc-info');
            $itemHtml = $this->parser->getHTMLString($item);
            $itemHtmlNew = '';
            $pls = "{\$_modx->config['$infoKey']}";
            switch ($item->nodeName) {
                case 'link':
                    $item->setAttribute('href', $pls);
                    break;
                case 'img':
                    $item->setAttribute('src', $pls);
                    break;
                default:
                    if ($item->hasAttribute('data-mpc-unwrap')) {
                        $itemHtmlNew = $pls;
                    } else {
                        $item->nodeValue = $pls;
                    }
                    break;
            }
            $itemHtmlNew = $itemHtmlNew ?: $this->parser->getHTMLString($item);

            if ($item->hasAttribute('data-mpc-if')) {
                $condition = $item->getAttribute('data-mpc-if') ?: "\$_modx->config['$infoKey']";
                $itemHtmlNew = $this->wrapInCondition($condition, $itemHtmlNew);
            }
            $this->html = str_replace($itemHtml, $itemHtmlNew, $this->html);
        }
    }

    private function handleContacts()
    {
        if (!$items = $this->getItems($this->html, '[data-mpc-contact]')) {
            return;
        }
        $search = [];

        foreach ($items as $item) {
            if (!$fields = $this->getItems($this->parser->getHTMLString($item), '[data-mpc-cfield]')) {
                continue;
            }
            $contactAttrValue = explode('|', trim($item->getAttribute('data-mpc-contact')));
            if (!$valueField = $this->getItems($this->parser->getHTMLString($item), '[data-mpc-cfield="value"]')[0]) {
                continue;
            }
            if ($href = $valueField->getAttribute('href')) {
                $value = $href;
            } else {
                $value = trim($valueField->textContent);
            }
            $key = $item->getAttribute('data-mpc-key') ?: $this->getContactKey($value);
            $type = $contactAttrValue[0];
            $placement = $contactAttrValue[1] ?? 'default';
            foreach ($fields as $field) {
                $fieldName = $field->getAttribute('data-mpc-cfield');
                $complexName = "{\$contacts['$placement']['$key']['$fieldName']}";
                $search[] = $this->parser->getHTMLString($field);
                if ($fieldName === 'value') {
                    if ($field->hasAttribute('href')) {
                        if ($type === 'phone') {
                            $complexName = 'tel:' . $complexName;
                        }
                        if ($type === 'email') {
                            $complexName = 'mailto:' . $complexName;
                        }
                        $field->setAttribute('href', $complexName);
                    }
                    $field->nodeValue = "{\$contacts['$placement']['$key']['fvalue']}";
                    $replacement[] = $this->parser->getHTMLString($field);
                } else {
                    if ($field->hasAttribute('src')) {
                        $field->setAttribute('src', $complexName);
                        $replacement[] = $this->parser->getHTMLString($field);
                    } else {
                        $replacement[] = $complexName;
                    }
                }
            }
        }

        if (!empty($replacement)) {
            $this->html = str_replace($search, $replacement, $this->html);
        }
    }

    private function handleSections()
    {
        if (!$sections = $this->getItems($this->html, '[data-mpc-section]')) {
            return;
        }

        foreach ($sections as $section) {
            $isCopy = $section->hasAttribute('data-mpc-copy');
            if ($isCopy) {
                continue;
            }

            if ($innerChunks = $this->getItems($this->parser->getHTMLString($section), '[data-mpc-chunk]')) {
                $this->parseInnerChunks($innerChunks);
            }

            // если секция НЕ является копией аналогичной из другого шаблона - создаем для неё файлы return !empty($newResourceData[$key][0]) ? count($newResourceData[$key]) : 0;
            $this->createSectionFiles($section);
        }
    }

    /**
     * @param \DOMNodeList $innerChunks
     * @return void
     */
    private function parseInnerChunks(\DOMNodeList $innerChunks)
    {
        foreach ($innerChunks as $innerChunk) {
            if (!property_exists($innerChunk, 'nodeValue')) {
                continue;
            }
            $chunkName = $innerChunk->getAttribute('data-mpc-chunk');

            if (in_array($chunkName, $this->properties['chunkNames'])) {
                continue;
            }
            $this->properties['chunkNames'][] = $chunkName;
            if ($subInnerChunks = $this->getItems($this->parser->getHTMLString($innerChunk), '[data-mpc-chunk]')) {
                $this->parseInnerChunks($subInnerChunks);
            }

            $dirName = explode('/', $chunkName);
            if (count($dirName) > 1) {
                unset($dirName[count($dirName) - 1]);
                $dirName = implode('/', $dirName);
            } else {
                $dirName = '';
            }
            $baseDir = $this->properties['pdotoolsElementsPath'] . $this->properties['pathToChunks'];
            if (!is_dir($baseDir . $dirName)) {
                mkdir($baseDir . $dirName, 0777, true);
            }
            $path = $baseDir . $chunkName;

            $this->putToFile($innerChunk, $path);
        }
    }

    /**
     * @param \DOMElement $section
     * @return void
     */
    private function createSectionFiles(\DOMElement $section)
    {
        $sectionName = trim($section->getAttribute('data-mpc-section'));
        $fileName = $sectionName . $this->properties['extension'];
        $pathToFile = $this->properties['pdotoolsElementsPath'] . $this->properties['pathToSections'] . $fileName;

        $this->putToFile($section, $pathToFile);
    }

    /**
     * @param \DOMElement $element
     * @param string $pathToFile
     * @return void
     */
    private function putToFile(\DOMElement $element, string $pathToFile)
    {
        $html = $this->parser->getHTMLString($element);
        $sectionName = trim($element->getAttribute('data-mpc-name'));
        $properties = [
            'html' => $html,
            'element' => $element,
            'fieldAttrName' => 'data-mpc-field',
            'itemAttrName' => 'data-mpc-item',
            'level' => 0,
            'isStatic' => empty($this->staticSectionNames) ? $element->hasAttribute('data-mpc-static') : in_array($sectionName, $this->staticSectionNames)
        ];
        $properties = $this->setPlaceholders($properties);
        $properties = $this->setSnippetTags($properties);
        if (!$properties['element']->hasAttribute('data-mpc-parse')) {
            $properties = $this->setParseChunks($properties);
        }
        if (!$properties['element']->hasAttribute('data-mpc-include')) {
            $properties = $this->setIncludeChunks($properties);
        }

        $properties = $this->removeHiddenPlaceholders($properties);

        if ($attrs = $this->getItems($properties['html'], '[data-mpc-attr]')) {
            foreach ($attrs as $attr) {
                $attrValue = $attr->getAttribute('data-mpc-attr');
                $search = 'data-mpc-attr="' . $attrValue . '"';
                $properties['html'] = str_replace($search, $attrValue, $properties['html']);
            }
        }

        if ($unwrap = $this->getItems($properties['html'], '[data-mpc-unwrap]')) {
            foreach ($unwrap as $attr) {
                $attrValue = '';
                foreach ($attr->childNodes as $childNode) {
                    $attrValue .= $this->parser->getHTMLString($childNode);
                }
                $search = $this->parser->getHTMLString($attr);
                $properties['html'] = str_replace($search, $attrValue, $properties['html']);
            }
        }

        if (strpos($element->getAttribute('data-mpc-section'), $this->properties['wrapperName']) !== false) {
            $properties['html'] = preg_replace('/<body(.*?)>(.*?)<\/body>/s', '<body\1>' . $properties['html'] . '</body>', $this->html);
        }

        $properties['html'] = str_replace('`', '"', $properties['html']);
        $properties['html'] = preg_replace($this->properties['pattern'], '', $properties['html']);

        file_put_contents($pathToFile, $properties['html']);
        //$this->modx->log(1, $properties['html']);
    }

    /**
     * @param array $properties
     * @return array
     */
    private function setPlaceholders(array $properties): array
    {
        $fieldAttrName = $properties['level'] ? $properties['fieldAttrName'] . '-' . $properties['level'] : $properties['fieldAttrName'];
        $itemAttrName = $properties['level'] ? $properties['itemAttrName'] . '-' . $properties['level'] : $properties['itemAttrName'];
        if (!$fields = $this->getItems($properties['html'], '[' . $fieldAttrName . ']')) {
            return $properties;
        }
        $mediaLists = [];
        foreach ($fields as $field) {
            $fieldName = $field->getAttribute($fieldAttrName);
            $fieldHTML = $this->parser->getHTMLString($field);

            if ($fieldName === 'bg_img') {
                $fieldHTMLNew = $this->setBackgroundPlaceholder($field, $fieldName, $properties);
            } elseif ($fieldName === 'img') {
                $fieldHTMLNew = $this->setImgPlaceholder($field, $fieldName, $properties);
            } elseif (in_array($fieldName, ['video', 'audio', 'picture'])) {
                $fieldHTMLNew = $this->setMediaPlaceholder($field, $fieldName, $properties);
            } elseif (in_array($fieldName, ['list_images', 'list_pictures', 'list_audios', 'list_videos'])) {
                $k = isset($mediaLists[$fieldName]) ? count($mediaLists[$fieldName]) : 0;
                if ($fieldName === 'list_images') {
                    $fieldHTMLNew = $this->setImgPlaceholder($field, $fieldName . "[$k].img", $properties);
                } else {
                    $fieldHTMLNew = $this->setMediaPlaceholder($field, $fieldName . "[$k]." . $field->nodeName, $properties);
                }
                $mediaLists[$fieldName][] = $field;
            } elseif ($items = $this->getItems($fieldHTML, '[' . $itemAttrName . ']')) {
                list($firstSymbol, $complexName) = $this->getSymbolComplex($field, $fieldName, $properties['level'], $properties['isStatic']);
                $props['html'] = $this->parser->getHTMLString($items[0]);
                $props['element'] = $items[0];
                $props['level'] = $properties['level'] + 1;

                $props = $this->setPlaceholders(array_merge($properties, $props));
                $limit = $field->getAttribute('data-mpc-lim');
                $offset = $field->getAttribute('data-mpc-off');
                if ($limit && $offset) {
                    $sampleKey = 'foreach_limit_offset';
                } elseif ($limit && !$offset) {
                    $sampleKey = 'foreach_limit';
                } elseif (!$limit && $offset) {
                    $sampleKey = 'foreach_offset';
                } else {
                    $sampleKey = 'foreach';
                }
                $field->nodeValue = str_replace(['##', 'subject', '^', 'html', 'limit', 'offset'],
                    [$firstSymbol, $complexName, $props['level'], $props['html'], $limit, $offset],
                    $this->properties['samples'][$sampleKey]);

                $fieldHTMLNew = $this->parser->getHTMLString($field);
                if ($field->hasAttribute('data-mpc-if')) {
                    $condition = $field->getAttribute('data-mpc-if') ?: $complexName;
                    $fieldHTMLNew = $this->wrapInCondition($condition, $fieldHTMLNew, $firstSymbol);
                }
            } else {
                $fieldHTMLNew = $this->setDefaultPlaceholder($field, $fieldName, $properties);
            }

            if (!empty($fieldHTMLNew)) {
                //$this->modx->log(1, print_r([$fieldHTML, $fieldHTMLNew], 1));
                $properties['html'] = str_replace($fieldHTML, $fieldHTMLNew, $properties['html']);
            }
        }

        //$this->modx->log(1, print_r($properties['html'], 1));
        return $properties;
    }

    private function setBackgroundPlaceholder(\DOMElement $row, string $fieldName, array $properties): string
    {
        $html = '';
        if ($style = $row->getAttribute('style')) {
            list($firstSymbol, $complexName) = $this->getSymbolComplex($row, $fieldName, $properties['level'], $properties['isStatic']);

            if ($this->properties['lazyloadAttr'] && !$row->hasAttribute('data-mpc-nolazy')) {
                $row->setAttribute($this->properties['lazyloadAttr'], "{$firstSymbol}{$complexName}}");
                $row->removeAttribute('style');
            } else {
                $style = preg_replace('/url\(\'(.*?)\'\)/', "url('" . $firstSymbol . $complexName . "}')", $style);
                $row->setAttribute('style', $style);
            }

            $html = $this->parser->getHTMLString($row);
        }
        return $html;
    }

    private function setImgPlaceholder(\DOMElement $row, string $fieldName, array $properties)
    {
        list($firstSymbol, $complexName) = $this->getSymbolComplex($row, $fieldName, $properties['level'], $properties['isStatic']);
        $imgAttrs = ['width', 'height', 'alt'];

        if ($this->properties['lazyloadAttr'] && !$row->hasAttribute('data-mpc-nolazy')) {
            $row->setAttribute($this->properties['lazyloadAttr'], "{$firstSymbol}{$complexName}[0].src}");
            $row->setAttribute('src', $this->properties['fakeImgPath']);
        } else {
            $row->setAttribute('src', "{$firstSymbol}{$complexName}[0].src}");
        }

        foreach ($imgAttrs as $attr) {
            $row->setAttribute($attr, "{$firstSymbol}{$complexName}[0].{$attr}}");
        }

        $html = $this->parser->getHTMLString($row);
        if ($row->hasAttribute('data-mpc-if')) {
            $condition = $row->getAttribute('data-mpc-if');
            $html = $this->wrapInCondition($condition, $html, $firstSymbol);
        }
        return $html;
    }

    private function setMediaPlaceholder(\DOMElement $row, string $fieldName, array $properties)
    {
        $condition = $row->getAttribute('data-mpc-if');
        $pls = '';
        list($firstSymbol, $complexName) = $this->getSymbolComplex($row, $fieldName, $properties['level'], $properties['isStatic']);
        $complexName .= '[0]';
        if ($row->hasAttribute('src')) {
            if ($this->properties['lazyloadAttr'] && !$row->hasAttribute('data-mpc-nolazy')) {
                $row->setAttribute($this->properties['lazyloadAttr'], "{$firstSymbol}{$complexName}.src}");
                $row->removeAttribute('src');
            } else {
                $row->setAttribute('src', "{$firstSymbol}{$complexName}.src}");
            }
        }
        $row = $this->setAttributes($row, $firstSymbol, $complexName);
        $html = $this->parser->getHTMLString($row);

        $sources = $row->getElementsByTagName('source');
        if ($sources->length) {
            $source = $this->setAttributes($sources[$sources->length - 1], $firstSymbol, '$source');
            $search = ['##', 'complexName', 'html'];
            $replace = [$firstSymbol, $complexName];
            if ($this->properties['lazyloadAttr'] && !$row->hasAttribute('data-mpc-nolazy')) {
                $source->setAttribute($this->properties['lazyloadAttr'], "{$firstSymbol}{$complexName}.src}");
                $source->removeAttribute('src');
                $source->removeAttribute('srcset');
            }
            $sourceHtml = $this->parser->getHTMLString($source);
            $replace[] = str_replace('</source>', '', $sourceHtml);
            $pls .= str_replace($search, $replace, $this->properties['samples']['media']);
        }

        $images = $row->getElementsByTagName('img');
        if ($images->length) {
            $img = $this->setAttributes($images[$images->length - 1], $firstSymbol, $complexName);
            if ($this->properties['lazyloadAttr'] && !$row->hasAttribute('data-mpc-nolazy')) {
                $img->setAttribute($this->properties['lazyloadAttr'], "{$firstSymbol}{$complexName}.src}");
                $img->setAttribute('src', $this->properties['fakeImgPath']);
            }
            $pls .= $this->parser->getHTMLString($img);
        }

        if ($pls) {
            $html = preg_replace(
                '/<' . $row->nodeName . '(.*?)>(.*?)<\/' . $row->nodeName . '>/s',
                '<' . $row->nodeName . '\1>' . PHP_EOL . $pls . PHP_EOL . '</' . $row->nodeName . '>',
                $html
            );
        }

        if ($row->hasAttribute('data-mpc-if')) {
            $condition = $row->getAttribute('data-mpc-if') ?: $complexName;
            $html = $this->wrapInCondition($condition, $html, $firstSymbol);
        }
        return $html;
    }

    private function setAttributes(\DOMElement $row, string $firstSymbol, string $complexName): \DOMElement
    {
        $allowedAttrs = [
            'src',
            'srcset',
            'loop',
            'media',
            'type',
            'sizes',
            'autoplay',
            'controls',
            'preload',
            'muted',
            'height',
            'width',
            'poster',
        ];
        foreach ($row->attributes as $attr) {
            if (!in_array($attr->nodeName, $allowedAttrs)) {
                continue;
            }
            $row->setAttribute($attr->nodeName, "{$firstSymbol}{$complexName}.{$attr->nodeName}}");
        }
        return $row;
    }

    private function setDefaultPlaceholder(\DOMElement $row, string $fieldName, array $properties)
    {
        list($firstSymbol, $complexName) = $this->getSymbolComplex($row, $fieldName, $properties['level'], $properties['isStatic']);
        if ($row->hasAttribute('href')) {
            $row->setAttribute('href', "{$firstSymbol}{$complexName}}");
        } else {
            $row->nodeValue = "{$firstSymbol}{$complexName}}";
        }
        $html = $this->parser->getHTMLString($row);
        if ($row->hasAttribute('data-mpc-if')) {
            $condition = $row->getAttribute('data-mpc-if') ?: $complexName;
            $html = $this->wrapInCondition($condition, $html, $firstSymbol);
        }
        return $html;
    }

    private function getSymbolComplex(\DOMElement $row, string $fieldName, ?int $level = 0, ?bool $isStatic = false): array
    {
        $firstSymbol = $isStatic ? '##' : (trim($row->getAttribute('data-mpc-symbol')) ?: '{');
        $rid = (int)$row->getAttribute('data-mpc-rid') ?: '';
        $table = $row->getAttribute('data-mpc-table') ?: 'config';

        if ($table === 'config') {
            $complexName = $level > 0 ? "\$item{$level}.{$fieldName}" : "\${$fieldName}";
        } else {
            $complexName = "($rid | resource: '$fieldName')";
        }
        return [$firstSymbol, $complexName];
    }

    private function wrapInCondition(string $conditions, string $html, ?string $firstSymbol = '{')
    {
        return str_replace(['##', 'condition', 'html'], [$firstSymbol, $conditions, $html], $this->properties['samples']['if']);
    }

    /**
     * @param array $properties
     * @return array
     */
    private function setSnippetTags(array $properties): array
    {
        if (!$snippets = $this->getItems($properties['html'], '[data-mpc-snippet]')) {
            return $properties;
        }
        foreach ($snippets as $snippet) {
            $firstSymbol = trim($snippet->getAttribute('data-mpc-symbol')) ?: '##';
            if ($value = trim($snippet->getAttribute('data-mpc-snippet'))) {
                $call = $this->getSnippetCall($value, $firstSymbol);
                $snippetHTml = $this->parser->getHTMLString($snippet);

                if (!$snippet->hasAttribute('data-mpc-unwrap')) {
                    $snippet->nodeValue = $call;
                    $call = $this->parser->getHTMLString($snippet);
                }

                $properties['html'] = str_replace($snippetHTml, $call, $properties['html']);
            }
        }
        return $properties;
    }

    /**
     * @param string $value
     * @param string $firstSymbol
     * @return string
     */
    public function getSnippetCall(string $value, string $firstSymbol): string
    {
        $params = '';
        $value = explode('|', $value);
        $snippetName = $value[0];
        $presetKey = str_replace('!', '', strtolower($value[0]));
        $presetName = $value[1];
        if (isset($this->properties['presets'][$presetKey]) && isset($this->properties['presets'][$presetKey][$presetName])) {
            $preset = $this->properties['presets'][$presetKey][$presetName];
            if ($preset['extends']) {
                if (strpos($preset['extends'], '.') === false) {
                    $preset['extends'] = $presetKey . '.' . $preset['extends'];
                }
                $extendsPreset = $this->getExtends($preset['extends'], []);
                $preset = array_merge($extendsPreset, $preset);
                unset($preset['extends']);
            }

            foreach ($preset as $k => $v) {
                if (is_array($v)) {
                    $v = json_encode($v);
                    $v = str_replace('{', '{ ', $v);
                    $v = str_replace('##', '{', $v);
                }
                if (strpos($v, '#/') === 0) {
                    $v = str_replace('#/', '@FILE ' . $this->properties['pathToChunks'], $v);
                }

                if (strpos($v, '$') === 0 || strpos($v, '[') === 0 || strpos($v, '"') === 0) {
                    $params .= "'$k' => $v," . PHP_EOL;
                } else {
                    $params .= "'$k' => '$v'," . PHP_EOL;
                }

                if ($k == 'toPls') {
                    $firstSymbol = PHP_EOL . $firstSymbol . 'set $' . $v . ' = ';
                }
            }
        }


        if ($params) {
            $call = PHP_EOL . "$firstSymbol'$snippetName' | snippet: [
                        $params
                        ]}" . PHP_EOL;
        } else {
            $call = PHP_EOL . "$firstSymbol'$snippetName' | snippet: []}" . PHP_EOL;
        }
        return $call;
    }

    /**
     * @param $preset
     * @param $extends
     * @return array|mixed
     */
    private function getExtends($preset, $extends)
    {
        $preset = explode('.', $preset);
        $presetData = $this->properties['presets'][$preset[0]][$preset[1]];
        if ($presetData && is_array($presetData)) {
            $extends = array_merge($extends, $presetData);
            if ($presetData['extends']) {
                $extends = $this->getExtends($presetData['extends'], $extends);
            }
        }
        return $extends;
    }

    /**
     *
     * @param array $properties
     * @return array
     */
    private function setParseChunks(array $properties): array
    {
        if ($parses = $this->getItems($properties['html'], '[data-mpc-parse]')) {
            foreach ($parses as $parse) {
                $symbol = trim($parse->getAttribute('data-mpc-symbol')) ?: '##';
                $params = trim($parse->getAttribute('data-mpc-parse'));
                $path = $this->properties['pathToChunks'] . trim($parse->getAttribute('data-mpc-chunk'));
                $parseHtml = $this->parser->getHTMLString($parse);
                $parseHtmlNew = $symbol . '$_modx->parseChunk("@FILE ' . $path . '", ' . $params . ')}';
                $properties['html'] = str_replace($parseHtml, $parseHtmlNew, $properties['html']);
            }
        }
        return $properties;
    }

    /**
     * @param array $properties
     * @return array
     */
    private function setIncludeChunks(array $properties): array
    {
        if ($includes = $this->getItems($properties['html'], '[data-mpc-include]')) {
            foreach ($includes as $include) {
                $path = $this->properties['pathToChunks'] . trim($include->getAttribute('data-mpc-chunk'));
                $symbol = trim($include->getAttribute('data-mpc-symbol')) ?: '{';
                $includeHtml = $this->parser->getHTMLString($include);
                $includeHtmlNew = $symbol . 'include "file:' . $path . '"}';
                $properties['html'] = str_replace($includeHtml, $includeHtmlNew, $properties['html']);
            }
        }
        return $properties;
    }

    /**
     *
     * @param array $properties
     * @return array
     */
    private function removeHiddenPlaceholders(array $properties): array
    {
        if ($hiddenPls = $this->getItems($properties['html'], '[data-mpc-remove]')) {
            foreach ($hiddenPls as $hidden) {
                $hiddenHtml = $this->parser->getHTMLString($hidden);
                $properties['html'] = str_replace($hiddenHtml, '', $properties['html']);
            }
        }
        return $properties;
    }

}
