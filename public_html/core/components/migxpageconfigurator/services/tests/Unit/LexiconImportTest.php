<?php

namespace MpcTests\Unit;

use MpcServices\Handlers\LexiconImport;
use PHPUnit\Framework\TestCase;

/**
 * Тесты чистой логики импорта лексиконов (разбор заголовков/листа/diff/детект).
 */
class LexiconImportTest extends TestCase
{
    // parseHeaders ----------------------------------------------------

    public function testParseHeadersAllInOne(): void
    {
        // формат all-in-one: Контекст | lexicon_key | ru | en
        $h = LexiconImport::parseHeaders(['Контекст', 'lexicon_key', 'ru', 'en']);
        $this->assertSame(1, $h['keyIdx']);
        $this->assertSame([2 => 'ru', 3 => 'en'], $h['langCols']);
    }

    public function testParseHeadersLegacy(): void
    {
        // старый формат: lexicon_key первой колонкой
        $h = LexiconImport::parseHeaders(['lexicon_key', 'ru', 'en']);
        $this->assertSame(0, $h['keyIdx']);
        $this->assertSame([1 => 'ru', 2 => 'en'], $h['langCols']);
    }

    public function testParseHeadersNoKeyColumn(): void
    {
        $h = LexiconImport::parseHeaders(['Контекст', 'ru']);
        $this->assertNull($h['keyIdx']);
    }

    // sheetToData -----------------------------------------------------

    public function testSheetToDataIgnoresContextUsesNames(): void
    {
        $headers = ['Контекст', 'lexicon_key', 'ru', 'en'];
        $rows = [
            ['Секция: Заголовок', 'howto_title', 'Привет', 'Hello'],
            ['', 'howto_lead', 'Лид', ''],
            ['', '', 'пусто', 'empty'], // без ключа → пропуск
        ];
        $r = LexiconImport::sheetToData($headers, $rows);
        $this->assertSame(['ru', 'en'], $r['langs']);
        $this->assertSame([
            'howto_title' => ['ru' => 'Привет', 'en' => 'Hello'],
            'howto_lead'  => ['ru' => 'Лид', 'en' => ''],
        ], $r['data']);
    }

    public function testSheetToDataNoKeyColumnEmpty(): void
    {
        $r = LexiconImport::sheetToData(['Контекст', 'ru'], [['x', 'y']]);
        $this->assertSame([], $r['data']);
    }

    // computeDiff -----------------------------------------------------

    public function testComputeDiffCountsNewAndChanged(): void
    {
        $data = [
            'a' => ['ru' => 'А-новое'],          // нет нигде → new
            'b' => ['ru' => 'Б-изменено'],        // есть, отличается → changed
            'c' => ['ru' => 'Ц'],                 // есть, совпадает → не считается
        ];
        $existing = ['ru' => ['b' => 'Б-старое', 'c' => 'Ц']];
        $d = LexiconImport::computeDiff($data, $existing);
        $this->assertSame(['keys' => 3, 'new' => 1, 'changed' => 1], $d);
    }

    public function testComputeDiffEmptyValueNotChange(): void
    {
        // пустое входное значение не считается изменением
        $data = ['b' => ['ru' => '']];
        $existing = ['ru' => ['b' => 'есть']];
        $d = LexiconImport::computeDiff($data, $existing);
        $this->assertSame(0, $d['changed']);
    }

    // resolveTarget ---------------------------------------------------

    public function testResolveTargetBySheetName(): void
    {
        $this->assertSame('tpl-shablon-primer',
            LexiconImport::resolveTarget('tpl-shablon-primer', ['tpl-shablon-primer', 'contacts'], 'static'));
    }

    public function testResolveTargetStatic(): void
    {
        $this->assertSame('page-types', LexiconImport::resolveTarget('Static', ['page-types'], 'page-types'));
    }

    public function testResolveTargetUnrecognized(): void
    {
        $this->assertNull(LexiconImport::resolveTarget('Resource', ['contacts'], 'static'));
        $this->assertNull(LexiconImport::resolveTarget('Неизвестная', ['contacts'], 'static'));
    }
}
