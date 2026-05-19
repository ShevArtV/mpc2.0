<?php

namespace MpcTests\Unit\Cutter;

use DiDom\Document;
use MpcServices\Handlers\Cutter\PlaceholderProcessor;
use MpcServices\Handlers\Grabber\LexiconManager;
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
            'excludeLexiconFields'    => [],
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

        $lexiconManager = new LexiconManager($modx, $properties);

        return new PlaceholderProcessor($modx, $properties, new Parser(), $lexiconManager);
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
    // setPlaceholders с включёнными лексиконами — `| lexicon` добавляется
    // только для тех типов контента, что есть в translatableContentTypes.
    // ---------------------------------------------------------------

    private function lexHtml(string $html, array $translatableTypes = ['text', 'image']): string
    {
        $proc = $this->makeProcessor([
            'useLexicons'              => true,
            'translatableContentTypes' => $translatableTypes,
        ]);
        $properties = [
            'html'          => $html,
            'element'       => (new Document($html))->find('[data-mpc-section]')[0],
            'fieldAttrName' => 'data-mpc-field',
            'itemAttrName'  => 'data-mpc-item',
            'level'         => 0,
            'isStatic'      => false,
        ];
        return $proc->setPlaceholders($properties)['html'];
    }

    public function testSetPlaceholdersTextFieldWithLexicon(): void
    {
        $html = $this->lexHtml(
            '<section data-mpc-section="test"><h1 data-mpc-field="title">Hello</h1></section>'
        );
        // Лексикон отложен на final-пасс через ##: pdoTools-интерполяция
        // `{$title}` на eager-пассе подменяет ключ литералом, `##→{` оставляет
        // нормальный Fenom-тег `{'key' | lexicon}` для final-пасса.
        $this->assertStringContainsString("##'{\$title}' | lexicon}", $html);
    }

    public function testSetPlaceholdersTextFieldWithoutTextInTranslatable(): void
    {
        $html = $this->lexHtml(
            '<section data-mpc-section="test"><h1 data-mpc-field="title">Hello</h1></section>',
            ['image']
        );
        $this->assertStringContainsString('{$title}', $html);
        $this->assertStringNotContainsString('| lexicon', $html);
    }

    public function testSetPlaceholdersImgFieldWithImageAndTextLexicon(): void
    {
        $html = $this->lexHtml(
            '<section data-mpc-section="test"><img data-mpc-field="image" src="x.jpg" width="100" height="50" alt="T"></section>'
        );
        $this->assertStringContainsString("##'{\$image[0].src}' | lexicon}", $html);
        $this->assertStringContainsString("##'{\$image[0].alt}' | lexicon}", $html);
        // width/height не локализуются — выводятся обычным тегом
        $this->assertStringContainsString('{$image[0].width}', $html);
        $this->assertStringContainsString('{$image[0].height}', $html);
    }

    public function testSetPlaceholdersImgFieldOnlyImageTranslatable(): void
    {
        $html = $this->lexHtml(
            '<section data-mpc-section="test"><img data-mpc-field="image" src="x.jpg" alt="T"></section>',
            ['image']
        );
        $this->assertStringContainsString("##'{\$image[0].src}' | lexicon}", $html);
        $this->assertStringContainsString('{$image[0].alt}', $html);
        $this->assertStringNotContainsString('alt}\' | lexicon', $html);
    }

    public function testSetPlaceholdersImgWithThumbAndLexicon(): void
    {
        $proc = $this->makeProcessor([
            'useLexicons'              => true,
            'translatableContentTypes' => ['image'],
            'thumbSnippet'             => 'mpcThumb',
            'commonThumbParams'        => 'q=90',
        ]);
        $html = '<section data-mpc-section="t"><img data-mpc-field="pic" src="x.jpg" width="100" height="50"></section>';
        $properties = [
            'html'          => $html,
            'element'       => (new Document($html))->find('[data-mpc-section]')[0],
            'fieldAttrName' => 'data-mpc-field',
            'itemAttrName'  => 'data-mpc-item',
            'level'         => 0,
            'isStatic'      => false,
        ];
        $resultHtml = $proc->setPlaceholders($properties)['html'];

        // Лексикон-режим в thumb: вызов отложен через ##, input через
        // `('{$expr}' | lexicon)`, размеры баковатся в литералы через {$expr}.
        $this->assertStringContainsString("##'mpcThumb' | snippet:", $resultHtml);
        $this->assertStringContainsString("'input' => ('{\$pic[0].src}' | lexicon)", $resultHtml);
        $this->assertStringContainsString('{$pic[0].width}', $resultHtml);
    }

    public function testSetPlaceholdersVideoSrcAndPosterWithLexicon(): void
    {
        $html = $this->lexHtml(
            '<section data-mpc-section="test"><video data-mpc-field="clip" src="m.mp4" poster="p.jpg" controls></video></section>',
            ['text', 'image', 'video', 'poster']
        );
        $this->assertStringContainsString("##'{\$clip[0].src}' | lexicon}", $html);
        $this->assertStringContainsString("##'{\$clip[0].poster}' | lexicon}", $html);
        // controls — boolean-атрибут, не локализуется
        $this->assertStringNotContainsString("controls}' | lexicon", $html);
    }

    public function testSetPlaceholdersAudioSrcWithLexicon(): void
    {
        $html = $this->lexHtml(
            '<section data-mpc-section="test"><audio data-mpc-field="track" src="a.mp3" controls></audio></section>',
            ['audio']
        );
        $this->assertStringContainsString("##'{\$track[0].src}' | lexicon}", $html);
    }

    public function testSetPlaceholdersAudioSrcWithoutAudioInTranslatable(): void
    {
        $html = $this->lexHtml(
            '<section data-mpc-section="test"><audio data-mpc-field="track" src="a.mp3" controls></audio></section>',
            ['text', 'image']  // audio НЕ в списке
        );
        $this->assertStringContainsString('{$track[0].src}', $html);
        $this->assertStringNotContainsString('| lexicon', $html);
    }

    public function testSetPlaceholdersBgImgWithLexicon(): void
    {
        $html = $this->lexHtml(
            '<section data-mpc-section="test"><div data-mpc-field="bg_img" style="background:url(\'x.jpg\');"></div></section>'
        );
        $this->assertStringContainsString("##'{\$bg_img}' | lexicon}", $html);
    }

    // ---------------------------------------------------------------
    // Cutter учитывает excludeLexiconFields — не ставит `| lexicon`
    // на поля, которые грабер пропустил бы (иначе пустота на сайте).
    // ---------------------------------------------------------------

    public function testSetPlaceholdersExcludedFieldNameSkipsLexicon(): void
    {
        $proc = $this->makeProcessor([
            'useLexicons'              => true,
            'translatableContentTypes' => ['text'],
            'excludeLexiconFields'     => ['inline_styles'],
        ]);
        $properties = [
            'html'          => '<section data-mpc-section="t"><div data-mpc-field="inline_styles">--color: red;</div></section>',
            'element'       => (new Document('<section data-mpc-section="t"><div data-mpc-field="inline_styles">--color: red;</div></section>'))->find('[data-mpc-section]')[0],
            'fieldAttrName' => 'data-mpc-field',
            'itemAttrName'  => 'data-mpc-item',
            'level'         => 0,
            'isStatic'      => false,
        ];
        $html = $proc->setPlaceholders($properties)['html'];

        // excluded → нет `| lexicon`, обычный плейсхолдер.
        $this->assertStringContainsString('{$inline_styles}', $html);
        $this->assertStringNotContainsString('| lexicon', $html);
    }

    public function testSetPlaceholdersExcludedFieldNameByGlobSkipsLexicon(): void
    {
        $proc = $this->makeProcessor([
            'useLexicons'              => true,
            'translatableContentTypes' => ['image'],
            'excludeLexiconFields'     => ['*_picture', 'img'],
        ]);
        $html = '<section data-mpc-section="t">'
            . '<img data-mpc-field="img" src="x.jpg">'
            . '<img data-mpc-field="hero_picture" src="y.jpg">'
            . '<img data-mpc-field="banner" src="z.jpg">'
            . '</section>';
        $properties = [
            'html'          => $html,
            'element'       => (new Document($html))->find('[data-mpc-section]')[0],
            'fieldAttrName' => 'data-mpc-field',
            'itemAttrName'  => 'data-mpc-item',
            'level'         => 0,
            'isStatic'      => false,
        ];
        $result = $proc->setPlaceholders($properties)['html'];

        // 'img' — точное исключение → без lexicon
        $this->assertStringContainsString('{$img[0].src}', $result);
        // '*_picture' — glob → без lexicon
        $this->assertStringContainsString('{$hero_picture[0].src}', $result);
        // banner — не под паттерн → с lexicon
        $this->assertStringContainsString("##'{\$banner[0].src}' | lexicon}", $result);
    }

    public function testSetPlaceholdersExcludedByFullPathSkipsLexicon(): void
    {
        // Pattern `cards_*` матчит fullPath `cards_title` → исключаем.
        $proc = $this->makeProcessor([
            'useLexicons'              => true,
            'translatableContentTypes' => ['text'],
            'excludeLexiconFields'     => ['cards_*'],
        ]);
        $html = '<section data-mpc-section="t">'
            . '<div data-mpc-field="cards">'
            .   '<div data-mpc-item>'
            .     '<h2 data-mpc-field-1="title">x</h2>'
            .   '</div>'
            . '</div>'
            . '</section>';
        $properties = [
            'html'          => $html,
            'element'       => (new Document($html))->find('[data-mpc-section]')[0],
            'fieldAttrName' => 'data-mpc-field',
            'itemAttrName'  => 'data-mpc-item',
            'level'         => 0,
            'isStatic'      => false,
        ];
        $result = $proc->setPlaceholders($properties)['html'];

        // title исключён по fullPath
        $this->assertStringContainsString('{$item1.title}', $result);
        $this->assertStringNotContainsString('| lexicon', $result);
    }

    public function testSetPlaceholdersPictureSourceUsesPictureFieldAsParentForExclusion(): void
    {
        // Регрессионный кейс: <source> внутри <picture data-mpc-field-1="picture">
        // должен исключаться по pattern `picture_*` (как делает grabber через
        // getPictureValue: parentFieldName='picture', fullPath='picture_source').
        // Раньше cutter использовал accumulated `list_triple_picture_picture`
        // → pattern не матчил → лексикон ставился, а grabber его не писал.
        $proc = $this->makeProcessor([
            'useLexicons'              => true,
            'translatableContentTypes' => ['image', 'text'],
            'excludeLexiconFields'     => ['picture', 'picture_*', '*_picture'],
            'lazyloadAttr'             => 'data-lazy',
            'samples'                  => [
                'if'                   => $this->ifSample,
                'foreach'              => $this->foreachSample,
                'foreach_limit'        => $this->foreachSample,
                'foreach_offset'       => $this->foreachSample,
                'foreach_limit_offset' => $this->foreachSample,
                'media'                => '##foreach complexName.sources as $source index=$index last=$last}html##/foreach}',
            ],
        ]);
        $html = '<section data-mpc-section="t">'
            . '<div data-mpc-field="list_triple_picture">'
            .   '<div data-mpc-item>'
            .     '<picture data-mpc-field-1="picture">'
            .       '<source srcset="/a.jpg" media="(max-width: 768px)" width="768" height="100">'
            .       '<img src="/b.jpg" width="1920" height="200">'
            .     '</picture>'
            .   '</div>'
            . '</div>'
            . '</section>';
        $properties = [
            'html'          => $html,
            'element'       => (new Document($html))->find('[data-mpc-section]')[0],
            'fieldAttrName' => 'data-mpc-field',
            'itemAttrName'  => 'data-mpc-item',
            'level'         => 0,
            'isStatic'      => false,
        ];
        $result = $proc->setPlaceholders($properties)['html'];

        // source.srcset НЕ должен идти через `| lexicon` — pattern `picture_*`
        // матчит fullPath `picture_source` (как в грабере).
        $this->assertStringNotContainsString("'{\$source.srcset}' | lexicon", $result);
        $this->assertStringContainsString('$source.srcset', $result);
    }

    public function testSetPlaceholdersContainerSuffixDoesNotBleedToChildren(): void
    {
        // Регрессионный кейс: pattern `*_picture` против контейнера
        // `list_triple_picture` НЕ должен исключать subtitle/title под ним.
        $proc = $this->makeProcessor([
            'useLexicons'              => true,
            'translatableContentTypes' => ['text'],
            'excludeLexiconFields'     => ['*_picture', 'picture'],
        ]);
        $html = '<section data-mpc-section="t">'
            . '<div data-mpc-field="list_triple_picture">'
            .   '<div data-mpc-item>'
            .     '<p data-mpc-field-1="subtitle">x</p>'
            .     '<h2 data-mpc-field-1="title">y</h2>'
            .   '</div>'
            . '</div>'
            . '</section>';
        $properties = [
            'html'          => $html,
            'element'       => (new Document($html))->find('[data-mpc-section]')[0],
            'fieldAttrName' => 'data-mpc-field',
            'itemAttrName'  => 'data-mpc-item',
            'level'         => 0,
            'isStatic'      => false,
        ];
        $result = $proc->setPlaceholders($properties)['html'];

        // subtitle/title под picture-контейнером — лексиконятся (fullPath
        // не оканчивается на `_picture`)
        $this->assertStringContainsString("##'{\$item1.subtitle}' | lexicon}", $result);
        $this->assertStringContainsString("##'{\$item1.title}' | lexicon}", $result);
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

    public function testGetThumbWithUseLexiconDefersAndWrapsInput(): void
    {
        $proc = $this->makeProcessor(['thumbSnippet' => 'mpcThumb']);

        $call = $proc->getThumb([
            'width'       => false,
            'height'      => false,
            'thumbParams' => 'q=90',
            'firstSymbol' => '{',
            'complexName' => '$item1.field',
            'srcAttr'     => 'src',
            'useLexicon'  => true,
        ]);

        // Сниппет-вызов отложен через ##, input — `('{$expr}' | lexicon)`.
        // На eager-пассе `'{$item1.field.src}'` интерполируется в `'key'`,
        // после `##→{` получается `{'mpcThumb' | snippet: ['input' => ('key' | lexicon), ...]}`,
        // лексикон резолвит ключ в путь на final-пассе.
        $this->assertStringStartsWith("##'mpcThumb' | snippet:", $call);
        $this->assertStringContainsString("'input' => ('{\$item1.field.src}' | lexicon)", $call);
    }

    public function testGetThumbWithUseLexiconBakesWidthHeightAsLiteral(): void
    {
        $proc = $this->makeProcessor(['thumbSnippet' => 'mpcThumb']);

        $call = $proc->getThumb([
            'width'       => true,
            'height'      => true,
            'thumbParams' => 'q=90',
            'firstSymbol' => '{',
            'complexName' => '$item1.field',
            'srcAttr'     => 'src',
            'useLexicon'  => true,
        ]);

        // Размеры баковатся через `{$item.width}` — pdoTools-интерполяция на
        // eager-пассе подменит их литералом числа. Иначе на final-пассе `$item`
        // не в скоупе (для нестатичных секций).
        $this->assertStringContainsString('{$item1.field.width}', $call);
        $this->assertStringContainsString('{$item1.field.height}', $call);
    }

    public function testGetThumbWithoutUseLexiconLeavesInputBare(): void
    {
        $proc = $this->makeProcessor(['thumbSnippet' => 'mpcThumb']);

        $call = $proc->getThumb([
            'width'       => false,
            'height'      => false,
            'thumbParams' => 'q=90',
            'firstSymbol' => '{',
            'complexName' => '$item1.field',
            'srcAttr'     => 'src',
            'useLexicon'  => false,
        ]);

        $this->assertStringContainsString("'input' => \$item1.field.src", $call);
        $this->assertStringNotContainsString('| lexicon', $call);
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
