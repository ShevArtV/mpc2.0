<?php

namespace MpcTests\Unit\Grabber;

use MpcServices\Handlers\Grabber\MediaDownloader;
use MpcServices\Handlers\Grabber\ResourceFieldGrabber;
use MpcServices\Handlers\Parser;
use MpcTests\Stubs\ModxObjectStub;
use PHPUnit\Framework\TestCase;

/**
 * Тесты грабера полей ресурса (data-mpc-rfield / data-mpc-tv → resource).
 */
class ResourceFieldGrabberTest extends TestCase
{
    private function grabber(): ResourceFieldGrabber
    {
        return new ResourceFieldGrabber(new Parser());
    }

    public function testWritesRfieldAndTv(): void
    {
        $html = '<div>'
            . '<h1 data-mpc-rfield="pagetitle">Заголовок страницы</h1>'
            . '<div data-mpc-rfield="content">Контент</div>'
            . '<span data-mpc-tv="subtitle">Значение TV</span>'
            . '<img data-mpc-tv="cover" src="cover.jpg">'
            . '<a data-mpc-rfield="link_attributes" href="/path">x</a>'
            . '</div>';
        $res = new ModxObjectStub('modResource', ['id' => 5]);

        $written = $this->grabber()->grab($html, $res);

        $this->assertSame('Заголовок страницы', $res->get('pagetitle'));
        $this->assertSame('Контент', $res->get('content'));
        $this->assertSame('/path', $res->get('link_attributes'));
        $this->assertSame('Значение TV', $res->getTVValue('subtitle'));
        $this->assertSame('cover.jpg', $res->getTVValue('cover'), 'без MediaDownloader src сохраняется как есть');
        $this->assertArrayHasKey('pagetitle', $written['fields']);
        $this->assertArrayHasKey('cover', $written['tvs']);
    }

    public function testLocalizesImageSrcViaMediaDownloader(): void
    {
        // Стаб загрузчика: возвращает «локальный» URL вместо скачивания.
        $downloader = new class extends MediaDownloader {
            public function __construct() {}
            public function downloadImage(string $attrValue, string $language = ''): string
            {
                return '/assets/dl/' . basename((string)parse_url($attrValue, PHP_URL_PATH));
            }
        };
        $grabber = new ResourceFieldGrabber(new Parser(), [], $downloader);

        $html = '<div>'
            . '<img data-mpc-tv="cover" src="https://ext.example/img/bedroom.jpg">'
            . '<span data-mpc-tv="subtitle">текст</span>'
            . '</div>';
        $res = new ModxObjectStub('modResource', ['id' => 5]);

        $grabber->grab($html, $res);

        $this->assertSame('/assets/dl/bedroom.jpg', $res->getTVValue('cover'), 'src картинки локализован через MediaDownloader');
        $this->assertSame('текст', $res->getTVValue('subtitle'), 'текстовый TV загрузчиком не трогается');
    }

    public function testProtectedFieldsNotWritten(): void
    {
        $html = '<div>'
            . '<span data-mpc-rfield="alias">slug</span>'
            . '<span data-mpc-rfield="template">9</span>'
            . '<span data-mpc-rfield="parent">42</span>'
            . '<span data-mpc-rfield="class_key">modWebLink</span>'
            . '</div>';
        $res = new ModxObjectStub('modResource', ['id' => 5, 'alias' => 'orig', 'template' => 1, 'parent' => 0, 'class_key' => 'modDocument']);

        $written = $this->grabber()->grab($html, $res);

        $this->assertSame('orig', $res->get('alias'), 'alias защищён');
        $this->assertSame(1, $res->get('template'), 'template защищён');
        $this->assertSame(0, $res->get('parent'), 'parent защищён');
        $this->assertSame('modDocument', $res->get('class_key'), 'class_key защищён');
        $this->assertEmpty($written['fields']);
    }

    public function testCustomProtectedList(): void
    {
        $g = new ResourceFieldGrabber(new Parser(), ['alias', 'uri', 'template', 'pagetitle']);
        $html = '<h1 data-mpc-rfield="pagetitle">Новый</h1><div data-mpc-rfield="content">C</div>';
        $res = new ModxObjectStub('modResource', ['id' => 5, 'pagetitle' => 'Старый']);

        $g->grab($html, $res);

        $this->assertSame('Старый', $res->get('pagetitle'), 'pagetitle защищён кастомным списком');
        $this->assertSame('C', $res->get('content'));
    }

