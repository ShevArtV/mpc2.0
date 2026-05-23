<?php

namespace MpcTests\Unit\Grabber;

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
        $this->assertSame('cover.jpg', $res->getTVValue('cover'));
        $this->assertArrayHasKey('pagetitle', $written['fields']);
        $this->assertArrayHasKey('cover', $written['tvs']);
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
}
