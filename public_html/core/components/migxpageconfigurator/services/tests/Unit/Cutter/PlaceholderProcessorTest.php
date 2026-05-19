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

    // ---------------------------------------------------------------
    // getThumb — quoting input для non-lexicon режима
    // ---------------------------------------------------------------

    public function testGetThumbNonLexiconLoopVarLeavesInputAsExpression(): void
    {
        $proc = $this->makeProcessor(['thumbSnippet' => 'mpcThumb']);

        $call = $proc->getThumb([
            'width'       => false,
            'height'      => false,
            'thumbParams' => 'q=90',
            'firstSymbol' => '{',
            'complexName' => '$source',
            'srcAttr'     => 'srcset',
            'isLoopVar'   => true,
        ]);

        // $source.srcset — Fenom-выражение, должно вычисляться внутри foreach
        // в скоупе $source. Кавычки превратили бы его в литерал, и mpcThumb
        // получил бы строку "$source.srcset" вместо актуального пути.
        $this->assertStringContainsString("'input' => \$source.srcset", $call);
        $this->assertStringNotContainsString("'input' => '\$source.srcset'", $call);
    }

    public function testGetThumbNonLexiconRegularLeavesInputAsExpression(): void
    {
        $proc = $this->makeProcessor(['thumbSnippet' => 'mpcThumb']);

        $call = $proc->getThumb([
            'width'       => false,
            'height'      => false,
            'thumbParams' => 'q=90',
            'firstSymbol' => '{',
            'complexName' => '$item1.list_triple_picture[0].picture[0]',
            'srcAttr'     => 'src',
            'isLoopVar'   => false,
        ]);

        $this->assertStringContainsString("'input' => \$item1.list_triple_picture[0].picture[0].src", $call);
        $this->assertStringNotContainsString("'input' => '\$item1.list_triple_picture[0].picture[0].src'", $call);
    }

    public function testGetThumbLoopVarInStaticContextLeavesInputAsExpression(): void
    {
        $proc = $this->makeProcessor(['thumbSnippet' => 'mpcThumb']);

        $call = $proc->getThumb([
            'width'       => false,
            'height'      => false,
            'thumbParams' => 'q=90',
            'firstSymbol' => '##',
            'complexName' => '$source',
            'srcAttr'     => 'srcset',
            'isLoopVar'   => true,
        ]);

        $this->assertStringContainsString("'input' => \$source.srcset", $call);
        $this->assertStringNotContainsString("'input' => '\$source.srcset'", $call);
    }

    public function testGetThumbLexiconImageQuotesBraceWrap(): void
    {
        $proc = $this->makeProcessor([
            'thumbSnippet'             => 'mpcThumb',
            'useLexicons'              => true,
            'translatableContentTypes' => ['text', 'image'],
        ]);

        $call = $proc->getThumb([
            'width'       => false,
            'height'      => false,
            'thumbParams' => 'q=90',
            'firstSymbol' => '{',
            'complexName' => '$item1.field',
            'srcAttr'     => 'src',
            'isLoopVar'   => false,
        ]);

        // lexicon-image: '{…}'-обёртка (с одинарными кавычками вокруг {…}). После
        // предпарсинга {$var} вычислится и подменится на конкретный путь — на выходе
        // получим `'input' => '/assets/...'` (валидный Fenom-литерал). Без кавычек
        // Fenom падает на «Unexpected token '/'» при путях, начинающихся с `/`.
        $this->assertStringContainsString("'{\$item1.field.src}'", $call);
        $this->assertStringContainsString("##'mpcThumb' | snippet:", $call);
        $this->assertStringContainsString('{if $item1.field.src}', $call);
    }

    // ---------------------------------------------------------------
    // setPlaceholders — интегральный тест на <picture>
    // input должен быть Fenom-выражением ($source.srcset), а не литералом,
    // чтобы внутри foreach $source.srcset вычислился в актуальный путь
    // и попал в mpcThumb. Литералы (`'input' => '$source.srcset'` или
    // `'input' => /assets/...`) ломают семантику.
    // ---------------------------------------------------------------

    public function testSetPlaceholdersPictureWithSlashPathsLeavesInputAsExpression(): void
    {
        $proc = $this->makeProcessor([
            'thumbSnippet'      => 'mpcThumb',
            'lazyloadAttr'      => 'data-site-lazy',
            'commonThumbParams' => 'q=90',
            'samples'           => [
                'if'                   => $this->ifSample,
                'foreach'              => $this->foreachSample,
                'foreach_limit'        => $this->foreachSample,
                'foreach_offset'       => $this->foreachSample,
                'foreach_limit_offset' => $this->foreachSample,
                'media'                => '##foreach complexName.sources as $source index=$index last=$last}html##/foreach}',
            ],
        ]);

        $html = '<section data-mpc-section="test">'
            . '<picture data-mpc-field="picture">'
            . '<img src="/assets/components/sleepandglow/img/sections/top-slider/001.jpg" width="1920" height="920" alt="">'
            . '<source srcset="/assets/components/sleepandglow/img/sections/top-slider/01-mobile.jpg" media="(max-width: 768px)" width="768" height="1238">'
            . '<source srcset="/assets/components/sleepandglow/img/sections/top-slider/01-ipad.jpg" media="(max-width: 1280px)" width="1280" height="598">'
            . '</picture>'
            . '</section>';

        $properties = [
            'html'          => $html,
            'element'       => (new Document($html))->find('[data-mpc-section]')[0],
            'fieldAttrName' => 'data-mpc-field',
            'itemAttrName'  => 'data-mpc-item',
            'level'         => 0,
            'isStatic'      => false,
        ];

        $result = $proc->setPlaceholders($properties);

        $this->assertStringNotContainsString("'input' => /", $result['html'],
            "Сырой путь в input — Fenom не должен видеть литерал");
        $this->assertStringNotContainsString("'input' => '\$source.srcset'", $result['html'],
            "Литерал-строка вместо Fenom-выражения — mpcThumb получит '\$source.srcset', а не путь");
        $this->assertStringContainsString("'input' => \$source.srcset", $result['html']);
    }
}
