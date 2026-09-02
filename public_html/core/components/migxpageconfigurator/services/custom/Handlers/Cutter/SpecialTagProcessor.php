<?php

namespace MpcServices\Handlers\Cutter;

use DiDom\Exceptions\InvalidSelectorException;
use MpcServices\Handlers\Parser;

/**
 * Обрабатывает специальные теги: сниппеты, чанки, скрытые элементы.
 *
 * Ответственность:
 *  - data-mpc-snippet → Fenom-вызов сниппета
 *  - data-mpc-parse   → $_modx->parseChunk(...)
 *  - data-mpc-include → {include "file:..."}
 *  - data-mpc-remove  → удаление элемента из HTML
 */
class SpecialTagProcessor
{
    /** Открывающий тег с атрибутом data-mpc-remove — для текстового фолбэка удаления. */
    private const OPEN_TAG_RE = '/<([a-z][a-z0-9-]*)[^<>]*\sdata-mpc-remove(?=[\s>=\/])/i';
    private const CUT_LIMIT   = 200;
    private const VOID_TAGS   = [
        'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input',
        'link', 'meta', 'param', 'source', 'track', 'wbr',
    ];

    private array                $properties;
    private Parser               $parser;
    private SnippetCallBuilder   $snippetCallBuilder;
    private PlaceholderProcessor $placeholderProcessor;

    public function __construct(
        array                $properties,
        Parser               $parser,
        SnippetCallBuilder   $snippetCallBuilder,
        PlaceholderProcessor $placeholderProcessor
    ) {
        $this->properties           = $properties;
        $this->parser               = $parser;
        $this->snippetCallBuilder   = $snippetCallBuilder;
        $this->placeholderProcessor = $placeholderProcessor;
    }

    /**
     * Заменяет [data-mpc-snippet] на вызов сниппета.
     *
     * @throws InvalidSelectorException
     */
    public function setSnippetTags(array $properties): array
    {
        $snippets = $this->parser->findByAttribute($properties['html'], '[data-mpc-snippet]');
        if (!$snippets) {
            return $properties;
        }

        foreach ($snippets as $snippet) {
            $defaultSymbol = !empty($properties['isStatic']) ? '##' : '{';
            $firstSymbol = trim((string)$snippet->getAttribute('data-mpc-symbol')) ?: $defaultSymbol;
            $value = trim((string)$snippet->getAttribute('data-mpc-snippet'));

            if (!$value) {
                continue;
            }

            $call        = $this->snippetCallBuilder->getSnippetCall($value, $firstSymbol);
            $snippetHtml = $this->parser->getHTMLString($snippet);

            if (!$snippet->hasAttribute('data-mpc-unwrap')) {
                $snippet->setInnerHtml($call);
                $call = $this->parser->getHTMLString($snippet);
            }

            if ($snippet->hasAttribute('data-mpc-if')) {
                $condition = $snippet->getAttribute('data-mpc-if') ?: $call;
                $call = $this->placeholderProcessor->wrapInCondition($condition, $call, $firstSymbol);
            }

            $properties['html'] = str_replace($snippetHtml, $call, $properties['html']);
        }

        return $properties;
    }

    /**
     * Заменяет [data-mpc-parse] на $_modx->parseChunk(...).
     *
     * @throws InvalidSelectorException
     */
    public function setParseChunks(array $properties): array
    {
        $parses = $this->parser->findByAttribute($properties['html'], '[data-mpc-parse]');
        if (!$parses) {
            return $properties;
        }

        // Имя чанка ТЕКУЩЕГО файла: его корневой элемент сам несёт data-mpc-parse и
        // попадает в выборку — заменять его нельзя (self-reference). Вложенные чанки
        // (другое имя) обрабатываем.
        $self = isset($properties['element']) ? trim((string)$properties['element']->getAttribute('data-mpc-chunk')) : '';

        foreach ($parses as $parse) {
            $chunk = trim((string)$parse->getAttribute('data-mpc-chunk'));
            if ($chunk === '' || $chunk[0] === '/' || strpos($chunk, '..') !== false || strpos($chunk, '"') !== false) {
                continue; // пустой / абсолютный путь / path traversal / поломка @FILE-строки (V5)
            }
            if ($self !== '' && $chunk === $self) {
                continue; // сам себя не парсим
            }
            $symbol     = trim((string)$parse->getAttribute('data-mpc-symbol')) ?: (!empty($properties['isStatic']) ? '##' : '{');
            $params     = trim((string)$parse->getAttribute('data-mpc-parse'));
            // $params вставляется в {$_modx->parseChunk("...", $params)} — `}` закрыл
            // бы Fenom-тег и дал инъекцию. Легитимный массив-литерал `}` не содержит;
            // есть → подменяем пустым массивом (defense поверх trust каттера, V5).
            if (strpos($params, '}') !== false) {
                $params = '[]';
            }
            $path       = $this->properties['pathToChunks'] . $chunk;
            $parseHtml  = $this->parser->getHTMLString($parse);
            $parseHtmlNew = $symbol . '$_modx->parseChunk("@FILE ' . $path . '", ' . $params . ')}';

            $properties['html'] = str_replace($parseHtml, $parseHtmlNew, $properties['html']);
        }

        return $properties;
    }

