<?php

namespace MpcServices\Handlers\Grabber;

use DiDom\Element;
use MpcServices\Handlers\Parser;

/**
 * Грабит data-mpc-rfield / data-mpc-tv из HTML и пишет значения в поля/TV ресурса.
 *
 * Используется на нарезке: значения из разметки становятся данными уровня type
 * (ресурс-тип), которые при рендере перекрывает конкретный контент-ресурс
 * (приоритет resource > type).
 *
 * Overwrite всех полей, КРОМЕ защищённых: alias/uri/template (по требованию)
 * + структурный минимум (id/class_key/context_key/parent/uri_override) — чтобы
 * разметка не сломала дерево/класс ресурса. Список настраивается.
 *
 * Извлечение значения чистое (DiDom), запись — через resource->set/setTVValue.
 */
class ResourceFieldGrabber
{
    private Parser $parser;

    /** @var string[] */
    private array $protected;

    public function __construct(Parser $parser, array $protectedFields = [])
    {
        $this->parser = $parser;
        $this->protected = $protectedFields ?: [
            'id', 'class_key', 'context_key', 'parent', 'uri_override',
            'alias', 'uri', 'template',
        ];
    }

    /**
     * @param string $html
     * @param object $resource modResource (или стаб с set/setTVValue)
     * @return array ['fields'=>[name=>true], 'tvs'=>[name=>true]] — что записано
     */
    public function grab(string $html, $resource): array
    {
        $written = ['fields' => [], 'tvs' => []];

        foreach ($this->parser->findByAttribute($html, '[data-mpc-rfield]') as $el) {
            $name = trim((string)$el->getAttribute('data-mpc-rfield'));
            if ($name === '' || in_array($name, $this->protected, true)) {
                continue;
            }
            $resource->set($name, $this->extractValue($el));
            $written['fields'][$name] = true;
        }

        foreach ($this->parser->findByAttribute($html, '[data-mpc-tv]') as $el) {
            $name = trim((string)$el->getAttribute('data-mpc-tv'));
            if ($name === '') {
                continue;
            }
            if (method_exists($resource, 'setTVValue')) {
                $resource->setTVValue($name, $this->extractValue($el));
                $written['tvs'][$name] = true;
            }
        }

        return $written;
    }

    /**
     * Значение маркера: src для img/source, href для a/link, иначе innerHtml.
     */
    private function extractValue(Element $el): string
    {
        $tag = $el->tagName();
        if (in_array($tag, ['img', 'source'], true)) {
            return (string)$el->getAttribute('src');
        }
        if (in_array($tag, ['a', 'link'], true)) {
            return (string)$el->getAttribute('href');
        }
        return trim($el->innerHtml());
    }
}
