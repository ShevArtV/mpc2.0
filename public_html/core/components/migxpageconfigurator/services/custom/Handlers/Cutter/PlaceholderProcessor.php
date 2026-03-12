<?php

namespace MpcServices\Handlers\Cutter;

use DiDom\Document;
use DiDom\Element;
use DiDom\Exceptions\InvalidSelectorException;
use MpcServices\Handlers\Parser;

/**
 * Преобразует data-mpc-field элементы в Fenom/PHP-плейсхолдеры.
 *
 * Ответственность:
 *  - setPlaceholders() — оркестратор: определяет тип поля и вызывает нужный метод
 *  - setImgPlaceholder(), setBackgroundPlaceholder(), setMediaPlaceholder(), setDefaultPlaceholder()
 *  - getSymbolComplex(), getThumb(), wrapInCondition(), unwrapBlock(), setAttributes()
 */
class PlaceholderProcessor
{
    private \modX  $modx;
    private array  $properties;
    private Parser $parser;

    public function __construct(\modX $modx, array $properties, Parser $parser)
    {
        $this->modx       = $modx;
        $this->properties = $properties;
        $this->parser     = $parser;
    }

    /**
     * Обходит все [data-mpc-field] в html и заменяет их на плейсхолдеры.
     * Рекурсивен: обрабатывает вложенные уровни через data-mpc-item.
     *
     * @return array Модифицированный $properties['html']
     * @throws InvalidSelectorException
     */
    public function setPlaceholders(array $properties): array
    {
        $fieldAttrName = $properties['level'] ? $properties['fieldAttrName'] . '-' . $properties['level'] : $properties['fieldAttrName'];
        $itemAttrName  = $properties['level'] ? $properties['itemAttrName']  . '-' . $properties['level'] : $properties['itemAttrName'];

        $fields = $this->findByAttr($properties['html'], '[' . $fieldAttrName . ']');
        if (!$fields) {
            return $properties;
        }

        $mediaLists = [];

        foreach ($fields as $field) {
            $fieldName    = $field->getAttribute($fieldAttrName);
            $properties['fieldName'] = $fieldName;
            $fieldHTML    = $this->parser->getHTMLString($field);

            if ($fieldName === 'bg_img') {
                $fieldHTMLNew = $this->setBackgroundPlaceholder($field, $fieldName, $properties);

            } elseif ($field->tagName() === 'img' && !in_array($fieldName, ['list_images', 'list_pictures'])) {
                $fieldHTMLNew = $this->setImgPlaceholder($field, $fieldName, $properties);

            } elseif (in_array($field->tagName(), ['video', 'audio', 'picture']) && !in_array($fieldName, ['list_pictures', 'list_audios', 'list_videos'])) {
                $fieldHTMLNew = $this->setMediaPlaceholder($field, $fieldName, $properties);

            } elseif (in_array($fieldName, ['list_images', 'list_pictures', 'list_audios', 'list_videos'])) {
                $parentNode = $this->getParentNode($properties['parentElement'] ?? $field, $fieldAttrName);
                if ($parentNode != $properties['element']) {
                    continue;
                }
                $prefix = '';
                if ($properties['parentElement'] ?? null) {
                    $prefix     = 'item' . $properties['level'] . '.';
                    $fieldName  = strpos($fieldName, $prefix) === 0 ? $fieldName : $prefix . $fieldName;
                }
                $k = isset($mediaLists[$fieldName]) ? count($mediaLists[$fieldName]) : 0;
                $listProperties = array_merge($properties, ['level' => $k]);

                if ($fieldName === $prefix . 'list_images') {
                    $fieldHTMLNew = $this->setImgPlaceholder($field, $fieldName . "[$k].img", $listProperties);
                } else {
                    $fieldHTMLNew = $this->setMediaPlaceholder($field, $fieldName . "[$k]." . $field->tagName(), $listProperties);
                }
                $mediaLists[$fieldName][] = $field;

            } elseif ($items = $this->findByAttr($this->parser->getHTMLString($field), '[' . $itemAttrName . ']')) {
                [$firstSymbol, $complexName] = $this->getSymbolComplex($field, $fieldName, $properties['level'], $properties['isStatic']);

                $props = [
                    'html'          => $this->parser->getHTMLString($items[0]),
                    'element'       => $items[0],
                    'parentElement' => $this->getParentNode($items[0], $fieldAttrName),
                    'level'         => $properties['level'] + 1,
                ];
                $properties['parentFieldName'] = $fieldName;
                $properties['fieldName']       = preg_replace('/^\$/', '', $complexName);

                $props      = $this->setPlaceholders(array_merge($properties, $props));
                $limit      = $field->getAttribute('data-mpc-lim');
                $offset     = $field->getAttribute('data-mpc-off');

                if ($limit && $offset) {
                    $sampleKey = 'foreach_limit_offset';
                } elseif ($limit) {
                    $sampleKey = 'foreach_limit';
                } elseif ($offset) {
                    $sampleKey = 'foreach_offset';
                } else {
                    $sampleKey = 'foreach';
                }

                $html = $this->unwrapBlock($props['html']);
                $fieldHTMLNew = str_replace(
                    ['##', 'subject', '^', 'html', 'limit', 'offset'],
                    [$firstSymbol, $complexName, $props['level'], $html, $limit, $offset],
                    $this->properties['samples'][$sampleKey]
                );

                if (!$field->hasAttribute('data-mpc-unwrap')) {
                    $field->setInnerHtml($fieldHTMLNew);
                    $fieldHTMLNew = $this->parser->getHTMLString($field);
                } else {
                    $fieldHTMLNew = str_replace(['</source>', '</path>'], '', $fieldHTMLNew);
                }

                if ($field->hasAttribute('data-mpc-if')) {
                    $condition    = $field->getAttribute('data-mpc-if') ?: $complexName;
                    $fieldHTMLNew = $this->wrapInCondition($condition, $fieldHTMLNew, $firstSymbol);
                }

            } else {
                $fieldHTMLNew = $this->setDefaultPlaceholder($field, $fieldName, $properties);
            }

            $this->modx->invokeEvent('mpcOnGetNewHtml', [
                'fieldHTMLNew' => $fieldHTMLNew,
                'Grabber'      => $this,
            ]);

            $fieldHTMLNew = isset($this->modx->event->returnedValues) && !empty($this->modx->event->returnedValues['fieldHTMLNew'])
                ? $this->modx->event->returnedValues['fieldHTMLNew']
                : $fieldHTMLNew;

            if (!empty($fieldHTMLNew)) {
                $properties['html'] = str_replace($fieldHTML, $fieldHTMLNew, $properties['html']);
            }
        }

        return $properties;
    }