    public function testEmptyHtmlNoop(): void
    {
        $res = new ModxObjectStub('modResource', ['id' => 5]);
        $written = $this->grabber()->grab('<div><p>нет маркеров</p></div>', $res);
        $this->assertEmpty($written['fields']);
        $this->assertEmpty($written['tvs']);
    }

    private function lexManager(): \MpcServices\Handlers\Grabber\LexiconManager
    {
        return new \MpcServices\Handlers\Grabber\LexiconManager(new \MpcTests\Stubs\ModxStub(), [
            'useLexicons'              => true,
            'lexiconFilenameField'     => 'id',
            'allowedTags'              => [''],
            'allowModxTags'            => false,
            'translatableContentTypes' => ['text', 'image', 'poster', 'video', 'audio'],
        ]);
    }

    /** rfield лексиконится в ключ mpc_resource_<field> файла ресурса + колонка. */
    public function testLexiconizesRfieldWhenEnabled(): void
    {
        $lm = $this->lexManager();
        $grabber = new ResourceFieldGrabber(new Parser(), [], null, $lm, true);
        $res = new ModxObjectStub('modResource', ['id' => 7]);

        $grabber->grab('<h1 data-mpc-rfield="pagetitle">Заголовок</h1>', $res);

        $this->assertSame('mpc_resource_pagetitle', $res->get('pagetitle'));         // колонка = КЛЮЧ
        $this->assertSame('Заголовок', $lm->lexicons[7]['mpc_resource_pagetitle']);  // лексикон = значение
    }

    /** Поле из excludeLexiconFields не лексиконится: колонка = значение, лексикон пуст. */
    public function testExcludedRfieldNotLexiconized(): void
    {
        $lm = new \MpcServices\Handlers\Grabber\LexiconManager(new \MpcTests\Stubs\ModxStub(), [
            'useLexicons'          => true,
            'lexiconFilenameField' => 'id',
            'allowedTags'          => [''],
            'allowModxTags'        => false,
            'translatableContentTypes' => ['text', 'image', 'poster', 'video', 'audio'],
            'excludeLexiconFields' => ['pagetitle'],
        ]);
        $grabber = new ResourceFieldGrabber(new Parser(), [], null, $lm, true);
        $res = new ModxObjectStub('modResource', ['id' => 7]);

        $written = $grabber->grab('<h1 data-mpc-rfield="pagetitle">Заголовок</h1>', $res);

        $this->assertSame('Заголовок', $res->get('pagetitle'));            // колонка = ЗНАЧЕНИЕ, не ключ
        $this->assertSame([], $lm->lexicons);                              // лексикон не пишется
        $this->assertArrayHasKey('pagetitle', $written['fields']);         // поле всё равно записано
    }

    /** TV лексиконится в ключ mpc_resource_tv_<name>: TV-значение = ключ, лексикон = текст. */
    public function testLexiconizesTvWhenEnabled(): void
    {
        $lm = $this->lexManager();
        $grabber = new ResourceFieldGrabber(new Parser(), [], null, $lm, true);
        $res = new ModxObjectStub('modResource', ['id' => 7]);

        $grabber->grab('<span data-mpc-tv="subtitle">Подзаголовок</span>', $res);

        $this->assertSame('mpc_resource_tv_subtitle', $res->getTVValue('subtitle'));     // TV = КЛЮЧ
        $this->assertSame('Подзаголовок', $lm->lexicons[7]['mpc_resource_tv_subtitle']); // лексикон = значение
    }

    /** TV из excludeLexiconFields не лексиконится: TV = значение, лексикон пуст. */
    public function testExcludedTvNotLexiconized(): void
    {
        $lm = new \MpcServices\Handlers\Grabber\LexiconManager(new \MpcTests\Stubs\ModxStub(), [
            'useLexicons'          => true,
            'lexiconFilenameField' => 'id',
            'allowedTags'          => [''],
            'allowModxTags'        => false,
            'translatableContentTypes' => ['text', 'image', 'poster', 'video', 'audio'],
            'excludeLexiconFields' => ['subtitle'],
        ]);
        $grabber = new ResourceFieldGrabber(new Parser(), [], null, $lm, true);
        $res = new ModxObjectStub('modResource', ['id' => 7]);

        $grabber->grab('<span data-mpc-tv="subtitle">Подзаголовок</span>', $res);

        $this->assertSame('Подзаголовок', $res->getTVValue('subtitle')); // TV = ЗНАЧЕНИЕ, не ключ
        $this->assertSame([], $lm->lexicons);                            // лексикон не пишется
    }

