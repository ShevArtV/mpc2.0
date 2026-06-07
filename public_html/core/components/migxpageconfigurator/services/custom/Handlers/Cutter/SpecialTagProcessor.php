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

        foreach ($parses as $parse) {
            $chunk = trim((string)$parse->getAttribute('data-mpc-chunk'));
            if ($chunk === '' || $chunk[0] === '/' || strpos($chunk, '..') !== false || strpos($chunk, '"') !== false) {
                continue; // пустой / абсолютный путь / path traversal / поломка @FILE-строки (V5)
            }
            $symbol     = trim((string)$parse->getAttribute('data-mpc-symbol')) ?: (!empty($properties['isStatic']) ? '##' : '{');
            $params     = trim((string)$parse->getAttribute('data-mpc-parse'));
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

        foreach ($includes as $include) {
            $chunk = trim((string)$include->getAttribute('data-mpc-chunk'));
            if ($chunk === '' || $chunk[0] === '/' || strpos($chunk, '..') !== false || strpos($chunk, '"') !== false) {
                continue; // пустой / абсолютный путь / path traversal / поломка file:-пути (V5)
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

        return $properties;
    }
}
