<?php

require_once dirname(__FILE__) . '/mpcbasehandler.class.php';

/**
 *
 */
class MpcFilesHandler extends MpcBaseHandler
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
            'pathToChunks' => $this->modx->getOption('mpc_path_to_chunks', null, 'chunks/'),
            'commonConfigName' => $this->modx->getOption('mpc_common_config_name', null, 'config'),
            'baseSectionName' => $this->modx->getOption('mpc_base_section_name', null, 'base'),
            'sbpId' => (int)$this->modx->getOption('mpc_static_block_page_id', null, 1),
            'chunkNames' => [],
            'allowUserGroup' => explode(',', $this->modx->getOption('mpc_allow_user_group', '', 'Administrator')),
            'pattern' => '/(\s)*?data-mpc-(exlazy|sff|copy|symbol|form|preset|cond|static|name|item|unwrap|section|snippet|chunk|include|parse|remove|attr|field|content|table|rid)(-){0,1}([0-9]*)(=".*?"){0,1}(\s)*?/',
            'wrapperName' => $this->modx->getOption('mpc_wrapper_name', null, 'wrapper'),
            'pathToSrc' => $this->modx->getOption('mpc_path_to_src', null, 'elements/templates/'),
            'thumbFormat' => $this->modx->getOption('mpc_thumb_format', null, 'png'),
            'lazyloadAttr' => $this->modx->getOption('mpc_lazyload_attr', null, ''),
            'fakeImgPath' => $this->modx->getOption('mpc_fake_img_path', null, 'assets/components/migxpageconfigurator/images/fake-img.png'),
            'pathToPresets' => $this->modx->getOption('mpc_path_to_presets', null, 'components/migxpageconfigurator/elements/presets/'),
            'presets' => []

        ];
        $this->properties['corePath'] = str_replace('\\', '/', $this->properties['corePath']);
        $this->properties['pdotoolsElementsPath'] = str_replace($this->properties['corePath'], '', $this->properties['pdotoolsElementsPath']);
        $this->properties = array_merge($this->properties, $properties);
    }

    /**
     * @return array|object|string
     */
    public function handle()
    {
        if (empty($this->properties['html'])) {
            $this->modx->error->addError('[MpcFilesHandler::handle] Не передан HTML.');
            return $this->modx->error->failure('', $this->properties);
        }

        $this->getPresets();

        $sections = $this->findByAttribute($this->properties['html'], '[data-mpc-section]');
        if (empty($sections)) {
            $this->modx->error->addError('[MpcFilesHandler::handle] Не найдено ни одной секции.');
            return $this->modx->error->failure();
        }

        foreach ($sections as $section) {
            $isCopy = $section->hasAttribute('data-mpc-copy');
            if ($isCopy) continue;

            $innerChunks = $this->findByAttribute($this->getHTMLString($section), '[data-mpc-chunk]');
            if ($innerChunks->count()) {
                $this->parseInnerChunks($innerChunks);
            }

            // если секция НЕ является копией аналогичной из другого шаблона - создаем для неё файлы return !empty($newResourceData[$key][0]) ? count($newResourceData[$key]) : 0;
            $this->createSectionFiles($section);
            break;
        }

        return $this->modx->error->success();
    }

    /**
     * @return void
     */
    private function getPresets()
    {
        $pathToPresets = $this->properties['corePath'] . $this->properties['pathToPresets'];
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

    /**
     * @param DOMNodeList $innerChunks
     * @return void
     */
    private function parseInnerChunks(DOMNodeList $innerChunks)
    {
        foreach ($innerChunks as $innerChunk) {
            if (!property_exists($innerChunk, 'nodeValue')) continue;
            $chunkName = $innerChunk->getAttribute('data-mpc-chunk');

            if (in_array($chunkName, $this->properties['chunkNames'])) continue;
            $this->properties['chunkNames'][] = $chunkName;
            $subInnerChunks = $this->findByAttribute($this->getHTMLString($innerChunk), '[data-mpc-chunk]');
            if ($subInnerChunks->count()) {
                $this->parseInnerChunks($subInnerChunks);
            }

            $dirName = explode('/', $chunkName);

            if (count($dirName) > 1) {
                unset($dirName[count($dirName) - 1]);
                $dirName = implode('/', $dirName);
            }
            $baseDir = $this->properties['corePath'] . $this->properties['pdotoolsElementsPath'] . $this->properties['pathToChunks'];
            if (!is_dir($baseDir . $dirName)) {
                mkdir($baseDir . $dirName, 0777, true);
                $this->modx->log(1, $dirName);
            }
            $path = $baseDir . $chunkName;

            $this->putToFile($innerChunk, $path);
        }
    }

    /**
     * @param DOMElement $section
     * @return void
     */
    private function createSectionFiles(DOMElement $section)
    {
        $sectionName = trim($section->getAttribute('data-mpc-section'));
        $fileName = $sectionName . $this->properties['extension'];
        $pathToFile = $this->properties['corePath'] . $this->properties['pdotoolsElementsPath'] . $this->properties['pathToSections'] . $fileName;

        $this->putToFile($section, $pathToFile);
    }

    /**
     * @param DOMElement $element
     * @param string $pathToFile
     * @return void
     */
    private function putToFile(DOMElement $element, string $pathToFile)
    {
        $html = $this->getHTMLString($element);
        $properties = [
            'html' => $html,
            'element' => $element,
            'fieldAttrName' => 'data-mpc-field',
            'itemAttrName' => 'data-mpc-item',
            'level' => 0,
            'isStatic' => $element->hasAttribute('data-mpc-static')
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

        $attrs = $this->findByAttribute($properties['html'], '[data-mpc-attr]');
        if ($attrs->count()) {
            foreach ($attrs as $attr) {
                $attrValue = $attr->getAttribute('data-mpc-attr');
                $search = 'data-mpc-attr="' . $attrValue . '"';
                $properties['html'] = str_replace($search, $attrValue, $properties['html']);
            }
        }

        if (!$this->modx->user->isMember($this->properties['allowUserGroup'])) {
            $unwrap = $this->findByAttribute($properties['html'], '[data-mpc-unwrap]');
            if ($unwrap->count()) {
                foreach ($unwrap as $attr) {
                    $attrValue = '';
                    foreach ($attr->childNodes as $childNode) {
                        $attrValue .= $this->getHTMLString($childNode);
                    }
                    $search = $this->getHTMLString($attr);
                    $properties['html'] = str_replace($search, $attrValue, $properties['html']);
                }
            }
        }

        if ($element->getAttribute('data-mpc-section') === $this->properties['wrapperName']) {
            $fileName = $this->properties['wrapperName'] . $this->properties['extension'];
            $path_to_tpl = $this->properties['corePath'] . $this->properties['pathToSrc'] . $fileName;
            $content = file_get_contents($path_to_tpl);
            $properties['html'] = preg_replace('/<body(.*?)>(.*?)<\/body>/s', '<body\1>' . $properties['html'] . '</body>', $content);
        }

        $properties['html'] = str_replace('`', '"', $properties['html']);

        if (!$this->modx->user->isMember($this->properties['allowUserGroup'])) {
            $properties['html'] = preg_replace($this->properties['pattern'], '', $properties['html']);
        }
        file_put_contents($pathToFile, $properties['html']);
        //$this->modx->log(1, $result['html']);
    }

    /**
     * @param array $properties
     * @return array
     */
    private function setPlaceholders(array $properties): array
    {
        $fieldAttrName = $properties['level'] ? $properties['fieldAttrName'] . '-' . $properties['level'] : $properties['fieldAttrName'];
        $itemAttrName = $properties['level'] ? $properties['itemAttrName'] . '-' . $properties['level'] : $properties['itemAttrName'];
        $fields = $this->findByAttribute($properties['html'], '[' . $fieldAttrName . ']');
        $firstSymbol = $properties['isStatic'] ? '##' : '{';

        foreach ($fields as $field) {
            $table = $field->getAttribute('data-mpc-table');
            $fieldName = !$table ? $field->getAttribute($fieldAttrName) : "{$table}.{$field->getAttribute($fieldAttrName)}";
            $fieldHTML = $this->getHTMLString($field);
            $items = $this->findByAttribute($fieldHTML, '[' . $itemAttrName . ']');

            if ($items->count() && strpos($fieldName, 'list') !== false) {
                $props['html'] = $this->getHTMLString($items[0]);
                $props['element'] = $items[0];
                $props['level'] = $properties['level'] + 1;

                $props = $this->setPlaceholders(array_merge($properties, $props));
                $fieldHTMLNew = "{$firstSymbol}foreach \${$fieldName} as \$item{$props['level']} index=\$i last=\$l}" . PHP_EOL;
                $fieldHTMLNew .= $props['html'] . PHP_EOL;
                $fieldHTMLNew .= '{/foreach}' . PHP_EOL;
            } else {
                $fieldHTMLNew = $this->getPlaceholder($field, $fieldName, $firstSymbol, $properties['level']);
            }

            $properties['html'] = str_replace($fieldHTML, $fieldHTMLNew, $properties['html']);
        }

        return $properties;
    }

    /**
     * @param DOMElement $row
     * @param string $fieldName
     * @param string $firstSymbol
     * @param int $level
     * @return string
     */
    private function getPlaceholder(DOMElement $row, string $fieldName, string $firstSymbol = '{', int $level = 0): string
    {

        $complexName = $level > 0 ? "\$item{$level}.{$fieldName}" : "\${$fieldName}";

        if ($row->hasAttribute('src')) {
            if ($this->properties['lazyloadAttr'] && !$row->hasAttribute('data-mpc-exlazy')) {
                $row->setAttribute('src', trim($this->properties['fakeImgPath']));
                $row->setAttribute($this->properties['lazyloadAttr'], "{$firstSymbol}{$complexName}}");
            } else {
                $row->setAttribute('src', "{$firstSymbol}{$complexName}}");
            }
            return $this->getHTMLString($row);
        } elseif ($fieldName === 'picture' || strpos($fieldName, 'list_pictures') !== false) {
            return $this->getPicturePlaceholder($row, $complexName, $firstSymbol);
        } elseif ($row->hasAttribute('href')) {
            $row->setAttribute('href', "{$firstSymbol}{$complexName}}");
            return $this->getHTMLString($row);
        } elseif ($style = $row->getAttribute('style')) {
            if ($this->properties['lazyloadAttr'] && !$row->hasAttribute('data-mpc-exlazy')) {
                $style = preg_replace('/(background|background-image):(\s){0,}url\(\'.*?\'\)(;){0,}/', "", $style);
                $row->setAttribute($this->properties['lazyloadAttr'], "{$firstSymbol}{$complexName}}");
            } else {
                $style = preg_replace('/url\(\'(.*?)\'\)/', "url('" . $firstSymbol . $complexName . "}')", $style);
            }
            $row->setAttribute('style', $style);
            return $this->getHTMLString($row);
        } else {
            $row->nodeValue = "{$firstSymbol}{$complexName}}";
            return $this->getHTMLString($row);
        }
    }

    /**
     * @param $row
     * @param $complexName
     * @param $firstSymbol
     * @return string
     */
    private function getPicturePlaceholder($row, $complexName, $firstSymbol = '{'): string
    {
        $pls = PHP_EOL . "{$firstSymbol}foreach {$complexName} as \$source index=\$i last=\$l}" . PHP_EOL;
        $pls .= "{$firstSymbol}if \$i === 0}{$firstSymbol}set \$baseimg = \$source.srcset}{$firstSymbol}/if}" . PHP_EOL;
        $pls .= "<source srcset=\"{$firstSymbol}\$source.srcset}\" type=\"{$firstSymbol}\$source.type}\" media=\"{$firstSymbol}\$source.media}\">" . PHP_EOL;
        $pls .= "{$firstSymbol}/foreach}" . PHP_EOL;
        $pls .= "<img src=\"{$firstSymbol}\$baseimg}\" alt=\"\">" . PHP_EOL;
        $html = $this->getHTMLString($row);
        return preg_replace('/<picture(.*?)>(.*?)<\/picture>/s', '<picture\1>' . $pls . '</picture>', $html);
    }

    /**
     * @param array $properties
     * @return array
     */
    private function setSnippetTags(array $properties): array
    {
        $snippets = $this->findByAttribute($properties['html'], '[data-mpc-snippet]');
        foreach ($snippets as $snippet) {
            $firstSymbol = trim($snippet->getAttribute('data-mpc-symbol')) ?: '##';
            if ($value = trim($snippet->getAttribute('data-mpc-snippet'))) {
                $call = $this->getSnippetCall($value, $firstSymbol);
                $snippetHTml = $this->getHTMLString($snippet);

                if (!$snippet->hasAttribute('data-mpc-unwrap') || $this->modx->user->isMember($this->properties['allowUserGroup'])) {
                    $snippet->nodeValue = $call;
                    $call = $this->getHTMLString($snippet);
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
                $extendsPreset = $this->getExtends($preset['extends'], []);
                $preset = array_merge($extendsPreset, $preset);
            }

            foreach ($preset as $k => $v) {
                if ($k == 'toPls') {
                    $firstSymbol = $firstSymbol . 'set $' . $v . ' = ';
                }
                if (is_array($v)) {
                    $v = json_encode($v);
                    $v = str_replace('{', '{ ', $v);
                    $v = str_replace('##', '{', $v);
                }
                if (strpos($v, '#/') === 0) {
                    $v = str_replace('#/', '@FILE ' . $this->properties['pathToChunks'], $v);
                }

                if (strpos($v, '$') === 0 || strpos($v, '[') === 0 || strpos($v, '{$') === 0 || strpos($v, '"') === 0) {
                    $params .= "'$k' => $v," . PHP_EOL;
                } else {
                    $params .= "'$k' => '$v'," . PHP_EOL;
                }
            }
        }


        if ($params) {
            $call = "$firstSymbol'$snippetName' | snippet: [
                        $params]}";
        } else {
            $call = "$firstSymbol'$snippetName' | snippet: []}";
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
        $parses = $this->findByAttribute($properties['html'], '[data-mpc-parse]');
        if ($parses->count()) {
            foreach ($parses as $parse) {
                $symbol = trim($parse->getAttribute('data-mpc-symbol')) ?: '##';
                $params = trim($parse->getAttribute('data-mpc-parse'));
                $path = $this->properties['pathToChunks'] . trim($parse->getAttribute('data-mpc-chunk'));
                $parseHtml = $this->getHTMLString($parse);
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
        $includes = $this->findByAttribute($properties['html'], '[data-mpc-include]');
        if ($includes->count()) {
            foreach ($includes as $include) {
                $path = $this->properties['pathToChunks'] . trim($include->getAttribute('data-mpc-chunk'));
                $symbol = trim($include->getAttribute('data-mpc-symbol')) ?: '{';
                $includeHtml = $this->getHTMLString($include);
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
        $hiddenPls = $this->findByAttribute($properties['html'], '[data-mpc-remove]');
        if ($hiddenPls->count()) {
            foreach ($hiddenPls as $hidden) {
                $hiddenHtml = $this->getHTMLString($hidden);
                $properties['html'] = str_replace($hiddenHtml, '', $properties['html']);
            }
        }
        return $properties;
    }
}