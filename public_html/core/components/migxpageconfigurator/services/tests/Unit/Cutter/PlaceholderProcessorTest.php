<?php

namespace MpcTests\Unit\Cutter;

use DiDom\Document;
use MpcServices\Handlers\Cutter\PlaceholderProcessor;
use MpcServices\Handlers\Parser;
use MpcTests\Stubs\ModxStub;
use PHPUnit\Framework\TestCase;

/**
 * Unit-тесты для pure-методов PlaceholderProcessor.
 * Тестируем: getSymbolComplex, wrapInCondition, getThumb через публичный API.
 */
class PlaceholderProcessorTest extends TestCase
{
    private string $ifSample        = "##if condition}\n    html\n##/if}";
    private string $foreachSample   = "##foreach subject as \$item^ index=\$i^ last=\$l^}\n    html\n##/foreach}";

    private function makeProcessor(array $extraProperties = []): PlaceholderProcessor
    {
        $fixturesDir = dirname(__DIR__, 2) . '/Fixtures';
        $modx = new ModxStub($fixturesDir);

        $properties = array_merge([
            'lazyloadAttr'            => '',
            'fakeImgPath'             => 'assets/fake.png',
            'thumbSnippet'            => '',
            'commonThumbParams'       => '',
            'useLexicons'             => false,
            'translatableContentTypes' => ['text', 'image'],
            'isSectionStatic'         => false,
            'samples'                 => [
                'if'                   => $this->ifSample,
                'foreach'              => $this->foreachSample,
                'foreach_limit'        => $this->foreachSample,
                'foreach_offset'       => $this->foreachSample,
                'foreach_limit_offset' => $this->foreachSample,
                'media'                => '',
            ],
        ], $extraProperties);

        return new PlaceholderProcessor($modx, $properties, new Parser());
    }

    private function makeElement(string $tag, array $attrs = [], string $inner = ''): \DiDom\Element
    {
        $html = "<$tag";
        foreach ($attrs as $k => $v) {
            $html .= " $k=\"$v\"";
        }
        $html .= ">$inner</$tag>";
        $doc = new Document($html);
        return $doc->find($tag)[0];
    }

    // ---------------------------------------------------------------
    // getSymbolComplex
    // ---------------------------------------------------------------

    public function testGetSymbolComplexReturnsOpenBrace(): void
    {
        $proc    = $this->makeProcessor();
        $element = $this->makeElement('div', ['data-mpc-field' => 'title']);
        [$symbol] = $proc->getSymbolComplex($element, 'title');

        $this->assertEquals('{', $symbol);
    }

    public function testGetSymbolComplexReturnsHashHashForStatic(): void
    {
        $proc    = $this->makeProcessor();
        $element = $this->makeElement('div', ['data-mpc-field' => 'title']);
        [$symbol] = $proc->getSymbolComplex($element, 'title', 0, true);

        $this->assertEquals('##', $symbol);
    }

    public function testGetSymbolComplexReturnsCustomSymbol(): void
    {
        $proc    = $this->makeProcessor();
        $element = $this->makeElement('div', ['data-mpc-field' => 'title', 'data-mpc-symbol' => '##']);
        [$symbol] = $proc->getSymbolComplex($element, 'title', 0, false);

        $this->assertEquals('##', $symbol);
    }

    public function testGetSymbolComplexSimpleField(): void
    {
        $proc    = $this->makeProcessor();
        $element = $this->makeElement('div', ['data-mpc-field' => 'title']);
        [, $complex] = $proc->getSymbolComplex($element, 'title', 0, false);

        $this->assertEquals('$title', $complex);
    }

    public function testGetSymbolComplexNestedField(): void
    {
        $proc    = $this->makeProcessor();
        $element = $this->makeElement('div', ['data-mpc-field' => 'name']);
        [, $complex] = $proc->getSymbolComplex($element, 'name', 2, false);

        $this->assertEquals('$item2.name', $complex);
    }

