<?php

namespace MpcServices\Handlers\Cutter;

use DiDom\Document;
use DiDom\Element;
use DiDom\Exceptions\InvalidSelectorException;
use MpcServices\Handlers\Grabber\LexiconManager;
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
    /**
     * HTML-булевы атрибуты медиа: включены самим ФАКТОМ присутствия
     * (`controls="0"` всё равно включает). Подстановкой значения их не выключить
     * — рендерим условно: `{if $rec.controls}controls{/if}`. Каттер ставит
     * на них сентинел в setAttributes, renderBoolAttrs превращает его в {if}.
     */
    private const BOOL_ATTRS = ['controls', 'autoplay', 'loop', 'muted'];
    private const BOOL_SENTINEL = '@@MPCBOOL@@';

    private \modX $modx;
    private array $properties;
    private Parser $parser;
    private LexiconManager $lexiconManager;

    public function __construct(\modX $modx, array $properties, Parser $parser, LexiconManager $lexiconManager)
    {
        $this->modx = $modx;
        $this->properties = $properties;
        $this->parser = $parser;
        $this->lexiconManager = $lexiconManager;
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
        $itemAttrName = $properties['level'] ? $properties['itemAttrName'] . '-' . $properties['level'] : $properties['itemAttrName'];

        $this->assertNoMisplacedFields($properties['html'], $fieldAttrName, $itemAttrName, $properties);

        $fields = $this->findByAttr($properties['html'], '[' . $fieldAttrName . ']');
        if (!$fields) {
            return $properties;
        }

        $mediaLists = [];
        foreach ($fields as $field) {
            $fieldName = $field->getAttribute($fieldAttrName);
            $properties['fieldName'] = $fieldName;
            $fieldHTML = $this->parser->getHTMLString($field);

            if ($fieldName === 'bg_img') {
                $fieldHTMLNew = $this->setBackgroundPlaceholder($field, $fieldName, $properties);
            } elseif ($field->tagName() === 'img' && !in_array($fieldName, ['list_images', 'list_pictures'])) {
                $fieldHTMLNew = $this->setImgPlaceholder($field, $fieldName, $properties);
            } elseif (in_array($field->tagName(), ['video', 'audio', 'picture']) && !in_array($fieldName, ['list_pictures', 'list_audios', 'list_videos'])) {
                $fieldHTMLNew = $this->setMediaPlaceholder($field, $fieldName, $properties);
            } elseif (in_array($fieldName, ['list_images', 'list_pictures', 'list_audios', 'list_videos'])) {
                // Медиа-список обрабатываем, только если он — поле текущего
                // scope, а не вложен в ДРУГОЙ field-элемент того же уровня.
                // Если ближайший предок несёт тот же field-атрибут — список
                // принадлежит ему, не нам → пропускаем. Вложенность в item
                // (`data-mpc-item`) границей не считается: list_images внутри
                // элемента списка — легитимный кейс.
                $parentNode = $this->getParentNode($field, $fieldAttrName);
                if ($parentNode instanceof Element && $this->elementHasAttribute($parentNode, $fieldAttrName)) {
                    continue;
                }
                // Индекс элемента списка кодируется в имени как [$k]. Префикс
                // $itemN для вложенных списков навешивает getSymbolComplex по
                // фактическому уровню вложенности ($properties['level']) —
                // ручной item-префикс здесь НЕ добавляем, иначе получится
                // двойной $itemN.$itemN.
                $k = isset($mediaLists[$fieldName]) ? count($mediaLists[$fieldName]) : 0;

                if ($fieldName === 'list_images') {
                    $fieldHTMLNew = $this->setImgPlaceholder($field, $fieldName . "[$k].img", $properties);
                } else {
                    $fieldHTMLNew = $this->setMediaPlaceholder($field, $fieldName . "[$k]." . $field->tagName(), $properties);
                }
                $mediaLists[$fieldName][] = $field;
            } elseif ($items = $this->findByAttr($this->parser->getHTMLString($field), '[' . $itemAttrName . ']')) {
                [$firstSymbol, $complexName] = $this->getSymbolComplex($field, $fieldName, $properties['level'], $properties['isStatic']);

                $props = [
                    'html' => $this->parser->getHTMLString($items[0]),
                    'element' => $items[0],
                    'level' => $properties['level'] + 1,
                ];
                // Накапливаем parentFieldName по образцу grabber'а (без idx —
                // cutter работает на уровне схемы, row-indexes не известны).
                // Это нужно, чтобы exclusion-паттерны, заданные на накопленном
                // пути (`compare_list_compare_product`, `*_picture` и т.п.),
                // корректно сматчились на cutter-стороне.
                $savedParent = $properties['parentFieldName'] ?? '';
                $savedFieldName = $properties['fieldName'] ?? '';
                $properties['parentFieldName'] = $savedParent !== ''
                    ? "{$savedParent}_{$fieldName}"
                    : $fieldName;
                $properties['fieldName'] = preg_replace('/^\$/', '', $complexName);

                $props = $this->setPlaceholders(array_merge($properties, $props));

                // Восстанавливаем родительский контекст: иначе следующая
                // итерация foreach увидит накопленный parent от предыдущего
                // фольда. Например, после `list_triple_picture` (parent →
                // `list_triple_picture`) шёл `list_simple` — parent становился
                // `list_triple_picture_list_simple`, и fullPath для content
                // был `list_triple_picture_list_simple_content`, что матчил
                // pattern `list_triple*content*` и ложно исключал content.
                $properties['parentFieldName'] = $savedParent;
                $properties['fieldName'] = $savedFieldName;
                $limit = $field->getAttribute('data-mpc-lim');
                $offset = $field->getAttribute('data-mpc-off');

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
                    $condition = $field->getAttribute('data-mpc-if') ?: $complexName;
                    $fieldHTMLNew = $this->wrapInCondition($condition, $fieldHTMLNew, $firstSymbol);
                }
            } else {
                $fieldHTMLNew = $this->setDefaultPlaceholder($field, $fieldName, $properties);
            }

            $this->modx->invokeEvent('mpcOnGetNewHtml', [
                'fieldHTMLNew' => $fieldHTMLNew,
                'Grabber' => $this,
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

        $parentFieldName = $properties['parentFieldName'] ?? '';
        $deferVar = $this->deferLexiconVar($properties);

        if (!$row->hasAttribute('data-mpc-nothumb') && !empty($this->properties['thumbSnippet'])) {
            $src = $this->getThumb([
                'width' => (int)($width[1] ?? 0),
                'height' => (int)($height[1] ?? 0),
                'thumbParams' => $row->getAttribute('data-mpc-thumb'),
                'firstSymbol' => $firstSymbol,
                'complexName' => $complexName,
                'srcAttr' => '',
                'setValues' => true,
                'useLexicon' => $this->shouldLexiconize('image', $fieldName, $parentFieldName),
                'deferVar' => $deferVar,
            ]);
        } else {
            $src = $this->lex($firstSymbol, $complexName, 'image', $fieldName, $parentFieldName, $deferVar);
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
        $parentFieldName = $properties['parentFieldName'] ?? '';
        $deferVar = $this->deferLexiconVar($properties);

        if (!$row->hasAttribute('data-mpc-nothumb') && !empty($this->properties['thumbSnippet'])) {
            $src = $this->getThumb([
                'width' => $row->hasAttribute('width'),
                'height' => $row->hasAttribute('height'),
                'thumbParams' => $row->getAttribute('data-mpc-thumb'),
                'firstSymbol' => $firstSymbol,
                'complexName' => $complexName,
                'srcAttr' => 'src',
                'useLexicon' => $this->shouldLexiconize('image', $fieldName, $parentFieldName),
                'deferVar' => $deferVar,
            ]);
        } else {
            $src = $this->lex($firstSymbol, "$complexName.src", 'image', $fieldName, $parentFieldName, $deferVar);
        }

        if ($this->properties['lazyloadAttr'] && !$row->hasAttribute('data-mpc-nolazy')) {
            $row->setAttribute($this->properties['lazyloadAttr'], $src);
            $row->setAttribute('src', $this->properties['fakeImgPath']);
        } else {
            $row->setAttribute('src', $src);
        }

        // width/height — не локализуются; alt/title — `text` (грабер пишет ключ
        // с суффиксом _alt / _title).
        $attrSpec = [
            'width'  => null,
            'height' => null,
            'alt'    => ['contentType' => 'text', 'fieldName' => $fieldName . '_alt'],
            'title'  => ['contentType' => 'text', 'fieldName' => $fieldName . '_title'],
        ];
        foreach ($attrSpec as $attr => $spec) {
            if (!$row->hasAttribute($attr)) {
                continue;
            }
            if ($spec && $this->shouldLexiconize($spec['contentType'], $spec['fieldName'], $parentFieldName)) {
                $row->setAttribute($attr, $this->lex($firstSymbol, "$complexName.$attr", $spec['contentType'], $spec['fieldName'], $parentFieldName, $deferVar));
            } else {
                $row->setAttribute($attr, "{$firstSymbol}{$complexName}.{$attr}}");
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
        $parentFieldName = $properties['parentFieldName'] ?? '';
        $deferVar = $this->deferLexiconVar($properties);

        $tag = $row->tagName();
        // content-type для атрибутов главного элемента: video/audio/picture
        $mainContentType = $tag === 'picture' ? 'image' : $tag;

        // Накопленный «путь» для poster/source — повторяет grabber'овский
        // `LexiconManager::getLexiconKey` style (без idx, который cutter не знает).
        $accumulatedParent = $parentFieldName !== ''
            ? "{$parentFieldName}_{$fieldName}"
            : $fieldName;

        // src главного элемента (video/audio).
        if ($row->hasAttribute('src')) {
            $srcExpr = $this->lex(
                $firstSymbol,
                "$complexName.src",
                $mainContentType,
                $fieldName,
                $parentFieldName,
                $deferVar
            );
            if ($this->properties['lazyloadAttr'] && !$row->hasAttribute('data-mpc-nolazy')) {
                $row->setAttribute($this->properties['lazyloadAttr'], $srcExpr);
                $row->removeAttribute('src');
            } else {
                $row->setAttribute('src', $srcExpr);
            }
        }

        // Остальные атрибуты главного элемента (poster, alt, и т.д.).
        // src включаем сюда тоже — если он остался на элементе (не-lazyload путь),
        // `setAttributes` иначе перетирает его обычным `{$expr}` поверх нашего
        // `lex()`-выставленного значения. В lazyload-случае src уже удалён, и
        // setAttributes его не трогает.
        $mainLexiconAttrs = $tag === 'picture'
            ? []  // picture сам по себе не имеет локализуемых атрибутов
            : [
                'src'    => $this->shouldLexiconize($mainContentType, $fieldName, $parentFieldName),
                'poster' => $this->shouldLexiconize('poster', 'poster', $accumulatedParent),
                'alt'    => $this->shouldLexiconize('text', $fieldName . '_alt', $parentFieldName),
                'title'  => $this->shouldLexiconize('text', $fieldName . '_title', $parentFieldName),
            ];

        $row = $this->setAttributes($row, $firstSymbol, $complexName, $mainLexiconAttrs, $deferVar);
        $html = $this->parser->getHTMLString($row);

        $sources = $row->find('source');
        if (count($sources) > 0) {
            $k = count($sources) - 1;
            // <source> внутри picture → атрибут srcset, content-type 'image';
            // <source> внутри video/audio → атрибут src, content-type как у тега-родителя.
            $sourceAttr = $tag === 'picture' ? 'srcset' : 'src';
            $sourceContentType = $tag === 'picture' ? 'image' : $tag;
            // parentFieldName для source:
            // - picture: грабер `getPictureValue` перезаписывает
            //   `$options['parentFieldName']` на picture's fieldName БЕЗ
            //   grandparent → fullPath для source = `picture_source` (а не
            //   `<grandparent>_picture_source`). Pattern `picture_*` его матчит.
            // - video/audio: грабер `getMediaValue` использует
            //   `getParentFieldName`, который аккумулирует с grandparent
            //   (как наш $accumulatedParent).
            $sourceParent = $tag === 'picture' ? $fieldName : $accumulatedParent;
            $useSourceLexicon = $this->shouldLexiconize($sourceContentType, 'source', $sourceParent);
            $source = $this->setAttributes($sources[$k], $firstSymbol, '$source', [$sourceAttr => $useSourceLexicon], $deferVar);
            $search = ['##', 'complexName', 'html'];
            $replace = [$firstSymbol, $complexName];

            if ($this->properties['lazyloadAttr'] && !$row->hasAttribute('data-mpc-nolazy')) {
                if ($tag === 'picture' && !$source->hasAttribute('data-mpc-nothumb') && !empty($this->properties['thumbSnippet'])) {
                    $src = $this->getThumb([
                        'width' => $source->hasAttribute('width'),
                        'height' => $source->hasAttribute('height'),
                        'thumbParams' => $source->getAttribute('data-mpc-thumb'),
                        'firstSymbol' => $firstSymbol,
                        'complexName' => '$source',
                        'srcAttr' => $sourceAttr,
                        'useLexicon' => $useSourceLexicon,
                        'deferVar' => $deferVar,
                    ]);
                } else {
                    $src = $this->lex($firstSymbol, "\$source.$sourceAttr", $sourceContentType, 'source', $sourceParent, $deferVar);
                }
                $source->setAttribute($this->properties['lazyloadAttr'], $src);
                $source->removeAttribute($sourceAttr);
            }

            $sourceHtml = $this->parser->getHTMLString($source);
            $replace[] = str_replace('</source>', '', $sourceHtml);
            $pls .= str_replace($search, $replace, $this->properties['samples']['media']);
        }

        $images = $row->find('img');
        if (count($images) > 0) {
            // <img> внутри <picture> — content-type 'image' для src, 'text' для alt.
            // Грабер вызывает getImageValue с исходным options (fieldName = picture's field).
            $imgFieldName = $fieldName;
            $imgLexAttrs = [
                'src'   => $this->shouldLexiconize('image', $imgFieldName, $parentFieldName),
                'alt'   => $this->shouldLexiconize('text', $imgFieldName . '_alt', $parentFieldName),
                'title' => $this->shouldLexiconize('text', $imgFieldName . '_title', $parentFieldName),
            ];
            $img = $this->setAttributes(
                $images[count($images) - 1],
                $firstSymbol,
                $complexName . '.img[0]',
                $imgLexAttrs,
                $deferVar
            );
            if (!$img->hasAttribute('data-mpc-nothumb') && !empty($this->properties['thumbSnippet'])) {
                $src = $this->getThumb([
                    'width' => $img->hasAttribute('width'),
                    'height' => $img->hasAttribute('height'),
                    'thumbParams' => $img->getAttribute('data-mpc-thumb'),
                    'firstSymbol' => $firstSymbol,
                    'complexName' => $complexName . '.img[0]',
                    'srcAttr' => 'src',
                    'useLexicon' => $imgLexAttrs['src'],
                    'deferVar' => $deferVar,
                ]);
            } else {
                $src = $this->lex($firstSymbol, "$complexName.img[0].src", 'image', $imgFieldName, $parentFieldName, $deferVar);
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

        // Сентинелы булевых атрибутов главного элемента → условный рендер {if}.
        // Только main video/audio несёт controls/autoplay/loop/muted; source/img
        // их не имеют, поэтому конверсия по main complexName покрывает всё.
        $html = $this->renderBoolAttrs($html, $firstSymbol, $complexName);

        return $html;
    }

    public function setDefaultPlaceholder(Element $row, string $fieldName, array $properties): string
    {
        [$firstSymbol, $complexName] = $this->getSymbolComplex($row, $fieldName, $properties['level'], $properties['isStatic']);
        $parentFieldName = $properties['parentFieldName'] ?? '';
        $deferVar = $this->deferLexiconVar($properties);

        $listValues = (string)$row->getAttribute('data-mpc-values');
        if ($row->hasAttribute('href')) {
            // href — это URL/id ресурса. Не локализуется.
            $pls = (int)$row->getAttribute('href')
                ? "{$firstSymbol}{$complexName} | url}"
                : "{$firstSymbol}{$complexName}}";
            $row->setAttribute('href', $pls);
        } elseif ($listValues !== '') {
            // Атрибут переписываем в keyed-нормализованную форму "Caption==norm(value)":
            // фронт-редактор берёт опции из data-mpc-values в DOM, и ему нужны
            // НОРМАЛИЗОВАННЫЕ значения (иначе правка Case B «Собака||Кошка» сохраняла
            // бы сырое «Собака» мимо ключа лексикона `_sobaka`). @SELECT не трогаем.
            $listValues = LexiconManager::normalizeInputOptionValues($listValues);
            // @SELECT: резолвим [[+PREFIX]]/[[+DBASE]] в реальные значения прямо в
            // атрибуте — иначе MODX гасит эти плейсхолдеры на рендере, и фронт-редактор
            // получает SQL без префикса таблицы (`FROM site_content`) → 0 опций.
            // Атрибут читает только редактор (fields/options), config с [[+PREFIX]]
            // для migx не трогаем.
            if (strpos($listValues, '[[+') !== false) {
                $listValues = str_replace(
                    ['[[+PREFIX]]', '[[+DBASE]]'],
                    [(string)$this->modx->getOption('table_prefix'), (string)$this->modx->getOption('dbname')],
                    $listValues
                );
            }
            $row->setAttribute('data-mpc-values', $listValues);
            // listbox: значение — ключ опции, не переводимый текст. Плейсхолдер —
            // listboxPlaceholder (лексикон по {prefix}_{field}_{$value} либо сырое
            // {$value}, симметрично грабу).
            $row->setInnerHtml($this->listboxPlaceholder(
                $row, $fieldName, $firstSymbol, $complexName, $parentFieldName, $deferVar, $listValues
            ));
        } else {
            // Внутренний текст элемента — content-type `text`.
            $row->setInnerHtml($this->lex($firstSymbol, $complexName, 'text', $fieldName, $parentFieldName, $deferVar));
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
        $rid = (int)$row->getAttribute('data-mpc-rid') ?: '';
        $table = $row->getAttribute('data-mpc-table') ?: 'config';

        if ($table === 'config') {
            // Уровень вложенности задаёт префикс $itemN. Имена медиа-списков
            // (list_images[$k].img и т.п.) идут той же дорогой: на top-level
            // (level 0) → $list_images[..], внутри элемента списка → $itemN.list_images[..].
            $complexName = $level > 0 ? "\$item{$level}.{$fieldName}" : "\${$fieldName}";
        } else {
            $complexName = "($rid | resource: '$fieldName')";
        }

        return [$firstSymbol, $complexName];
    }

    /**
     * Генерирует код вызова thumb-сниппета.
     * PURE: не меняет состояние.
     *
     * Параметр `useLexicon` (bool): когда поле локализуется (см. lex()), весь
     * сниппет-вызов откладывается до final-пасса (`firstSymbol = '##'`), а
     * `input` оборачивается в `('{$expr}' | lexicon)` — тот же приём, что в
     * lex(): pdoTools-интерполяция `'{$expr}'` баком ключ литералом на
     * eager-пассе, лексикон-модификатор резолвит на final-пассе. Размеры
     * тоже баковатся литералом через `{$expr}` (на final-пассе `$item` не в
     * скоупе для нестатичных секций).
     *
     * Параметр `deferVar` (bool): static + foreach (см. {@see deferLexiconVar}).
     * Тогда обёртывающий `##foreach}` отложен, и eager-интерполяция `{$expr}`
     * вычислила бы `$itemN` мимо скоупа. Откладываем переменную целиком:
     * `($expr | lexicon)` и размеры через bare `$expr` без `{...}`.
     */
    public function getThumb(array $params): string
    {
        $snippetName = $this->properties['thumbSnippet'];
        $thumbParams = $params['thumbParams'] ?: $this->properties['commonThumbParams'];
        $useLexicon = !empty($params['useLexicon']);
        $deferVar = !empty($params['deferVar']);
        $src = $params['srcAttr'] ? "{$params['complexName']}.{$params['srcAttr']}" : $params['complexName'];

        if ($params['width']) {
            $width = ($useLexicon && !$deferVar)
                ? '{' . $params['complexName'] . '.width}'
                : $params['complexName'] . '.width';
            $pls = ($params['setValues'] ?? false) ? $params['width'] : "'~$width" . ($params['height'] ? "~'" : '');
            $thumbParams .= "&w=$pls";
        }

        if ($params['height']) {
            $height = ($useLexicon && !$deferVar)
                ? '{' . $params['complexName'] . '.height}'
                : $params['complexName'] . '.height';
            $pls = ($params['setValues'] ?? false) ? $params['height'] : "'~$height";
            $thumbParams .= "&h=$pls";
        }

        if (!($params['height'] ?? false) && !($params['width'] ?? false)) {
            $thumbParams .= "'";
        }

        if ($useLexicon) {
            $src = $deferVar ? "($src | lexicon)" : "('{" . $src . "}' | lexicon)";
            $params['firstSymbol'] = '##';
        }

        return "{$params['firstSymbol']}'$snippetName' | snippet: [ 'input' => $src, 'options' => '{$thumbParams}]}";
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
                $search = $this->parser->getHTMLString($attr);
                $html = str_replace($search, $attrValue, $html);
            }
        }
        return $html;
    }

    /**
     * Устанавливает Fenom-плейсхолдеры для разрешённых атрибутов медиа-элемента.
     *
     * `$lexiconAttrs`: маппинг `attrName => bool` — `true` означает «применить
     * `| lexicon` к этому атрибуту». Caller вычисляет решение через
     * `shouldLexiconize($contentType, $fieldName, $parentFieldName)` с
     * правильным контекстом (имя поля, накопленный родитель).
     * Атрибуты, отсутствующие в карте или со значением `false`, выводятся
     * обычным `{$expr}`.
     *
     * `$deferVar` (bool): static + foreach (см. {@see deferLexiconVar}) —
     * откладываем переменную целиком `##$expr | lexicon}` вместо eager-
     * интерполяции `##'{$expr}' | lexicon}`, иначе `$itemN`/`$source`
     * вычислится мимо скоупа отложенного `##foreach}`.
     */
    public function setAttributes(Element $row, string $firstSymbol, string $complexName, array $lexiconAttrs = [], bool $deferVar = false): Element
    {
        $allowedAttrs = ['src', 'srcset', 'loop', 'media', 'type', 'sizes', 'autoplay', 'controls', 'preload', 'muted', 'height', 'width', 'poster', 'alt', 'title'];

        foreach ($allowedAttrs as $attrName) {
            if ($row->hasAttribute($attrName)) {
                // Булев атрибут (controls/autoplay/loop/muted): помечаем сентинелом,
                // renderBoolAttrs обернёт его в {if} (условный рендер вместо
                // подстановки значения — иначе `controls="0"` не выключается).
                if (in_array($attrName, self::BOOL_ATTRS, true)) {
                    $row->setAttribute($attrName, self::BOOL_SENTINEL);
                } elseif (empty($lexiconAttrs[$attrName])) {
                    $row->setAttribute($attrName, "{$firstSymbol}{$complexName}.{$attrName}}");
                } elseif ($deferVar) {
                    $row->setAttribute($attrName, "##" . "$complexName.$attrName" . " | lexicon}");
                } else {
                    $row->setAttribute($attrName, "##'{" . "$complexName.$attrName" . "}' | lexicon}");
                }
            }
        }

        return $row;
    }

    /**
     * Превращает сентинел булевых атрибутов в условный Fenom-рендер:
     * `controls="@@MPCBOOL@@"` → `{if $rec.controls}controls{/if}` (firstSymbol:
     * `{` для обычных секций, `##` для static — резолвится в `{` на final-пассе).
     * Так булев атрибут выводится ТОЛЬКО при truthy-значении в конфиге; `0` —
     * не выводится (HTML-boolean нельзя выключить значением). PURE.
     */
    private function renderBoolAttrs(string $html, string $firstSymbol, string $complexName): string
    {
        $pattern = '/\s+(' . implode('|', self::BOOL_ATTRS) . ')="' . preg_quote(self::BOOL_SENTINEL, '/') . '"/';
        $out = preg_replace_callback($pattern, function (array $m) use ($firstSymbol, $complexName) {
            $attr = $m[1];
            return ' ' . $firstSymbol . 'if ' . $complexName . '.' . $attr . '}' . $attr . $firstSymbol . '/if}';
        }, $html);
        return $out ?? $html;
    }

    // ---------------------------------------------------------------
    // Приватные вспомогательные
    // ---------------------------------------------------------------

    /**
     * Устанавливает контекст секции для решений о лексиконах.
     *
     * Префикс ({@see LexiconManager::setContext}) нужен, чтобы exclude-проверка
     * в `shouldLexiconize` видела полный lex-ключ с префиксом
     * (`{prefix}_..._field`) — симметрично граберу. Префикс: `data-mpc-lexicon`,
     * иначе fallback на `data-mpc-section` (как Grabber/SectionProcessor).
     * Вызывается из `Cutter::handleSections` ДО обработки секции и её вложенных
     * чанков, чтобы поля чанков тоже наследовали префикс секции (грабер
     * граблит чанки в DOM секции под её префиксом). Для cutter-флоу
     * static-wipe в setContext — no-op (`lexicons[$rid]` пуст).
     */
    public function setSectionContext(Element $section): void
    {
        $prefix = trim((string)$section->getAttribute('data-mpc-lexicon'))
            ?: trim((string)$section->getAttribute('data-mpc-section'));
        $this->lexiconManager->setContext($prefix, $section->hasAttribute('data-mpc-static'));
    }

    /**
     * Должно ли поле быть лексиконизировано с учётом content-type и
     * exclusion-паттернов. Делегирует в `LexiconManager::shouldLexiconize`.
     */
    private function shouldLexiconize(string $contentType, string $fieldName, string $parentFieldName = ''): bool
    {
        return $this->lexiconManager->shouldLexiconize($contentType, $fieldName, $parentFieldName);
    }

    /**
     * Нужно ли откладывать саму переменную лексиконного выражения целиком
     * (без eager-интерполяции `'{$expr}'`).
     *
     * Истинно для **статичных секций внутри foreach** (`isStatic && level > 0`):
     * там обёртывающий `##foreach}` тоже отложен (firstSymbol = `##`), поэтому
     * на eager-пассе loop-переменная `$itemN` ещё НЕ в скоупе. Если в такой
     * ситуации эмитить `##'{$itemN.field}' | lexicon}`, eager-пасс
     * интерполирует внутренний `{$itemN.field}` мимо скоупа → пусто →
     * `{'' | lexicon}` в parsed/. Нужно отложить всё выражение: `##$itemN.field
     * | lexicon}` → после `##→{` → `{$itemN.field | lexicon}`, резолвится когда
     * отложенный foreach реально итерирует.
     *
     * Для нестатичных секций foreach исполняется на eager-пассе ({foreach}),
     * `$itemN` в скоупе, и eager-интерполяция нужна (значение запекается, пока
     * loop-переменная жива) — поэтому возвращаем false.
     * PURE: не меняет состояние.
     */
    private function deferLexiconVar(array $properties): bool
    {
        return !empty($properties['isStatic']) && ($properties['level'] ?? 0) > 0;
    }

    /**
     * Собирает Fenom-плейсхолдер для выражения, добавляя `| lexicon` если
     * поле указанного content-type локализуется и не попадает под
     * exclusion-паттерны.
     *
     * Для нелексиконных — `{$expr}` (или `##$expr}` если firstSymbol = ##).
     *
     * Для лексиконных по умолчанию — `##'{$expr}' | lexicon}`. Логика:
     * лексикон-файл ресурса подгружается **позже** eager-пасса parseChunk, и
     * `{$expr | lexicon}` на eager-пассе вернёт пусто (переопределённый
     * модификатор отдаёт `''` для отсутствующих ключей). Решение — отложить
     * вычисление лексикона до final-пасса через `##`. Внутренний `'{$expr}'`
     * интерполируется на eager-пассе по pdoTools-конвенции (одинарные кавычки
     * сохраняются, `{$expr}` подменяется значением): получаем `##'key' |
     * lexicon}`, после `##→{` — `{'key' | lexicon}`, лексикон резолвится на
     * final-пассе.
     *
     * При `$deferVar = true` (static + foreach, см. {@see deferLexiconVar})
     * откладывается сама переменная: `##$expr | lexicon}` — без eager-
     * интерполяции, иначе `$itemN` вычислится мимо скоупа отложенного foreach.
     */
    private function lex(string $firstSymbol, string $expr, string $contentType, string $fieldName, string $parentFieldName = '', bool $deferVar = false): string
    {
        if (!$this->shouldLexiconize($contentType, $fieldName, $parentFieldName)) {
            return "{$firstSymbol}{$expr}}";
        }
        if ($deferVar) {
            return "##" . $expr . " | lexicon}";
        }
        return "##'{" . $expr . "}' | lexicon}";
    }

    /**
     * Плейсхолдер listbox-значения. Если список keyed ("Caption==key||..."),
     * текущее значение элемента входит в список ключей и лексиконы включены —
     * ключ {prefix}_{field}_{$value}: `##'{prefix}_{field}_{$value}' | lexicon}`
     * (капшены опций пишет грабер, см. LexiconManager::writeListboxOptions). Иначе
     * (неключевой список / значение не из списка / лексиконы off) — сырое значение
     * `{$value}`. Решение симметрично грабу (оба видят элемент и значение).
     */
    private function listboxPlaceholder(Element $row, string $fieldName, string $firstSymbol, string $expr, string $parentFieldName, bool $deferVar, string $rawValues): string
    {
        $parsed    = LexiconManager::classifyListboxOptions($rawValues);
        $dynamic   = $parsed['mode'] === 'dynamic';
        $keyPrefix = $this->lexiconManager->getSectionPrefix() . '_' . $fieldName . '_';

        // МУЛЬТИВЫБОР: значение — массив ключей. Лексиконы вкл (keyed/list + поле
        // лексиконится) → вывод через foreach (каждый ключ → капшен по лексикону,
        // ключи запекаются eager, `| lexicon` отложен); иначе → join ключей.
        if (LexiconManager::isMultiOptionFtype((string)$row->getAttribute('data-mpc-ftype'))) {
            // Значение — строка нормализованных ключей через "||". Итерируем через split.
            $lex = !$dynamic && $this->shouldLexiconize('text', $fieldName, $parentFieldName);
            return $lex
                ? $this->optionPlaceholder($keyPrefix, $expr, true)
                : "{$firstSymbol}{$expr} | split: '||' | join: ','}";
        }

        // ОДИНОЧНЫЙ listbox/option: ключ {prefix}_{field}_{$value} | lexicon, если
        // значение из списка опций и лексиконы вкл; иначе сырое {$value}. Значение
        // в БД уже нормализовано (FieldValueExtractor), сравниваем по norm.
        $current = LexiconManager::normalizeOptionKey(trim($row->innerHtml()));
        $lexize  = !$dynamic
            && $this->shouldLexiconize('text', $fieldName, $parentFieldName)
            && in_array($current, array_column($parsed['options'], 'value'), true);
        if (!$lexize) {
            return "{$firstSymbol}{$expr}}";
        }
        if ($deferVar) {
            return "##'" . $keyPrefix . "' ~ " . $expr . " | lexicon}";
        }
        return $this->optionPlaceholder($keyPrefix, $expr, false);
    }

    /**
     * ЕДИНЫЙ плейсхолдер капшена опции (секции и TV — один вид рендера, разница лишь
     * в $keyPrefix: <section>_<field>_ vs mpc_resource_tv_<tv>_). Одиночный — ключ
     * {prefix}{$value} | lexicon; мультивыбор — foreach по "||" с join ", ".
     */
    public function optionPlaceholder(string $keyPrefix, string $valueExpr, bool $multiple): string
    {
        if ($multiple) {
            return '{set $mpcItems = ' . $valueExpr . ' | split: \'||\'}'
                 . '{foreach $mpcItems as $item}'
                 . '##(\'' . $keyPrefix . '{$item}\') | lexicon}'
                 . '{if !$item@last}, {/if}{/foreach}';
        }
        return "##'" . $keyPrefix . "{" . $valueExpr . "}' | lexicon}";
    }

    private function findByAttr(string $html, string $selector): ?array
    {
        if (empty($html)) {
            return null;
        }
        $items = $this->parser->findByAttribute($html, $selector);
        return count($items) ? $items : null;
    }

    /**
     * Проверяет, что внутри элементов списка (`data-mpc-item[-N]`) поля несут
     * атрибут уровня вложенности (`data-mpc-field-{N+1}`), а не родительский
     * `data-mpc-field[-N]`.
     *
     * Зачем: bare `data-mpc-field` внутри `data-mpc-item` — частая ошибка
     * вёрстки. На грабере это даёт плоские ключи + пустой список, а на каттере
     * валится непрозрачным `DiDom\Element::hasAttribute() must be of type bool,
     * null returned` (libxml на части PHP отдаёт null для node такого
     * перемещённого элемента) в `setPlaceholders`. Детект делаем CSS-селектором
     * `[item] [field]` (XPath, без хрупкого hasAttribute), имя поля достаём из
     * сериализованного HTML регуляркой (getAttribute на проблемном node тоже
     * мог бы вернуть null). Кидаем понятную ошибку ДО обработки полей.
     */
    private function assertNoMisplacedFields(string $html, string $fieldAttrName, string $itemAttrName, array $properties): void
    {
        if (empty($html)) {
            return;
        }
        $offenders = $this->findByAttr($html, '[' . $itemAttrName . '] [' . $fieldAttrName . ']');
        if (!$offenders) {
            return;
        }

        $names = [];
        foreach ($offenders as $el) {
            $chunk = $this->parser->getHTMLString($el);
            if (preg_match('/' . preg_quote($fieldAttrName, '/') . '="([^"]*)"/', $chunk, $m)) {
                $names[] = $m[1];
            }
        }
        $names = array_values(array_unique(array_filter($names)));

        $expected = $properties['fieldAttrName'] . '-' . ($properties['level'] + 1);
        throw new \RuntimeException(sprintf(
            'MPC-разметка: внутри `%s` поля используют `%s`%s — поля элемента списка должны нести `%s`. Замените `%s` на `%s`.',
            $itemAttrName,
            $fieldAttrName,
            $names ? ' (поля: ' . implode(', ', $names) . ')' : '',
            $expected,
            $fieldAttrName,
            $expected
        ));
    }

    private function getParentNode($node, string $attrName)
    {
        // parent() → Element|Document|null. Не-Element (Document или конец
        // дерева) — выше осмысленного родителя нет, отдаём как есть; вызывающий
        // код проверяет `instanceof Element`. Раньше при parent()===null был бы
        // фатал на ->hasAttribute(), а на «перемещённом» libxml-node
        // hasAttribute мог вернуть null → TypeError в DiDom-обёртке.
        $parent = $node->parent();
        if (!$parent instanceof Element) {
            return $parent;
        }
        if ($this->elementHasAttribute($parent, $attrName)
            || $this->elementHasAttribute($parent, 'data-mpc-section')) {
            return $parent;
        }
        return $this->getParentNode($parent, $attrName);
    }

    /**
     * Безопасная проверка наличия атрибута: гард по DOMElement + приведение к
     * bool. На части PHP/libxml `DOMElement::hasAttribute()` для проблемных node
     * возвращает null, из-за чего DiDom-обёртка с `: bool` бросала TypeError.
     */
    private function elementHasAttribute(Element $el, string $name): bool
    {
        $node = $el->getNode();
        return $node instanceof \DOMElement && (bool) $node->hasAttribute($name);
    }
}