    // ---------------------------------------------------------------
    // Методы замены по типу поля
    // ---------------------------------------------------------------

    public function setBackgroundPlaceholder(Element $row, string $fieldName, array $properties): string
    {
        $style = $row->getAttribute('style');
        if (!$style) {
            return '';
        }

        [$firstSymbol, $complexName] = $this->getSymbolComplex($row, $fieldName, $properties['level'], $properties['isStatic']);

        preg_match('/width:(.*?);/', $style, $width);
        preg_match('/height:(.*?);/', $style, $height);

        if (!$row->hasAttribute('data-mpc-nothumb') && !empty($this->properties['thumbSnippet'])) {
            $src = $this->getThumb([
                'width'       => (int)($width[1] ?? 0),
                'height'      => (int)($height[1] ?? 0),
                'thumbParams' => $row->getAttribute('data-mpc-thumb'),
                'firstSymbol' => $firstSymbol,
                'complexName' => $complexName,
                'srcAttr'     => '',
                'setValues'   => true,
            ]);
        } else {
            $src = "{$firstSymbol}{$complexName}}";
        }

        if ($this->properties['lazyloadAttr'] && !$row->hasAttribute('data-mpc-nolazy')) {
            $row->setAttribute($this->properties['lazyloadAttr'], 'bg:' . $src);
            $row->removeAttribute('style');
        } else {
            $style = preg_replace('/url\(\'(.*?)\'\)/', "url('" . $src . "')", $style);
            $row->setAttribute('style', $style);
        }

        return $this->parser->getHTMLString($row);
    }

