<?php

namespace MpcTests\Unit\Cutter;

use DiDom\Document;
use MpcServices\Handlers\Cutter\SectionFileWriter;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Unit-тесты SectionFileWriter::parseInnerChunks.
 *
 * Для элемента с data-mpc-copy parseInnerChunks делает continue ДО обращения
 * к processors/ФС, поэтому writer можно создать без конструктора и проверить
 * результат по приватному $chunkNames (имя copy-чанка туда не попадает →
 * файл не нарезается).
 */
class SectionFileWriterTest extends TestCase
{
    private function chunkNamesAfter(string $html): array
    {
        $ref    = new ReflectionClass(SectionFileWriter::class);
        $writer = $ref->newInstanceWithoutConstructor();

        $elements = (new Document($html))->find('[data-mpc-chunk]');
        $writer->parseInnerChunks($elements);

        $prop = $ref->getProperty('chunkNames');
        $prop->setAccessible(true);

        return $prop->getValue($writer);
    }

    /** data-mpc-copy → файл чанка НЕ нарезается (имя не попадает в обработку). */
    public function testChunkWithCopyIsNotSliced(): void
    {
        $names = $this->chunkNamesAfter(
            '<div data-mpc-chunk="copy_test.tpl" data-mpc-copy data-mpc-include><p>x</p></div>'
        );
        $this->assertNotContains('copy_test.tpl', $names);
    }

    /** data-mpc-copy со значением (имя оригинала) — тоже не нарезается. */
    public function testChunkWithCopyValueIsNotSliced(): void
    {
        $names = $this->chunkNamesAfter(
            '<div data-mpc-chunk="copy_test.tpl" data-mpc-copy="orig.tpl" data-mpc-include><p>x</p></div>'
        );
        $this->assertNotContains('copy_test.tpl', $names);
        $this->assertNotContains('orig.tpl', $names);
    }
}
