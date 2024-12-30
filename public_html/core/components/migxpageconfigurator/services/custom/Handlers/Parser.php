<?php
/**
 * Сервис для работы с DOM.
 */

namespace MpcServices\Handlers;

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
     * @param string $version
     * @param string $encoding
     * @return \DOMDocument
     */
    private function createDOM(string $html, string $version, string $encoding): \DOMDocument
    {
        $dom = new \DOMDocument($version, $encoding);
        libxml_use_internal_errors(true);
        $dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', $encoding));
        libxml_use_internal_errors(false);
        return $dom;
    }

    /**
     * @param string $html
     * @param string $selector
     * @param string $version
     * @param string $encoding
     * @return \DOMNodeList
     */
    public function findByAttribute(string $html, string $selector, string $version = '1.0', string $encoding = 'UTF-8'): \DOMNodeList
    {
        $dom = $this->createDOM($html, $version, $encoding);
        $xpath = new \DOMXpath($dom);
        $selector = str_replace('[', '[@', $selector);
        return $xpath->query("//*{$selector}");
    }


    /**
     * @param \DOMText|\DOMElement $element
     * @return string
     */
    public function getHTMLString($element): string
    {
        $html = urldecode(html_entity_decode($element->ownerDocument->saveHTML($element)));
        return str_replace(['</source>', '</path>'], '', $html);
    }
}