    public function setImgPlaceholder(Element $row, string $fieldName, array $properties): string
    {
        [$firstSymbol, $complexName] = $this->getSymbolComplex($row, $fieldName, $properties['level'], $properties['isStatic']);
        $complexName .= '[0]';
        $prefix = "{$firstSymbol}{$complexName}";

        if (!$row->hasAttribute('data-mpc-nothumb') && !empty($this->properties['thumbSnippet'])) {
            $src = $this->getThumb([
                'width'       => $row->hasAttribute('width'),
                'height'      => $row->hasAttribute('height'),
                'thumbParams' => $row->getAttribute('data-mpc-thumb'),
                'firstSymbol' => $firstSymbol,
                'complexName' => $complexName,
                'srcAttr'     => 'src',
            ]);
        } else {
            $src = "$prefix.src}";
        }

        if ($this->properties['lazyloadAttr'] && !$row->hasAttribute('data-mpc-nolazy')) {
            $row->setAttribute($this->properties['lazyloadAttr'], $src);
            $row->setAttribute('src', $this->properties['fakeImgPath']);
        } else {
            $row->setAttribute('src', $src);
        }

        foreach (['width', 'height', 'alt'] as $attr) {
            if ($row->hasAttribute($attr)) {
                $row->setAttribute($attr, "$prefix.{$attr}}");
            }
        }

        $html = $this->parser->getHTMLString($row);
        if ($row->hasAttribute('data-mpc-if')) {
            $condition = $row->getAttribute('data-mpc-if') ?: $complexName;
            $html = $this->wrapInCondition($condition, $html, $firstSymbol);
        }
        return $html;
    }

    public function setMediaPlaceholder(Element $row, string $fieldName, array $properties): string
    {
        $pls = '';
        [$firstSymbol, $complexName] = $this->getSymbolComplex($row, $fieldName, $properties['level'], $properties['isStatic']);
        $complexName .= '[0]';

        if ($row->hasAttribute('src')) {
            if ($this->properties['lazyloadAttr'] && !$row->hasAttribute('data-mpc-nolazy')) {
                $row->setAttribute($this->properties['lazyloadAttr'], "{$firstSymbol}{$complexName}.src}");
                $row->removeAttribute('src');
            } else {
                $row->setAttribute('src', "{$firstSymbol}{$complexName}.src}");
            }
        }

        $row  = $this->setAttributes($row, $firstSymbol, $complexName);
        $html = $this->parser->getHTMLString($row);

        $sources = $row->find('source');
        if (count($sources) > 0) {
            $k      = count($sources) - 1;
            $source = $this->setAttributes($sources[$k], $firstSymbol, '$source');
            $search  = ['##', 'complexName', 'html'];
            $replace = [$firstSymbol, $complexName];

            if ($this->properties['lazyloadAttr'] && !$row->hasAttribute('data-mpc-nolazy')) {
                if ($row->tagName() === 'picture') {
                    $attr = 'srcset';
                    if (!$source->hasAttribute('data-mpc-nothumb') && !empty($this->properties['thumbSnippet'])) {
                        $src = $this->getThumb([
                            'width'       => $source->hasAttribute('width'),
                            'height'      => $source->hasAttribute('height'),
                            'thumbParams' => $source->getAttribute('data-mpc-thumb'),
                            'firstSymbol' => $firstSymbol,
                            'complexName' => '$source',
                            'srcAttr'     => 'srcset',
                            'isLoopVar'   => true,
                        ]);
                    } else {
                        $src = "{$firstSymbol}\$source.$attr}";
                    }
                } else {
                    $attr = 'src';
                    $src  = "{$firstSymbol}\$source.$attr}";
                }
                $attr = $row->tagName() === 'picture' ? 'srcset' : 'src';
                $source->setAttribute($this->properties['lazyloadAttr'], $src);
                $source->removeAttribute($attr);
            }

            $sourceHtml = $this->parser->getHTMLString($source);
            $replace[]  = str_replace('</source>', '', $sourceHtml);
            $pls       .= str_replace($search, $replace, $this->properties['samples']['media']);
        }

        $images = $row->find('img');
        if (count($images) > 0) {
            $img = $this->setAttributes($images[count($images) - 1], $firstSymbol, $complexName . '.img[0]');
            if (!$img->hasAttribute('data-mpc-nothumb') && !empty($this->properties['thumbSnippet'])) {
                $src = $this->getThumb([
                    'width'       => $img->hasAttribute('width'),
                    'height'      => $img->hasAttribute('height'),
                    'thumbParams' => $img->getAttribute('data-mpc-thumb'),
                    'firstSymbol' => $firstSymbol,
                    'complexName' => $complexName . '.img[0]',
                    'srcAttr'     => 'src',
                ]);
            } else {
                $src = "{$firstSymbol}{$complexName}.img[0].src}";
            }

            if ($this->properties['lazyloadAttr'] && !$row->hasAttribute('data-mpc-nolazy')) {
                $img->setAttribute($this->properties['lazyloadAttr'], $src);
                $img->setAttribute('src', $this->properties['fakeImgPath']);
            }
            $pls .= $this->parser->getHTMLString($img);
        }

        if ($pls) {
            $html = preg_replace(
                '/<' . $row->tagName() . '(.*?)>(.*?)<\/' . $row->tagName() . '>/s',
                '<' . $row->tagName() . '\1>' . PHP_EOL . $pls . PHP_EOL . '</' . $row->tagName() . '>',
                $html
            );
        }

        if ($row->hasAttribute('data-mpc-if')) {
            $condition = $row->getAttribute('data-mpc-if') ?: $complexName;
            $html = $this->wrapInCondition($condition, $html, $firstSymbol);
        }

        return $html;
    }