    /**
     * Заменяет [data-mpc-include] на {include "file:..."}.
     *
     * @throws InvalidSelectorException
     */
    public function setIncludeChunks(array $properties): array
    {
        $includes = $this->parser->findByAttribute($properties['html'], '[data-mpc-include]');
        if (!$includes) {
            return $properties;
        }

        // Имя чанка ТЕКУЩЕГО файла: его корневой элемент сам несёт data-mpc-include и
        // попадает в выборку — заменять его нельзя (self-reference). Вложенные чанки
        // (другое имя) обрабатываем.
        $self = isset($properties['element']) ? trim((string)$properties['element']->getAttribute('data-mpc-chunk')) : '';

        foreach ($includes as $include) {
            $chunk = trim((string)$include->getAttribute('data-mpc-chunk'));
            if ($chunk === '' || $chunk[0] === '/' || strpos($chunk, '..') !== false || strpos($chunk, '"') !== false) {
                continue; // пустой / абсолютный путь / path traversal / поломка file:-пути (V5)
            }
            if ($self !== '' && $chunk === $self) {
                continue; // сам себя не инклюдим
            }
            $path        = $this->properties['pathToChunks'] . $chunk;
            $symbol      = trim((string)$include->getAttribute('data-mpc-symbol')) ?: '{';
            $includeHtml = $this->parser->getHTMLString($include);
            $includeHtmlNew = $symbol . 'include "file:' . $path . '"}';

            $properties['html'] = str_replace($includeHtml, $includeHtmlNew, $properties['html']);
        }

        return $properties;
    }

    /**
     * Удаляет [data-mpc-remove] элементы из HTML.
     *
     * @throws InvalidSelectorException
     */
    public function removeHiddenPlaceholders(array $properties): array
    {
        $hiddenPls = $this->parser->findByAttribute($properties['html'], '[data-mpc-remove]');
        if (!$hiddenPls) {
            return $properties;
        }

        foreach ($hiddenPls as $hidden) {
            $hiddenHtml = $this->parser->getHTMLString($hidden);
            $properties['html'] = str_replace($hiddenHtml, '', $properties['html']);
        }

        // Удаление выше — это str_replace по сериализации DiDom, а к этому моменту
        // предыдущие проходы (setPlaceholders) уже вписали Fenom прямо в позицию
        // имени тега: `<source{if $source.media} media="..."{/if} ...>`. DiDom при
        // ре-сериализации выбрасывает такой мусорный «атрибут», строка расходится
        // с исходной, и замена промахивается — узел-объявление уезжает на фронт.
        // Тот же класс дефекта описан у unwrapBlock в SectionFileWriter::putToFile.
        // Фолбэк вырезает уцелевшие узлы по самому тексту, без парсера.
        if (preg_match(self::OPEN_TAG_RE, $properties['html'])) {
            $properties['html'] = $this->cutHiddenByText($properties['html']);
        }

        return $properties;
    }

    /**
     * Текстовое вырезание уцелевших [data-mpc-remove] узлов.
     *
     * От открывающего тега до парного закрывающего с учётом вложенности одноимённых
     * тегов. Не тронет узел, у которого не нашлось пары, — лучше оставить как есть,
     * чем срезать половину секции. Ищется именно открывающий тег, а не подстрока:
     * имя атрибута встречается и в комментариях вёрстки, а там резать нечего.
     */
    private function cutHiddenByText(string $html): string
    {
        $guard = 0;
        while (preg_match(self::OPEN_TAG_RE, $html, $m, PREG_OFFSET_CAPTURE) && ++$guard <= self::CUT_LIMIT) {
            $openStart = $m[0][1];
            $openEnd   = strpos($html, '>', $openStart + strlen($m[0][0]) - 1);
            if ($openEnd === false) {
                break;
            }

            $tag = strtolower($m[1][0]);
            $end = $openEnd + 1;

            if (!in_array($tag, self::VOID_TAGS, true) && substr($html, $openEnd - 1, 1) !== '/') {
                $depth   = 1;
                $offset  = $end;
                $pattern = '/<(\/?)' . preg_quote($tag, '/') . '\b/i';

                while ($depth > 0 && preg_match($pattern, $html, $found, PREG_OFFSET_CAPTURE, $offset)) {
                    $depth += $found[1][0] === '/' ? -1 : 1;
                    $offset = $found[0][1] + strlen($found[0][0]);
                }

                if ($depth !== 0) {
                    break;
                }

                $closeEnd = strpos($html, '>', $offset);
                if ($closeEnd === false) {
                    break;
                }

                $end = $closeEnd + 1;
            }

            $html = substr($html, 0, $openStart) . substr($html, $end);
        }

        return $html;
    }
}
