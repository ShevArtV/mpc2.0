<?php

namespace MpcTests\Unit;

use MpcServices\Handlers\MediaLexiconMerger;
use PHPUnit\Framework\TestCase;

/**
 * Юнит лексикон-мержа media/record (вынос из FieldWriter). Fake LexiconWriter
 * (has по списку, set в журнал) — проверяем сохранение ключей/запись значений.
 */
class MediaLexiconMergerTest extends TestCase
{
    /** Fake LexiconWriter: has() по списку ключей, set() пишет в журнал. */
    private function fakeLex(array $keys, array &$log): object
    {
        return new class($keys, $log) {
            private array $keys;
            private array $log;
            public function __construct(array $keys, array &$log) { $this->keys = $keys; $this->log = &$log; }
            public function has(string $ident, string $key): bool { return in_array($key, $this->keys, true); }
            public function set(string $ident, string $key, string $value): bool { $this->log[$key] = $value; return true; }
        };
    }

    /** Лексиконная запись: ключи src/alt сохраняются, лексикон обновляется, width — литерал. */
    public function testMergeRecordKeepsKeysAndWritesLexicon(): void
    {
        $log = [];
        $m = new MediaLexiconMerger($this->fakeLex(['hero_img', 'hero_img_alt'], $log), 'res');

        $cur = '[{"MIGX_id":1,"src":"hero_img","alt":"hero_img_alt","width":"100"}]';
        $new = '[{"MIGX_id":1,"src":"/new.jpg","alt":"Новый alt","width":"200"}]';
        $out = json_decode($m->mergeRecordWithLexicon($cur, $new), true);

        $this->assertSame('hero_img', $out[0]['src']);        // ключ сохранён
        $this->assertSame('hero_img_alt', $out[0]['alt']);    // ключ сохранён
        $this->assertSame('200', $out[0]['width']);           // литерал обновлён
        $this->assertSame('/new.jpg', $log['hero_img']);      // лексикон обновлён
        $this->assertSame('Новый alt', $log['hero_img_alt']);
    }

    /** Не-лексиконная запись (ключей нет) → литералы как есть. */
    public function testMergeRecordWithoutLexiconKeysIsLiteral(): void
    {
        $log = [];
        $m = new MediaLexiconMerger($this->fakeLex([], $log), 'res');

        $cur = '[{"src":"/old.jpg","alt":"old"}]';
        $new = '[{"src":"/new.jpg","alt":"new"}]';
        $out = json_decode($m->mergeRecordWithLexicon($cur, $new), true);

        $this->assertSame('/new.jpg', $out[0]['src']);
        $this->assertSame('new', $out[0]['alt']);
        $this->assertSame([], $log);
    }

    public function testIsMediaWithSources(): void
    {
        $log = [];
        $m = new MediaLexiconMerger($this->fakeLex([], $log), 'res');
        $this->assertTrue($m->isMediaWithSources('[{"sources":[]}]'));
        $this->assertTrue($m->isMediaWithSources('[{"img":"x"}]'));
        $this->assertFalse($m->isMediaWithSources('[{"src":"x"}]'));
        $this->assertFalse($m->isMediaWithSources('hero_title'));
    }

    /** Новая media-запись: src/alt → сгенерённые ключи + запись в лексикон; width — литерал. */
    public function testNewRecordGeneratesKeys(): void
    {
        $log = [];
        $m = new MediaLexiconMerger($this->fakeLex([], $log), 'res');
        $address = ['section' => 'hero', 'fieldName' => 'photo', 'parentField' => '', 'idx' => ''];
        $new = '[{"MIGX_id":1,"src":"/p.jpg","alt":"подпись","width":"100"}]';

        $out = json_decode($m->newRecordWithLexiconKeys($address, $new, 'hero'), true);
        $this->assertSame('hero_photo', $out[0]['src']);       // ключ src
        $this->assertSame('hero_photo_alt', $out[0]['alt']);   // ключ alt
        $this->assertSame('100', $out[0]['width']);            // литерал
        $this->assertSame('/p.jpg', $log['hero_photo']);
        $this->assertSame('подпись', $log['hero_photo_alt']);
    }

    /** Пустой prefix → лексиконизации нет, значения литералом. */
    public function testNewRecordEmptyPrefixIsLiteral(): void
    {
        $log = [];
        $m = new MediaLexiconMerger($this->fakeLex([], $log), 'res');
        $address = ['section' => 'hero', 'fieldName' => 'photo'];
        $out = json_decode($m->newRecordWithLexiconKeys($address, '[{"src":"/p.jpg"}]', ''), true);
        $this->assertSame('/p.jpg', $out[0]['src']);
        $this->assertSame([], $log);
    }