    public function setDefaultPlaceholder(Element $row, string $fieldName, array $properties): string
    {
        [$firstSymbol, $complexName] = $this->getSymbolComplex($row, $fieldName, $properties['level'], $properties['isStatic']);

        if ($row->hasAttribute('href')) {
            $pls = (int)$row->getAttribute('href')
                ? "{$firstSymbol}{$complexName} | url}"
                : "{$firstSymbol}{$complexName}}";
            $row->setAttribute('href', $pls);
        } else {
            $row->setInnerHtml("$firstSymbol$complexName}");
        }

        $html = $this->parser->getHTMLString($row);
        if ($row->hasAttribute('data-mpc-if')) {
            $condition = $row->getAttribute('data-mpc-if') ?: $complexName;
            $html = $this->wrapInCondition($condition, $html, $firstSymbol);
        }
        return $html;
    }

    // ---------------------------------------------------------------
    // Вспомогательные методы (большинство чистые функции)
    // ---------------------------------------------------------------

    /**
     * Возвращает [firstSymbol, complexName] для поля.
     * PURE: не меняет состояние.
     */
    public function getSymbolComplex(Element $row, string $fieldName, ?int $level = 0, ?bool $isStatic = false): array
    {
        $firstSymbol = $isStatic ? '##' : (trim((string)$row->getAttribute('data-mpc-symbol')) ?: '{');
        $rid   = (int)$row->getAttribute('data-mpc-rid') ?: '';
        $table = $row->getAttribute('data-mpc-table') ?: 'config';

        if ($table === 'config') {
            if (preg_match('/^list_(images|pictures|videos|audios)/', $fieldName)) {
                $complexName = "\${$fieldName}";
            } else {
                $complexName = $level > 0 ? "\$item{$level}.{$fieldName}" : "\${$fieldName}";
            }
        } else {
            $complexName = "($rid | resource: '$fieldName')";
        }

        return [$firstSymbol, $complexName];
    }

