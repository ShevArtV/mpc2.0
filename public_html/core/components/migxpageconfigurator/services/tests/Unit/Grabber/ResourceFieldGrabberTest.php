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
            'useLexicons'          => true,
            'lexiconFilenameField' => 'id',
            'allowedTags'          => [''],
            'allowModxTags'        => false,
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
            'excludeLexiconFields' => ['pagetitle'],
        ]);
        $grabber = new ResourceFieldGrabber(new Parser(), [], null, $lm, true);
        $res = new ModxObjectStub('modResource', ['id' => 7]);

        $written = $grabber->grab('<h1 data-mpc-rfield="pagetitle">Заголовок</h1>', $res);

        $this->assertSame('Заголовок', $res->get('pagetitle'));            // колонка = ЗНАЧЕНИЕ, не ключ
        $this->assertSame([], $lm->lexicons);                              // лексикон не пишется
        $this->assertArrayHasKey('pagetitle', $written['fields']);         // поле всё равно записано
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