    /**
     * Предикат exclude (2.5.59-rc): исключённый ключ НЕ заводится у новой записи —
     * значение остаётся литералом, а соседнее поле лексиконизируется как обычно.
     */
    public function testExcludedKeyIsNotCreatedForNewRecord(): void
    {
        $log = [];
        $m = new MediaLexiconMerger(
            $this->fakeLex([], $log),
            'res',
            fn(string $key): bool => $key === 'hero_photo'   // исключён только src
        );
        $address = ['section' => 'hero', 'fieldName' => 'photo', 'parentField' => '', 'idx' => ''];
        $new = '[{"MIGX_id":1,"src":"/p.jpg","alt":"подпись","width":"100"}]';

        $out = json_decode($m->newRecordWithLexiconKeys($address, $new, 'hero'), true);

        $this->assertSame('/p.jpg', $out[0]['src']);           // литерал: ключ исключён
        $this->assertArrayNotHasKey('hero_photo', $log);       // в лексикон не писали
        $this->assertSame('hero_photo_alt', $out[0]['alt']);   // соседнее поле — как раньше
        $this->assertSame('подпись', $log['hero_photo_alt']);
    }

    /**
     * Существующий ключ, попавший под exclude, больше не переиспользуется плоским
     * мержем: значение уходит в конфиг литералом, лексикон не трогаем.
     */
    public function testExcludedExistingKeyNotReusedInFlatMerge(): void
    {
        $log = [];
        $m = new MediaLexiconMerger(
            $this->fakeLex(['hero_img', 'hero_img_alt'], $log),
            'res',
            fn(string $key): bool => $key === 'hero_img'
        );

        $cur = '[{"MIGX_id":1,"src":"hero_img","alt":"hero_img_alt","width":"100"}]';
        $new = '[{"MIGX_id":1,"src":"/new.jpg","alt":"Новый alt","width":"200"}]';
        $out = json_decode($m->mergeRecordWithLexicon($cur, $new), true);

        $this->assertSame('/new.jpg', $out[0]['src']);         // ключ вытеснен литералом
        $this->assertArrayNotHasKey('hero_img', $log);         // старый ключ не наполняется
        $this->assertSame('hero_img_alt', $out[0]['alt']);     // не исключённый — как раньше
        $this->assertSame('Новый alt', $log['hero_img_alt']);
    }

    /**
     * То же для глубокого мержа picture: исключённый ключ вложенного img и
     * исключённый ключ source не переиспользуются.
     */
    public function testExcludedExistingKeyNotReusedInMediaRecord(): void
    {
        $log = [];
        $m = new MediaLexiconMerger(
            $this->fakeLex(['media_cover', 'media_cover_alt', 'media_cover_source'], $log),
            'res',
            fn(string $key): bool => in_array($key, ['media_cover', 'media_cover_source'], true)
        );

        $cur = json_encode([[
            'MIGX_id' => 1,
            'img' => json_encode([['MIGX_id' => 1, 'src' => 'media_cover', 'alt' => 'media_cover_alt']], JSON_UNESCAPED_UNICODE),
            'sources' => [['MIGX_id' => 1, 'media' => '(min-width: 992px)', 'srcset' => 'media_cover_source']],
        ]], JSON_UNESCAPED_UNICODE);
        $new = json_encode([[
            'MIGX_id' => 1,
            'img' => json_encode([['MIGX_id' => 1, 'src' => '/new-main.jpg', 'alt' => 'Новый alt']], JSON_UNESCAPED_UNICODE),
            'sources' => [['MIGX_id' => 1, 'media' => '(min-width: 992px)', 'srcset' => '/new-src.webp']],
        ]], JSON_UNESCAPED_UNICODE);

        $out = json_decode($m->mergeMediaRecord($cur, $new, 'media', 'cover'), true);
        $img = json_decode($out[0]['img'], true);

        $this->assertSame('/new-main.jpg', $img[0]['src']);                 // литерал
        $this->assertArrayNotHasKey('media_cover', $log);
        $this->assertSame('/new-src.webp', $out[0]['sources'][0]['srcset']); // литерал
        $this->assertArrayNotHasKey('media_cover_source', $log);
        $this->assertSame('media_cover_alt', $img[0]['alt']);                // не исключён
        $this->assertSame('Новый alt', $log['media_cover_alt']);
    }

    /** Без предиката (внешние вызовы) поведение прежнее — все листья лексиконизируются. */
    public function testWithoutPredicateAllKeysAreLexiconized(): void
    {
        $log = [];
        $m = new MediaLexiconMerger($this->fakeLex([], $log), 'res', null);
        $address = ['section' => 'hero', 'fieldName' => 'photo', 'parentField' => '', 'idx' => ''];

        $out = json_decode($m->newRecordWithLexiconKeys($address, '[{"src":"/p.jpg","alt":"подпись"}]', 'hero'), true);

        $this->assertSame('hero_photo', $out[0]['src']);
        $this->assertSame('hero_photo_alt', $out[0]['alt']);
        $this->assertSame(['hero_photo' => '/p.jpg', 'hero_photo_alt' => 'подпись'], $log);
    }
}