    /**
     * Генерирует код вызова thumb-сниппета.
     * PURE: не меняет состояние.
     */
    public function getThumb(array $params): string
    {
        $snippetName = $this->properties['thumbSnippet'];
        $thumbParams = $params['thumbParams'] ?: $this->properties['commonThumbParams'];
        $src         = $params['srcAttr'] ? "{$params['complexName']}.{$params['srcAttr']}" : $params['complexName'];

        // isLoopVar=true означает, что complexName — это переменная foreach-цикла (например $source),
        // которая существует только во время отдачи страницы внутри {foreach}. Для статичных секций
        // такие переменные НЕ определены во время Render::parseChunk, поэтому оборачивать их в {…}
        // нельзя — Fenom вычислит тег в пустую строку. Вместо этого оставляем их как простые
        // выражения Fenom, которые вычисляются внутри вызова {pThumb | snippet:…} при отдаче страницы.
        $isLoopVarInStaticCtx = ($params['isLoopVar'] ?? false) && ($params['firstSymbol'] === '##');
        $isLexiconImage = $this->properties['useLexicons']
            && in_array('image', $this->properties['translatableContentTypes']);

        // Для {if}-обёртки запоминаем исходное выражение $src до оборачивания в {…}
        $rawSrc = $src;

        if ($params['width']) {
            $width = $params['complexName'] . '.width';
            if ($isLexiconImage && !$isLoopVarInStaticCtx) {
                $width = '{' . $params['complexName'] . '.width}';
            }
            $pls = ($params['setValues'] ?? false) ? $params['width'] : "'~$width" . ($params['height'] ? "~'" : '');
            $thumbParams .= "&w=$pls";
        }

        if ($params['height']) {
            $height = $params['complexName'] . '.height';
            if ($isLexiconImage && !$isLoopVarInStaticCtx) {
                $height = '{' . $params['complexName'] . '.height}';
            }
            $pls = ($params['setValues'] ?? false) ? $params['height'] : "'~$height";
            $thumbParams .= "&h=$pls";
        }

        if (!($params['height'] ?? false) && !($params['width'] ?? false)) {
            $thumbParams .= "'";
        }

        if ($isLexiconImage) {
            if (!$isLoopVarInStaticCtx) {
                $src = '{' . $src . '}';
            }
            $params['firstSymbol'] = '##';
        }

        $call = "{$params['firstSymbol']}'$snippetName' | snippet: [ 'input' => $src, 'options' => '{$thumbParams}]}";

        // Для статичных секций с лексиконами: {$var} вычисляется при предпарсинге,
        // а ##snippet — отложен до финального рендера. Если $var пуст, получается
        // 'input' => , — синтаксическая ошибка Fenom. Оборачиваем весь вызов в {if},
        // которое вычисляется на том же этапе предпарсинга — если пусто, вызов удаляется целиком.
        if ($isLexiconImage && !$isLoopVarInStaticCtx) {
            $call = '{if ' . $rawSrc . '}' . $call . '{/if}';
        }

        return $call;
    }

    /**
     * Оборачивает HTML в Fenom-условие {if}.
     * PURE: не меняет состояние.
     */
    public function wrapInCondition(string $conditions, string $html, ?string $firstSymbol = '{'): string
    {
        return str_replace(
            ['##', 'condition', 'html'],
            [$firstSymbol, $conditions, $html],
            $this->properties['samples']['if']
        );
    }

    /**
     * Удаляет обёртки [data-mpc-unwrap], оставляя только содержимое.
     */
    public function unwrapBlock(string $html): string
    {
        if ($unwrap = $this->parser->findByAttribute($html, '[data-mpc-unwrap]')) {
            foreach ($unwrap as $attr) {
                $attrValue = $this->parser->getHTMLString($attr, true);
                $search    = $this->parser->getHTMLString($attr);
                $html      = str_replace($search, $attrValue, $html);
            }
        }
        return $html;
    }

    /**
     * Устанавливает Fenom-плейсхолдеры для разрешённых атрибутов медиа-элемента.
     */
    public function setAttributes(Element $row, string $firstSymbol, string $complexName): Element
    {
        $allowedAttrs = ['src', 'srcset', 'loop', 'media', 'type', 'sizes', 'autoplay', 'controls', 'preload', 'muted', 'height', 'width', 'poster', 'alt'];

        foreach ($allowedAttrs as $attrName) {
            if ($row->hasAttribute($attrName)) {
                $row->setAttribute($attrName, "{$firstSymbol}{$complexName}.{$attrName}}");
            }
        }

        return $row;
    }

    // ---------------------------------------------------------------
    // Приватные вспомогательные
    // ---------------------------------------------------------------

    private function findByAttr(string $html, string $selector): ?array
    {
        if (empty($html)) {
            return null;
        }
        $items = $this->parser->findByAttribute($html, $selector);
        return count($items) ? $items : null;
    }

    private function getParentNode($node, string $attrName)
    {
        if ($node->parent() instanceof Document) {
            return $node->parent();
        }
        if ($node->parent()->hasAttribute($attrName)) {
            return $node->parent();
        }
        if ($node->parent()->hasAttribute('data-mpc-section')) {
            return $node->parent();
        }
        return $this->getParentNode($node->parent(), $attrName);
    }
}