    public function testGetSymbolComplexListImageField(): void
    {
        $proc    = $this->makeProcessor();
        $element = $this->makeElement('img', ['data-mpc-field' => 'list_images']);
        [, $complex] = $proc->getSymbolComplex($element, 'list_images', 0, false);

        $this->assertEquals('$list_images', $complex);
    }

    public function testGetSymbolComplexTableMode(): void
    {
        $proc    = $this->makeProcessor();
        $element = $this->makeElement('div', ['data-mpc-field' => 'pagetitle', 'data-mpc-table' => 'resource', 'data-mpc-rid' => '5']);
        [, $complex] = $proc->getSymbolComplex($element, 'pagetitle', 0, false);

        $this->assertEquals("(5 | resource: 'pagetitle')", $complex);
    }

    // ---------------------------------------------------------------
    // wrapInCondition
    // ---------------------------------------------------------------

    public function testWrapInConditionWithBrace(): void
    {
        $proc   = $this->makeProcessor();
        $result = $proc->wrapInCondition('$title', '<h1>Test</h1>');

        $this->assertStringContainsString('{if $title}', $result);
        $this->assertStringContainsString('<h1>Test</h1>', $result);
        $this->assertStringContainsString('{/if}', $result);
    }

    public function testWrapInConditionWithHashHash(): void
    {
        $proc   = $this->makeProcessor();
        $result = $proc->wrapInCondition('$title', '<h1>Test</h1>', '##');

        $this->assertStringContainsString('##if $title}', $result);
        $this->assertStringContainsString('##/if}', $result);
    }

    // ---------------------------------------------------------------
    // setPlaceholders — интегральный тест через HTML
    // ---------------------------------------------------------------

    public function testSetPlaceholdersSimpleTextField(): void
    {
        $proc = $this->makeProcessor();
        $html = '<section data-mpc-section="test"><h1 data-mpc-field="title">Hello</h1></section>';

        $properties = [
            'html'                 => $html,
            'element'              => (new Document($html))->find('[data-mpc-section]')[0],
            'fieldAttrName'        => 'data-mpc-field',
            'itemAttrName'         => 'data-mpc-item',
            'level'                => 0,
            'sectionLexiconPrefix' => '',
            'isStatic'             => false,
        ];

        $result = $proc->setPlaceholders($properties);

        $this->assertStringContainsString('{$title}', $result['html']);
        $this->assertStringNotContainsString('Hello', $result['html']);
    }

    public function testSetPlaceholdersImgField(): void
    {
        $proc = $this->makeProcessor();
        $html = '<section data-mpc-section="test"><img data-mpc-field="image" src="test.jpg" width="100" height="50" alt="Test"></section>';

        $properties = [
            'html'          => $html,
            'element'       => (new Document($html))->find('[data-mpc-section]')[0],
            'fieldAttrName' => 'data-mpc-field',
            'itemAttrName'  => 'data-mpc-item',
            'level'         => 0,
            'isStatic'      => false,
        ];

        $result = $proc->setPlaceholders($properties);

        $this->assertStringContainsString('{$image[0].src}', $result['html']);
        $this->assertStringContainsString('{$image[0].width}', $result['html']);
        $this->assertStringContainsString('{$image[0].alt}', $result['html']);
    }

    public function testSetPlaceholdersConditionField(): void
    {
        $proc = $this->makeProcessor();
        $html = '<section data-mpc-section="test"><div data-mpc-field="banner" data-mpc-if="$banner">Banner</div></section>';

        $properties = [
            'html'          => $html,
            'element'       => (new Document($html))->find('[data-mpc-section]')[0],
            'fieldAttrName' => 'data-mpc-field',
            'itemAttrName'  => 'data-mpc-item',
            'level'         => 0,
            'isStatic'      => false,
        ];

        $result = $proc->setPlaceholders($properties);

        $this->assertStringContainsString('{if $banner}', $result['html']);
        $this->assertStringContainsString('{/if}', $result['html']);
    }
}
