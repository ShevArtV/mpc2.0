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

    // sheetNameFor ----------------------------------------------------

    public function testSheetNameShortRidUnchanged(): void
    {
        $this->assertSame('tpl-shablon-primer', LexiconImport::sheetNameFor('tpl-shablon-primer'));
        // ровно 31 символ ещё влезает как есть
        $rid = str_repeat('a', 31);
        $this->assertSame($rid, LexiconImport::sheetNameFor($rid));
    }

    public function testSheetNameLongRidFitsLimitAndStaysUnique(): void
    {
        // алиасы с одинаковыми первыми 31 символами: раньше обе вкладки
        // сваливались в одно имя (+ порядковый суффикс) и импорт их путал
        $a = 'uslugi-remont-kvartir-pod-klyuch-v-moskve';
        $b = 'uslugi-remont-kvartir-pod-klyuch-v-spb';
        $nameA = LexiconImport::sheetNameFor($a);
        $nameB = LexiconImport::sheetNameFor($b);

        $this->assertSame(31, mb_strlen($nameA, 'UTF-8'));
        $this->assertSame(31, mb_strlen($nameB, 'UTF-8'));
        $this->assertNotSame($nameA, $nameB);
        $this->assertStringStartsWith('uslugi-remont-kvartir-p~', $nameA);
    }

    public function testSheetNameForbiddenCharsGetHash(): void
    {
        // одинаковый результат очистки у разных rid → различаем хешем
        $this->assertNotSame(
            LexiconImport::sheetNameFor('a/b'),
            LexiconImport::sheetNameFor('a:b')
        );
        // короткий rid → короткое имя, хеш добавляется только ради однозначности
        $this->assertSame('a_b~' . substr(sha1('a/b'), 0, 7), LexiconImport::sheetNameFor('a/b'));
    }

    public function testSheetNameIsDeterministic(): void
    {
        $rid = 'ochen-dlinnyy-alias-stranicy-uslug-i-cen';
        $this->assertSame(LexiconImport::sheetNameFor($rid), LexiconImport::sheetNameFor($rid));
    }

    // resolveTarget: длинные имена, манифест, старые выгрузки ----------

    public function testResolveTargetByHashedSheetName(): void
    {
        $rid   = 'uslugi-remont-kvartir-pod-klyuch-v-moskve';
        $other = 'uslugi-remont-kvartir-pod-klyuch-v-spb';
        $this->assertSame(
            $rid,
            LexiconImport::resolveTarget(LexiconImport::sheetNameFor($rid), [$other, $rid], 'static')
        );
    }

    public function testResolveTargetPrefersManifest(): void
    {
        // вкладку переименовали руками — манифест всё равно знает адресата
        $this->assertSame(
            'contacts',
            LexiconImport::resolveTarget('Лист1', ['contacts', 'page-types'], 'static', 'contacts')
        );
    }

    public function testResolveTargetIgnoresManifestForMissingRid(): void
    {
        // ресурс удалили после экспорта → не подставляем, отдаём в ручной ремап
        $this->assertNull(
            LexiconImport::resolveTarget('Лист1', ['contacts'], 'static', 'udalennyy')
        );
    }

    public function testResolveTargetLegacyTruncatedUnique(): void
    {
        // выгрузка до хеш-суффикса: имя = rid, обрезанный до 31 символа
        $rid  = 'uslugi-remont-kvartir-pod-klyuch-v-moskve';
        $name = mb_substr($rid, 0, 31, 'UTF-8');
        $this->assertSame($rid, LexiconImport::resolveTarget($name, ['contacts', $rid], 'static'));
    }

    public function testResolveTargetLegacyTruncatedAmbiguous(): void
    {
        // два кандидата с общим префиксом — гадать нельзя, только ручной ремап
        $a = 'uslugi-remont-kvartir-pod-klyuch-v-moskve';
        $b = 'uslugi-remont-kvartir-pod-klyuch-v-spb';
        $name = mb_substr($a, 0, 31, 'UTF-8');
        $this->assertNull(LexiconImport::resolveTarget($name, [$a, $b], 'static'));
    }

    // parseManifest ---------------------------------------------------

    public function testParseManifestBuildsMap(): void
    {
        $map = LexiconImport::parseManifest(
            ['sheet', 'rid'],
            [['uslugi-remont-kvartir-p~1a2b3c4', 'uslugi-remont-kvartir-pod-klyuch-v-moskve'], ['contacts', 'contacts'], ['', 'x']]
        );
        $this->assertSame([
            'uslugi-remont-kvartir-p~1a2b3c4' => 'uslugi-remont-kvartir-pod-klyuch-v-moskve',
            'contacts' => 'contacts',
        ], $map);
    }

    public function testParseManifestNotAManifest(): void
    {
        $this->assertSame([], LexiconImport::parseManifest(['Контекст', 'lexicon_key', 'ru'], [['a', 'b', 'c']]));
    }
}
