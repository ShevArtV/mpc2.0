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

    public function testGetSymbolComplexNestedListImageField(): void
    {
        // Медиа-список внутри элемента списка (level > 0) получает префикс $itemN.
        $proc    = $this->makeProcessor();
        $element = $this->makeElement('img', ['data-mpc-field-1' => 'list_images']);
        [, $complex] = $proc->getSymbolComplex($element, 'list_images[0].img', 1, false);

        $this->assertEquals('$item1.list_images[0].img', $complex);
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

    public function testSetPlaceholdersNestedFieldInLink(): void
    {
        $proc = $this->makeProcessor();
        $html = '<section data-mpc-section="test">'
            . '<a data-mpc-field="link" href="/contacts">'
            . '<span data-mpc-field="link_text" data-mpc-unwrap="1">Связаться</span>'
            . '</a></section>';

        $properties = [
            'html'          => $html,
            'element'       => (new Document($html))->find('[data-mpc-section]')[0],
            'fieldAttrName' => 'data-mpc-field',
            'itemAttrName'  => 'data-mpc-item',
            'level'         => 0,
            'isStatic'      => false,
        ];

        $result = $proc->setPlaceholders($properties)['html'];
        $result = $proc->unwrapBlock($result); // этап снятия обёрток (как в SectionFileWriter)

        // href ссылки и её текст — два независимых поля
        $this->assertStringContainsString('href="{$link}"', $result);
        $this->assertStringContainsString('{$link_text}', $result);
        // data-mpc-unwrap снял вложенный <span>, оставив только плейсхолдер текста
        $this->assertStringNotContainsString('<span', $result);
    }

    public function testSetPlaceholdersImgInsideListUsesOwnAttrs(): void
    {
        // img внутри списка остаётся массивом img[0], alt берётся из самого
        // медиа-поля (img[0].alt), а не из чужого поля.
        $proc = $this->makeProcessor();
        $html = '<section data-mpc-section="test">'
            . '<div data-mpc-field="cards"><div data-mpc-item>'
            . '<img data-mpc-field-1="img" src="1.png" alt="Фото" width="10" height="10">'
            . '<h3 data-mpc-field-1="title">Карточка</h3>'
            . '</div></div></section>';
        $properties = [
            'html'          => $html,
            'element'       => (new Document($html))->find('[data-mpc-section]')[0],
            'fieldAttrName' => 'data-mpc-field',
            'itemAttrName'  => 'data-mpc-item',
            'level'         => 0,
            'isStatic'      => false,
        ];
        $result = $proc->setPlaceholders($properties)['html'];
        $this->assertStringContainsString('{$item1.img[0].src}', $result);
        $this->assertStringContainsString('{$item1.img[0].alt}', $result);
        $this->assertStringContainsString('{$item1.title}', $result);
    }

    public function testSetPlaceholdersTwoLevelList(): void
    {
        // Двухуровневый список: вложенные foreach с $item1/$item2.
        $proc = $this->makeProcessor();
        $html = '<section data-mpc-section="test">'
            . '<div data-mpc-field="blocks"><div data-mpc-item>'
            . '<h3 data-mpc-field-1="title">Блок</h3>'
            . '<ul data-mpc-field-1="items"><li data-mpc-item-1>'
            . '<span data-mpc-field-2="label">Пункт</span>'
            . '</li></ul>'
            . '</div></div></section>';
        $properties = [
            'html'          => $html,
            'element'       => (new Document($html))->find('[data-mpc-section]')[0],
            'fieldAttrName' => 'data-mpc-field',
            'itemAttrName'  => 'data-mpc-item',
            'level'         => 0,
            'isStatic'      => false,
        ];
        $result = $proc->setPlaceholders($properties)['html'];
        $this->assertStringContainsString('{foreach $blocks as $item1', $result);
        $this->assertStringContainsString('{$item1.title}', $result);
        $this->assertStringContainsString('{foreach $item1.items as $item2', $result);
        $this->assertStringContainsString('{$item2.label}', $result);
    }

    public function testSetPlaceholdersTopLevelListImages(): void
    {
        // Регресс: top-level медиа-список → индексированный $list_images[k],
        // без префикса $itemN и без статичных URL.
        $proc = $this->makeProcessor();
        $html = '<section data-mpc-section="test">'
            . '<img data-mpc-field="list_images" src="a.jpg" width="10" height="10" alt="A">'
            . '<img data-mpc-field="list_images" src="b.jpg" width="20" height="20" alt="B">'
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

        $this->assertStringContainsString('{$list_images[0].img[0].src}', $result);
        $this->assertStringContainsString('{$list_images[1].img[0].src}', $result);
        $this->assertStringNotContainsString('a.jpg', $result);
        $this->assertStringNotContainsString('b.jpg', $result);
        $this->assertStringNotContainsString('$item', $result);
    }

    public function testSetPlaceholdersNestedListImages(): void
    {
        // Медиа-список list_images внутри элемента списка list_triple_images
        // должен превратиться в $item1.list_images[k]..., а не остаться статикой
        // и не получить двойной префикс $item1.item1.
        $proc = $this->makeProcessor();
        $html = '<section data-mpc-section="test">'
            . '<ul data-mpc-field="list_triple_images"><li data-mpc-item>'
            . '<h5 data-mpc-field-1="title">T</h5>'
            . '<img data-mpc-field-1="list_images" src="lake.jpg" width="53" height="53" alt="O">'
            . '<img data-mpc-field-1="list_images" src="rain.jpg" width="52" height="52" alt="R">'
            . '</li></ul></section>';

        $properties = [
            'html'          => $html,
            'element'       => (new Document($html))->find('[data-mpc-section]')[0],
            'fieldAttrName' => 'data-mpc-field',
            'itemAttrName'  => 'data-mpc-item',
            'level'         => 0,
            'isStatic'      => false,
        ];

        $result = $proc->setPlaceholders($properties)['html'];

        $this->assertStringContainsString('{foreach $list_triple_images as $item1', $result);
        $this->assertStringContainsString('{$item1.title}', $result);
        $this->assertStringContainsString('{$item1.list_images[0].img[0].src}', $result);
        $this->assertStringContainsString('{$item1.list_images[1].img[0].src}', $result);
        $this->assertStringNotContainsString('$item1.item1', $result);
        $this->assertStringNotContainsString('lake.jpg', $result);
        $this->assertStringNotContainsString('rain.jpg', $result);
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

    public function testSetPlaceholdersImgFieldWithTitleLexicon(): void
    {
        $html = $this->lexHtml(
            '<section data-mpc-section="test"><img data-mpc-field="image" src="x.jpg" alt="A" title="T"></section>'
        );
        // title — `text`, лексиконится с суффиксом _title (симметрично alt).
        $this->assertStringContainsString("##'{\$image[0].title}' | lexicon}", $html);
    }

    public function testSetPlaceholdersImgTitleWithoutTextInTranslatable(): void
    {
        $html = $this->lexHtml(
            '<section data-mpc-section="test"><img data-mpc-field="image" src="x.jpg" title="T"></section>',
            ['image']  // text НЕ в списке → title без lexicon
        );
        $this->assertStringContainsString('{$image[0].title}', $html);
        $this->assertStringNotContainsString('title}\' | lexicon', $html);
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

    public function testSetPlaceholdersVideoBooleanAttrsConditionalRender(): void
    {
        // Булевы атрибуты (controls/muted/…) рендерятся УСЛОВНО: выводятся только
        // при truthy-значении в конфиге. Подстановкой значения их не выключить
        // (`controls="0"` всё равно включает), поэтому НЕ `controls="{$...}"`,
        // а `{if $clip[0].controls}controls{/if}`.
        $html = $this->lexHtml(
            '<section data-mpc-section="test"><video data-mpc-field="clip" src="m.mp4" controls muted></video></section>',
            ['video']
        );
        $this->assertStringContainsString('{if $clip[0].controls}controls{/if}', $html);
        $this->assertStringContainsString('{if $clip[0].muted}muted{/if}', $html);
        $this->assertStringNotContainsString('controls="{$clip[0].controls}"', $html);
        $this->assertStringNotContainsString('@@MPCBOOL@@', $html);
    }

    public function testSetPlaceholdersVideoTitleWithLexicon(): void
    {
        $html = $this->lexHtml(
            '<section data-mpc-section="test"><video data-mpc-field="clip" src="m.mp4" title="Tip" controls></video></section>',
            ['text', 'image', 'video', 'poster']
        );
        $this->assertStringContainsString("##'{\$clip[0].title}' | lexicon}", $html);
    }

    public function testSetPlaceholdersAudioTitleWithLexicon(): void
    {
        $html = $this->lexHtml(
            '<section data-mpc-section="test"><audio data-mpc-field="track" src="a.mp3" title="Tip" controls></audio></section>',
            ['text', 'audio']
        );
        $this->assertStringContainsString("##'{\$track[0].title}' | lexicon}", $html);
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

    /** Фон в двойных кавычках url("…") тоже распознаётся и заменяется. */
    public function testSetPlaceholdersBgImgDoubleQuotesReplaced(): void
    {
        $html = $this->lexHtml(
            '<section data-mpc-section="test"><div data-mpc-field="bg_img" style=\'background:url("x.jpg")\'></div></section>'
        );
        $this->assertStringContainsString('| lexicon}', $html);
        $this->assertStringNotContainsString('x.jpg', $html);
    }

    /** Фон без кавычек url(…) тоже распознаётся и заменяется. */
    public function testSetPlaceholdersBgImgNoQuotesReplaced(): void
    {
        $html = $this->lexHtml(
            '<section data-mpc-section="test"><div data-mpc-field="bg_img" style="background:url(x.jpg)"></div></section>'
        );
        $this->assertStringContainsString('| lexicon}', $html);
        $this->assertStringNotContainsString('x.jpg', $html);
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

    public function testSetPlaceholdersExcludedByPrefixedLexKeySkipsLexicon(): void
    {
        // Exclude в префиксной форме `{section}_content`. Грабер чтит её через
        // полный lex-ключ (setLexicons → isFieldExcluded($lexiconKey)); каттер
        // должен тоже — после setSectionContext. Иначе на рендере `| lexicon`
        // отдаёт пусто (грабер ключ не завёл, каттер влепил модификатор).
        $proc = $this->makeProcessor([
            'useLexicons'              => true,
            'translatableContentTypes' => ['text'],
            'excludeLexiconFields'     => ['image_banner_content'],
        ]);
        $html    = '<section data-mpc-section="image_banner"><div data-mpc-field="content">x</div></section>';
        $section = (new Document($html))->find('[data-mpc-section]')[0];
        $proc->setSectionContext($section);
        $result = $proc->setPlaceholders([
            'html'          => $html,
            'element'       => $section,
            'fieldAttrName' => 'data-mpc-field',
            'itemAttrName'  => 'data-mpc-item',
            'level'         => 0,
            'isStatic'      => false,
        ])['html'];

        $this->assertStringContainsString('{$content}', $result);
        $this->assertStringNotContainsString('| lexicon', $result);
    }

    public function testPrefixedLexKeyExcludeRequiresSectionContext(): void
    {
        // Контроль асимметрии: тот же exclude `image_banner_content` БЕЗ
        // setSectionContext (пустой префикс) не срабатывает — `content`
        // лексиконится. Именно это (грабер чтил префиксный ключ, каттер — нет)
        // и чинит setSectionContext в Cutter::handleSections.
        $proc = $this->makeProcessor([
            'useLexicons'              => true,
            'translatableContentTypes' => ['text'],
            'excludeLexiconFields'     => ['image_banner_content'],
        ]);
        $html = '<section data-mpc-section="image_banner"><div data-mpc-field="content">x</div></section>';
        $result = $proc->setPlaceholders([
            'html'          => $html,
            'element'       => (new Document($html))->find('[data-mpc-section]')[0],
            'fieldAttrName' => 'data-mpc-field',
            'itemAttrName'  => 'data-mpc-item',
            'level'         => 0,
            'isStatic'      => false,
        ])['html'];

        $this->assertStringContainsString("##'{\$content}' | lexicon}", $result);
    }

    public function testPrefixedLexKeyExcludeViaGlobAndLexiconAttrOverride(): void
    {
        // data-mpc-lexicon переопределяет префикс (как у грабера), а exclude в
        // glob-форме `*_content` матчит полный lex-ключ `hero_content`.
        $proc = $this->makeProcessor([
            'useLexicons'              => true,
            'translatableContentTypes' => ['text'],
            'excludeLexiconFields'     => ['*_content'],
        ]);
        $html    = '<section data-mpc-section="image_banner" data-mpc-lexicon="hero"><div data-mpc-field="content">x</div></section>';
        $section = (new Document($html))->find('[data-mpc-section]')[0];
        $proc->setSectionContext($section);
        $result = $proc->setPlaceholders([
            'html'          => $html,
            'element'       => $section,
            'fieldAttrName' => 'data-mpc-field',
            'itemAttrName'  => 'data-mpc-item',
            'level'         => 0,
            'isStatic'      => false,
        ])['html'];

        $this->assertStringContainsString('{$content}', $result);
        $this->assertStringNotContainsString('| lexicon', $result);
    }

    public function testBareFieldInsideItemThrowsClearError(): void
    {
        // Регресс: bare `data-mpc-field` внутри `data-mpc-item` (надо
        // `data-mpc-field-1`) раньше валился непрозрачным DiDom TypeError
        // (`hasAttribute must be of type bool, null returned`) на части PHP.
        // Теперь — понятная ошибка ДО обработки полей.
        $proc = $this->makeProcessor();
        $html = '<section data-mpc-section="t">'
            . '<div data-mpc-field="cards">'
            .   '<div data-mpc-item="cards">'
            .     '<h3 data-mpc-field="card_title">x</h3>'
            .   '</div>'
            . '</div>'
            . '</section>';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('data-mpc-field-1');

        $proc->setPlaceholders([
            'html'          => $html,
            'element'       => (new Document($html))->find('[data-mpc-section]')[0],
            'fieldAttrName' => 'data-mpc-field',
            'itemAttrName'  => 'data-mpc-item',
            'level'         => 0,
            'isStatic'      => false,
        ]);
    }

    public function testCorrectlyNumberedFieldInsideItemDoesNotThrow(): void
    {
        // Контроль: правильная вёрстка (`data-mpc-field-1` внутри
        // `data-mpc-item`) не триггерит валидацию.
        $proc = $this->makeProcessor();
        $html = '<section data-mpc-section="t">'
            . '<div data-mpc-field="cards">'
            .   '<div data-mpc-item="cards">'
            .     '<h3 data-mpc-field-1="card_title">x</h3>'
            .   '</div>'
            . '</div>'
            . '</section>';

        $result = $proc->setPlaceholders([
            'html'          => $html,
            'element'       => (new Document($html))->find('[data-mpc-section]')[0],
            'fieldAttrName' => 'data-mpc-field',
            'itemAttrName'  => 'data-mpc-item',
            'level'         => 0,
            'isStatic'      => false,
        ])['html'];

        $this->assertStringContainsString('{$item1.card_title}', $result);
    }

    public function testSetPlaceholdersDoesNotLeakParentFieldNameBetweenSiblings(): void
    {
        // Регрессионный кейс: после обработки одного top-level item-фольда
        // (например, `list_triple_picture` — parent ставится `list_triple_picture`),
        // следующий top-level item-фольд (`list_simple`) НЕ должен наследовать
        // этот parent. Иначе fullPath для content внутри list_simple становится
        // `list_triple_picture_list_simple_content` и матчит pattern
        // `list_triple*content*` → ложно исключается.
        $proc = $this->makeProcessor([
            'useLexicons'              => true,
            'translatableContentTypes' => ['text'],
            'excludeLexiconFields'     => ['list_triple*content*'],
        ]);
        $html = '<section data-mpc-section="t">'
            . '<div data-mpc-field="list_triple_picture">'
            .   '<div data-mpc-item>'
            .     '<p data-mpc-field-1="caption">x</p>'
            .   '</div>'
            . '</div>'
            . '<div data-mpc-field="list_simple">'
            .   '<div data-mpc-item>'
            .     '<p data-mpc-field-1="content">y</p>'
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

        // content в list_simple — лексиконим (parent должен быть `list_simple`,
        // fullPath `list_simple_content`, pattern `list_triple*content*` не матчит).
        $this->assertStringContainsString("##'{\$item1.content}' | lexicon}", $result);
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

    public function testSetPlaceholdersStaticForeachDefersLexiconVar(): void
    {
        // Регрессионный кейс: в статичной секции (`data-mpc-static=1`)
        // обёртывающий `##foreach}` отложен (firstSymbol=##), поэтому на eager-
        // пассе loop-переменная $item1 ещё НЕ в скоупе. Eager-интерполяция
        // `##'{$item1.content}' | lexicon}` дала бы `{'' | lexicon}` в parsed/.
        // Для static+foreach переменная откладывается целиком:
        // `##$item1.content | lexicon}` → после `##→{` → `{$item1.content |
        // lexicon}`, резолвится когда отложенный foreach реально итерирует.
        $proc = $this->makeProcessor([
            'useLexicons'              => true,
            'translatableContentTypes' => ['text'],
        ]);
        $html = '<section data-mpc-section="t" data-mpc-static="1">'
            . '<div data-mpc-field="list_simple">'
            .   '<div data-mpc-item>'
            .     '<p data-mpc-field-1="content">y</p>'
            .   '</div>'
            . '</div>'
            . '</section>';
        $properties = [
            'html'          => $html,
            'element'       => (new Document($html))->find('[data-mpc-section]')[0],
            'fieldAttrName' => 'data-mpc-field',
            'itemAttrName'  => 'data-mpc-item',
            'level'         => 0,
            'isStatic'      => true,
        ];
        $result = $proc->setPlaceholders($properties)['html'];

        $this->assertStringContainsString('##$item1.content | lexicon}', $result);
        $this->assertStringNotContainsString("##'{\$item1.content}' | lexicon}", $result);
    }

    public function testSetPlaceholdersStaticTopLevelKeepsEagerInterpolation(): void
    {
        // Для top-level поля статичной секции (вне foreach) eager-интерполяция
        // сохраняется: $title в скоупе на eager-пассе, значение (ключ) запекается
        // литералом. Откладывать переменную тут НЕ нужно (level == 0).
        $proc = $this->makeProcessor([
            'useLexicons'              => true,
            'translatableContentTypes' => ['text'],
        ]);
        $html = '<section data-mpc-section="t" data-mpc-static="1">'
            . '<h1 data-mpc-field="title">y</h1>'
            . '</section>';
        $properties = [
            'html'          => $html,
            'element'       => (new Document($html))->find('[data-mpc-section]')[0],
            'fieldAttrName' => 'data-mpc-field',
            'itemAttrName'  => 'data-mpc-item',
            'level'         => 0,
            'isStatic'      => true,
        ];
        $result = $proc->setPlaceholders($properties)['html'];

        $this->assertStringContainsString("##'{\$title}' | lexicon}", $result);
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

    public function testGetThumbDeferVarInStaticForeachLeavesInputAndSizesBare(): void
    {
        // static + foreach (deferVar): обёртывающий `##foreach}` отложен, поэтому
        // eager-интерполяция вычислила бы $item1 мимо скоупа. Откладываем
        // переменную целиком: input — `($expr | lexicon)` без eager-кавычек,
        // размеры — bare `$expr` без `{...}` (резолвятся на final-пассе, когда
        // отложенный foreach итерирует и $item1 в скоупе).
        $proc = $this->makeProcessor(['thumbSnippet' => 'mpcThumb']);

        $call = $proc->getThumb([
            'width'       => true,
            'height'      => true,
            'thumbParams' => 'q=90',
            'firstSymbol' => '##',
            'complexName' => '$item1.field',
            'srcAttr'     => 'src',
            'useLexicon'  => true,
            'deferVar'    => true,
        ]);

        $this->assertStringContainsString("'input' => (\$item1.field.src | lexicon)", $call);
        $this->assertStringNotContainsString("('{\$item1.field.src}' | lexicon)", $call);
        $this->assertStringNotContainsString('{$item1.field.width}', $call);
        $this->assertStringNotContainsString('{$item1.field.height}', $call);
        $this->assertStringContainsString('$item1.field.width', $call);
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

    // ---------------------------------------------------------------
    // unwrapBlock
    // ---------------------------------------------------------------

    /** Базовый unwrap: обёртка [data-mpc-unwrap] снимается, содержимое остаётся. */
    public function testUnwrapBlockRemovesWrapper(): void
    {
        $proc = $this->makeProcessor();
        $html = '<div data-mpc-unwrap="1"><li class="x">{$title | lexicon}</li></div>';

        $out = $proc->unwrapBlock($html);

        $this->assertStringNotContainsString('data-mpc-unwrap', $out);
        $this->assertSame('<li class="x">{$title | lexicon}</li>', $out);
    }

    /**
     * Регрессия: unwrap чанка с data-mpc-attr на внутреннем элементе.
     *
     * unwrapBlock ДОЛЖЕН вызываться, пока data-mpc-attr ещё ВАЛИДНЫЙ атрибут
     * (data-mpc-attr="{$attr}"). Если сначала развернуть data-mpc-attr в голый
     * {$attr} (позиция HTML-атрибута), DiDom-ре-сериализация внутри unwrapBlock
     * выбросит невалидный {$attr} → str_replace промахнётся и обёртка не снимется
     * (особенно заметно при mpc_edit_mode, где strip атрибутов пропущен). Порядок
     * в SectionFileWriter::putToFile: unwrapBlock → потом data-mpc-attr.
     */
    public function testUnwrapBlockRemovesWrapperWithDataMpcAttrIntact(): void
    {
        $proc = $this->makeProcessor();
        $html = '<div data-mpc-unwrap="1" data-mpc-chunk="hdr/row.tpl">'
              . '<li class="item {$classnames}" data-mpc-attr="{$attr}">{$menutitle | lexicon}</li>'
              . '</div>';

        $out = $proc->unwrapBlock($html);

        $this->assertStringNotContainsString('data-mpc-unwrap', $out, 'обёртка должна сняться');
        $this->assertStringNotContainsString('data-mpc-chunk', $out, 'обёртка должна сняться');
        $this->assertStringStartsWith('<li', $out);
        $this->assertStringContainsString('data-mpc-attr="{$attr}"', $out, 'data-mpc-attr внутри сохранён для последующей замены');
    }

    // ---------------------------------------------------------------
    // <source> внутри media-элемента: набор атрибутов сводится по всем строкам
    // ---------------------------------------------------------------

    /**
     * Прогон одной секции через setPlaceholders с рабочим sample'ом media.
     */
    private function mediaHtml(string $sectionHtml, bool $isStatic = false, string $lazyloadAttr = 'data-lazy'): string
    {
        $proc = $this->makeProcessor([
            'lazyloadAttr' => $lazyloadAttr,
            'samples'      => [
                'if'                   => $this->ifSample,
                'foreach'              => $this->foreachSample,
                'foreach_limit'        => $this->foreachSample,
                'foreach_offset'       => $this->foreachSample,
                'foreach_limit_offset' => $this->foreachSample,
                'media'                => '##foreach complexName.sources as $source index=$index last=$last}html##/foreach}',
            ],
        ]);
        $properties = [
            'html'          => $sectionHtml,
            'element'       => (new Document($sectionHtml))->find('[data-mpc-section]')[0],
            'fieldAttrName' => 'data-mpc-field',
            'itemAttrName'  => 'data-mpc-item',
            'level'         => 0,
            'isStatic'      => $isStatic,
        ];
        return $proc->setPlaceholders($properties)['html'];
    }

    public function testSetPlaceholdersVideoSourceKeepsMediaFromFirstSource(): void
    {
        // Регрессионный кейс (#2608-221): шаблон строки {foreach} строится по
        // ПОСЛЕДНЕМУ <source>. У video последний source — десктопный fallback без
        // media, поэтому media выпадал из вывода для ВСЕХ строк, хотя грабер
        // пишет его в данные каждой строки.
        $html = $this->mediaHtml(
            '<section data-mpc-section="t">'
            . '<video data-mpc-field="clip" muted loop>'
            .   '<source media="(max-width: 576px)" type="video/mp4" src="/mob.mp4">'
            .   '<source type="video/mp4" src="/pc.mp4">'
            . '</video>'
            . '</section>'
        );

        $this->assertStringContainsString('$source.media', $html, 'media должен попасть в шаблон строки');
        // Атрибут есть не у всех строк данных (у второй media = null) → условный
        // рендер: пустой media="" подходит под любое устройство и перехватывает
        // выбор у следующего source.
        $this->assertStringContainsString('{if $source.media} media="{$source.media}"{/if}', $html);
        $this->assertStringNotContainsString('media=""', $html);
    }

    public function testSetPlaceholdersVideoSourceMediaConditionalInStaticSection(): void
    {
        // В static-секции символ плейсхолдера `##` — условие обязано идти тем же
        // символом, иначе final-пасс не соберёт Fenom-тег.
        $html = $this->mediaHtml(
            '<section data-mpc-section="t">'
            . '<video data-mpc-field="clip" muted>'
            .   '<source media="(max-width: 576px)" type="video/mp4" src="/mob.mp4">'
            .   '<source type="video/mp4" src="/pc.mp4">'
            . '</video>'
            . '</section>',
            true
        );

        $this->assertStringContainsString('##if $source.media} media="##$source.media}"##/if}', $html);
    }

    public function testSetPlaceholdersVideoSourceWithoutMediaAnywhereStaysClean(): void
    {
        // Обратная сторона: media нет ни у одного source в вёрстке — не выдумываем
        // его и не добавляем пустой {if}.
        $html = $this->mediaHtml(
            '<section data-mpc-section="t">'
            . '<video data-mpc-field="clip" muted>'
            .   '<source type="video/mp4" src="/pc.mp4">'
            . '</video>'
            . '</section>'
        );

        $this->assertStringNotContainsString('$source.media', $html);
        $this->assertStringContainsString('$source.src', $html);
    }

    public function testSetPlaceholdersPictureSourceMediaRenderedConditionally(): void
    {
        // picture: media есть у всех source, поведение сохраняется — атрибут в
        // шаблоне остаётся, но выводится условно (в данных строки он может быть
        // очищен через админку).
        $html = $this->mediaHtml(
            '<section data-mpc-section="t">'
            . '<picture data-mpc-field="pic">'
            .   '<source media="(max-width: 576px)" srcset="/mob.webp">'
            .   '<source media="(max-width: 1200px)" srcset="/tab.webp">'
            .   '<img src="/pc.webp" alt="x">'
            . '</picture>'
            . '</section>'
        );

        $this->assertStringContainsString('{if $source.media} media="{$source.media}"{/if}', $html);
        $this->assertStringContainsString('$source.srcset', $html);
    }
}