    /** Канон: image-TV при mpc_translated_content БЕЗ 'image' НЕ лексиконится
     *  (хранит путь, не ключ) — content-type определяется по тегу маркера. */
    public function testImageTvNotLexiconizedWhenImageNotTranslatable(): void
    {
        $lm = new \MpcServices\Handlers\Grabber\LexiconManager(new \MpcTests\Stubs\ModxStub(), [
            'useLexicons'              => true,
            'lexiconFilenameField'     => 'id',
            'allowedTags'              => [''],
            'allowModxTags'            => false,
            'translatableContentTypes' => ['text', 'contact'], // image НЕ переводим
        ]);
        $grabber = new ResourceFieldGrabber(new Parser(), [], null, $lm, true);
        $res = new ModxObjectStub('modResource', ['id' => 7]);

        $grabber->grab('<img data-mpc-tv="cover" src="/img/cover.jpg"><span data-mpc-tv="subtitle">Текст</span>', $res);

        $this->assertSame('/img/cover.jpg', $res->getTVValue('cover'));               // image-TV: путь, НЕ ключ
        $this->assertSame('mpc_resource_tv_subtitle', $res->getTVValue('subtitle'));  // text-TV: ключ (text переводим)
        $this->assertArrayNotHasKey('mpc_resource_tv_cover', $lm->lexicons[7] ?? []); // ключ image не заведён
        $this->assertSame('Текст', $lm->lexicons[7]['mpc_resource_tv_subtitle']);
    }

    /** rfield и tv с ОДНИМ именем дают РАЗНЫЕ ключи лексикона (без коллизии). */
    public function testRfieldAndTvSameNameUseDistinctKeys(): void
    {
        $lm = $this->lexManager();
        $grabber = new ResourceFieldGrabber(new Parser(), [], null, $lm, true);
        $res = new ModxObjectStub('modResource', ['id' => 7]);

        $grabber->grab('<h1 data-mpc-rfield="title">Поле</h1><span data-mpc-tv="title">ТВ</span>', $res);

        $this->assertSame('Поле', $lm->lexicons[7]['mpc_resource_title']);    // rfield-ключ
        $this->assertSame('ТВ', $lm->lexicons[7]['mpc_resource_tv_title']);   // tv-ключ — отдельный
    }

    /** Поля внутри обёртки data-mpc-res (разметка редактора) на грабе игнорируются. */
    public function testIgnoresFieldsInsideResWrapper(): void
    {
        $html = '<div data-mpc-res="{$id}">'
            . '<h3 data-mpc-rfield="pagetitle">{$pagetitle}</h3>'
            . '<span data-mpc-tv="subtitle">{$subtitle}</span>'
            . '</div>'
            . '<h1 data-mpc-rfield="longtitle">Свой заголовок</h1>'; // вне обёртки — грабится
        $res = new ModxObjectStub('modResource', ['id' => 5]);

        $written = $this->grabber()->grab($html, $res);

        $this->assertArrayNotHasKey('pagetitle', $written['fields'], 'rfield внутри data-mpc-res пропущен');
        $this->assertArrayNotHasKey('subtitle', $written['tvs'], 'tv внутри data-mpc-res пропущен');
        $this->assertNull($res->get('pagetitle'));
        $this->assertArrayHasKey('longtitle', $written['fields'], 'поле вне обёртки грабится');
        $this->assertSame('Свой заголовок', $res->get('longtitle'));
    }

    /** При useLexicons=false лексикон не пишется (только колонка). */
    public function testNoLexiconWhenDisabled(): void
    {
        $lm = $this->lexManager();
        $grabber = new ResourceFieldGrabber(new Parser(), [], null, $lm, false);
        $res = new ModxObjectStub('modResource', ['id' => 7]);

        $grabber->grab('<h1 data-mpc-rfield="pagetitle">X</h1>', $res);

        $this->assertSame('X', $res->get('pagetitle'));
        $this->assertSame([], $lm->lexicons);
    }
}
