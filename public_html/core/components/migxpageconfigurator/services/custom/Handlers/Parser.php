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
     * @return string
     */
    public function getHTMLString(Element $element): string
    {
        $html = urldecode(html_entity_decode($element->html(), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        return str_replace(['</img>','</source>'], '', $html);
    }
}
