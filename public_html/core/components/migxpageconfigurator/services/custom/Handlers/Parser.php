<?php
/**
 * Сервис для работы с DOM.
 */

namespace MpcServices\Handlers;

use DiDom\Document as Document;
use DiDom\Element as Element;
use DiDom\Exceptions\InvalidSelectorException;

/**
 * @author Arthur Shevchenko (https://t.me/ShevArtV)
 */
class Parser
{
    public function __construct()
    {
    }

    /**
     * @param string $html
     * @return Document
     */
    private function createDOM(string $html): Document
    {
        return new Document($html);
    }

    /**
     * @param string $html
     * @param string $selector
     * @return array
     * @throws InvalidSelectorException
     */
    public function findByAttribute(string $html, string $selector): array
    {
        $dom = $this->createDOM($html);
        return $dom->find($selector);
    }


    /**
     * @param Element $element
     * @param bool $inner
     * @return string
     */
    public function getHTMLString(Element $element, ?bool $inner = false): string
    {
        return $this->normalizeHTML($this->serialize($element, $inner), true);
    }

    /**
     * Варианты сериализации элемента для ПОИСКА фрагмента в исходном HTML.
     *
     * getHTMLString декодирует HTML-сущности и url-энкод, которые DOM добавляет
     * от себя (атрибуты с `{$...}` уезжают в `%7B%24...`). Но обратная сторона —
     * сущности, которые в исходнике были написаны руками (`TERMS &amp; CONDITIONS`),
     * тоже раскрываются, и такой строки в исходном HTML уже нет: str_replace молча
     * ничего не заменяет (баг #2609-70 — блок возврата на карточке товара).
     *
     * Поэтому отдаём три формы: `decoded` (как раньше), `entities` (снят только
     * url-энкод, сущности сохранены) и `raw` (сериализация DOM как есть). Вызывающий
     * ищет по ним по порядку и подставляет соответствующую форму замены.
     *
     * @param Element $element
     * @param bool $inner
     * @return array{decoded:string,entities:string,raw:string}
     */
    public function getHTMLVariants(Element $element, ?bool $inner = false): array
    {
        $raw = $this->serialize($element, $inner);

        return [
            'decoded'  => $this->normalizeHTML($raw, true),
            'entities' => $this->normalizeHTML($raw, false),
            'raw'      => $this->cleanupHTML($raw),
        ];
    }

    /**
     * Заменяет фрагмент элемента в HTML, перебирая формы сериализации из
     * getHTMLVariants: `decoded` (старое поведение), `entities`, `raw`. Подставляет
     * ту же форму замены, что и найденная. Ни одна форма не нашлась — возвращает
     * null, чтобы вызывающий залогировал промах, а не молчал (баг #2609-70).
     *
     * @param string $html
     * @param array $search формы искомого фрагмента
     * @param array|string $replacement формы замены или готовая строка на все формы
     * @return string|null
     */
    public function replaceFragment(string $html, array $search, $replacement): ?string
    {
        foreach (['decoded', 'entities', 'raw'] as $form) {
            $needle = (string)($search[$form] ?? '');
            if ($needle === '' || strpos($html, $needle) === false) {
                continue;
            }
            $value = is_array($replacement)
                ? (string)($replacement[$form] ?? $replacement['decoded'] ?? '')
                : (string)$replacement;
            return str_replace($needle, $value, $html);
        }

        return null;
    }

    /**
     * Сериализация элемента без какой-либо нормализации.
     *
     * @param Element $element
     * @param bool $inner
     * @return string
     */
    private function serialize(Element $element, ?bool $inner = false): string
    {
        $method = $inner ? 'innerHTML' : 'html';
        return (string)$element->$method();
    }

    /**
     * @param string $html
     * @param bool $decodeEntities
     * @return string
     */
    private function normalizeHTML(string $html, bool $decodeEntities): string
    {
        if ($decodeEntities) {
            $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        $html = str_replace('+', '|plus|', $html);
        $html = urldecode($html);
        $html = str_replace('|plus|', '+', $html);
        return $this->cleanupHTML($html);
    }

    /**
     * @param string $html
     * @return string
     */
    private function cleanupHTML(string $html): string
    {
        return str_replace(["\r", '</img>', '</source>'], '', $html);
    }
}
