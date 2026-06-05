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
            $key = $this->lexiconize($resource, $name, $value, LexiconManager::contentTypeForTag($el->tagName()));
            $resource->set($name, $key !== '' ? $key : $value);
            $written['fields'][$name] = true;
        }

        foreach ($this->parser->findByAttribute($html, '[data-mpc-tv]') as $el) {
            $name = trim((string)$el->getAttribute('data-mpc-tv'));
            if ($name === '' || $this->isCrossResource($el)) {
                continue;
            }
            if (method_exists($resource, 'setTVValue')) {
                $value    = $this->extractValue($el);
                $tv       = isset($resource->xpdo) ? $resource->xpdo->getObject('modTemplateVar', ['name' => $name]) : null;
                $tvType   = $tv ? (string)$tv->get('type') : '';
                $elements = $tv ? (string)$tv->get('elements') : '';

                // Опционная TV (listbox/option/checkbox) с парсящимися опциями: капшены
                // из elements TV (БД) → лексикон mpc_resource_tv_<tv>_<value>, значение —
                // нормализованный ключ опции (совпадает с ключом лексикона и рендером).
                if ($this->useLexicons && $this->lexiconManager !== null
                    && LexiconManager::isOptionTvType($tvType)
                    && LexiconManager::classifyListboxOptions($elements)['mode'] !== 'dynamic') {
                    $this->lexiconManager->setContext('', false);
                    if ($this->lexiconManager->shouldLexiconize('text', $name)) {
                        $this->lexiconManager->writeTvOptionCaptions((int)$resource->get('id'), $name, $elements);
                        $multiple = LexiconManager::isMultiOptionFtype($tvType);
                        $resource->setTVValue($name, LexiconManager::normalizeListboxValue($value, $multiple));
                        $written['tvs'][$name] = true;
                        continue;
                    }
                }

                // Прочие TV: content-type по ТИПУ TV. number/date/email/url/file → не
                // переводимы → не лексиконятся (значение в колонке); text/textarea/
                // richtext → лексикон mpc_resource_tv_<name>; @SELECT-listbox → raw.
                $ct  = $this->useLexicons ? LexiconManager::contentTypeForTvType($tvType) : 'text';
                $key = $this->lexiconize($resource, $name, $value, $ct, 'mpc_resource_tv_');
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
     * значение). Решение «лексиконизировать» — ЕДИНОЕ с каттером и редактором:
     * `LexiconManager::shouldLexiconize($contentType, $field)` (content-type ∈
     * mpc_translated_content + exclude). content-type — по тегу маркера
     * (`contentTypeForTag`): image-TV при настройке без `image` НЕ лексиконится;
     * exclude (напр. `pagetitle`) — тоже не лексиконится (значение в колонке).
     * Защищённые alias/uri/template уже отфильтрованы выше.
     *
     * Префикс ключа разный для rfield (`mpc_resource_`) и TV (`mpc_resource_tv_`)
     * — чтобы поля с одинаковым именем не делили один ключ лексикона.
     *
     * @return string ключ лексикона или '' (лексикон не пишется)
     */
    private function lexiconize($resource, string $field, string $value, string $contentType = 'text', string $keyPrefix = 'mpc_resource_'): string
    {
        if (!$this->useLexicons || $this->lexiconManager === null) {
            return '';
        }
        // ЕДИНОЕ решение с каттером/редактором: content-type ∈ mpc_translated_content
        // + exclude (image-TV при настройке без 'image' → НЕ лексиконизируется).
        // prefix пуст — rfield/TV вне секции.
        $this->lexiconManager->setContext('', false);
        if (!$this->lexiconManager->shouldLexiconize($contentType, $field)) {
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
