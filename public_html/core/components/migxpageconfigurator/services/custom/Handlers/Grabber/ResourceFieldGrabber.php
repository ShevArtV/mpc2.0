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
 * Запись — через resource->set/setTVValue. Значение img/source (src)
 * локализуется через MediaDownloader (если он передан), как обычные
 * data-mpc-field картинки; иначе сохраняется исходный URL.
 */
class ResourceFieldGrabber
{
    private Parser $parser;

    /** @var string[] */
    private array $protected;

    private ?MediaDownloader $mediaDownloader;

    private ?LexiconManager $lexiconManager;

    private bool $useLexicons;

    public function __construct(
        Parser $parser,
        array $protectedFields = [],
        ?MediaDownloader $mediaDownloader = null,
        ?LexiconManager $lexiconManager = null,
        bool $useLexicons = false
    ) {
        $this->parser = $parser;
        $this->protected = $protectedFields ?: [
            'id', 'class_key', 'context_key', 'parent', 'uri_override',
            'alias', 'uri', 'template',
        ];
        $this->mediaDownloader = $mediaDownloader;
        $this->lexiconManager  = $lexiconManager;
        $this->useLexicons     = $useLexicons;
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
            if ($name === '' || in_array($name, $this->protected, true) || $this->isCrossResource($el)) {
                continue;
            }
            $value = $this->extractValue($el);
            // При лексиконизации в КОЛОНКУ кладём ключ (как config-поля хранят
            // ключ в mpc_config), значение — в лексикон. Иначе колонка = значение.
            $key = $this->lexiconize($resource, $name, $value);
            $resource->set($name, $key !== '' ? $key : $value);
            $written['fields'][$name] = true;
        }

        foreach ($this->parser->findByAttribute($html, '[data-mpc-tv]') as $el) {
            $name = trim((string)$el->getAttribute('data-mpc-tv'));
            if ($name === '' || $this->isCrossResource($el)) {
                continue;
            }
            if (method_exists($resource, 'setTVValue')) {
                $value = $this->extractValue($el);
                // TV лексиконятся отдельным неймспейсом mpc_resource_tv_<name>,
                // чтобы НЕ коллизить с rfield того же имени (rfield → mpc_resource_<name>).
                $key = $this->lexiconize($resource, $name, $value, 'mpc_resource_tv_');
                $resource->setTVValue($name, $key !== '' ? $key : $value);
                $written['tvs'][$name] = true;
            }
        }

        return $written;
    }

    /**
     * Поле внутри обёртки data-mpc-res — это разметка ДЛЯ РЕДАКТОРА (значение
     * принадлежит другому ресурсу, который выводит сниппет). На грабе текущего
     * ресурса такие поля игнорируем, иначе их значения (напр. {$id}-разметка)
     * затирали бы поля текущего ресурс-типа.
     */
    private function isCrossResource(Element $el): bool
    {
        return $el->closest('[data-mpc-res]') !== null;
    }

    /**
     * Лексиконизация значения rfield: значение пишем в лексикон под ключом
     * `mpc_resource_<field>` (per-resource перевод; на диск кладёт createLexicons),
     * и ВОЗВРАЩАЕМ ключ — его caller кладёт в колонку (как config-поля хранят
     * ключ в mpc_config). Пусто, если лексиконы выключены (тогда колонка =
     * значение). Гейты: useLexicons + excludeLexiconFields — поле из списка
     * исключений (напр. `pagetitle`, читаемый MODX напрямую) НЕ лексиконится,
     * чтобы в колонке осталось значение, а не ключ (консистентно с каттером).
     * Защищённые alias/uri/template уже отфильтрованы выше.
     *
     * Префикс ключа разный для rfield (`mpc_resource_`) и TV (`mpc_resource_tv_`)
     * — чтобы поля с одинаковым именем не делили один ключ лексикона.
     *
     * @return string ключ лексикона или '' (лексикон не пишется)
     */
    private function lexiconize($resource, string $field, string $value, string $keyPrefix = 'mpc_resource_'): string
    {
        if (!$this->useLexicons || $this->lexiconManager === null) {
            return '';
        }
        if ($this->lexiconManager->isExcluded($field)) {
            return '';
        }
        $ident = $this->lexiconManager->getResourceIdentifierById((int)$resource->get('id'));
        if ($ident === '') {
            return '';
        }
        $key = $keyPrefix . $field;
        $this->lexiconManager->lexicons[$ident][$key] = $this->lexiconManager->sanitizeValue($value);
        return $key;
    }

    /**
     * Значение маркера: src для img/source (локализуется через MediaDownloader),
     * href для a/link, иначе innerHtml.
     */
    private function extractValue(Element $el): string
    {
        $tag = $el->tagName();
        if (in_array($tag, ['img', 'source'], true)) {
            return $this->resolveMedia((string)$el->getAttribute('src'));
        }
        if (in_array($tag, ['a', 'link'], true)) {
            return (string)$el->getAttribute('href');
        }
        return trim($el->innerHtml());
    }

    /**
     * Скачивает медиа в источник и возвращает локальный URL (как обычные
     * data-mpc-field картинки). Без MediaDownloader (юнит-тесты / нет DI) или
     * для пустого src — возвращает значение как есть.
     */
    private function resolveMedia(string $src): string
    {
        if ($src === '' || $this->mediaDownloader === null) {
            return $src;
        }
        return $this->mediaDownloader->downloadImage($src);
    }
}
