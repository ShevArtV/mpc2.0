<?php

namespace MpcTests\Unit\Grabber;

use MpcServices\Handlers\Grabber\ContentTypeHelper;
use PHPUnit\Framework\TestCase;

/** Юнит классификации content-type (вынос из LexiconManager). */
class ContentTypeHelperTest extends TestCase
{
    public function testContentTypeForTag(): void
    {
        $this->assertSame('image', ContentTypeHelper::contentTypeForTag('img'));
        $this->assertSame('image', ContentTypeHelper::contentTypeForTag('source'));
        $this->assertSame('image', ContentTypeHelper::contentTypeForTag('picture'));
        $this->assertSame('video', ContentTypeHelper::contentTypeForTag('video'));
        $this->assertSame('audio', ContentTypeHelper::contentTypeForTag('audio'));
        $this->assertSame('text', ContentTypeHelper::contentTypeForTag('div'));
        $this->assertSame('text', ContentTypeHelper::contentTypeForTag('a'));
        $this->assertSame('text', ContentTypeHelper::contentTypeForTag('H1')); // регистронезависимо
    }

    public function testContentTypeForTvType(): void
    {
        $this->assertSame('image', ContentTypeHelper::contentTypeForTvType('image'));
        $this->assertSame('image', ContentTypeHelper::contentTypeForTvType('image-plus'));
        $this->assertSame('text', ContentTypeHelper::contentTypeForTvType('text'));
        $this->assertSame('text', ContentTypeHelper::contentTypeForTvType('richtext'));
        $this->assertSame('text', ContentTypeHelper::contentTypeForTvType('TinyMCErte'));
        $this->assertSame('raw', ContentTypeHelper::contentTypeForTvType('number'));
        $this->assertSame('raw', ContentTypeHelper::contentTypeForTvType('listbox'));
        $this->assertSame('raw', ContentTypeHelper::contentTypeForTvType('email'));
    }
}
