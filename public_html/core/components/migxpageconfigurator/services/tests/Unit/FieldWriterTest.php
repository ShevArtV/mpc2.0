<?php

namespace MpcTests\Unit;

use MpcServices\Handlers\FieldWriter;
use MpcTests\Stubs\ModxObjectStub;
use MpcTests\Stubs\ModxStub;
use PHPUnit\Framework\TestCase;

/**
 * Тесты write-API mpc 2.4.0 (FieldWriter).
 * Покрыты реализованные пути: rfield (нативная колонка) и tv. type=field —
 * заглушка (M2). Запись/чтение проверяются через ModxObjectStub.
 */
class FieldWriterTest extends TestCase
{
    /**
     * modX-стаб, отдающий контролируемый resource-объект по getObject('modResource').
     */
    private function makeModx(ModxObjectStub $resource, array $configOverrides = []): ModxStub
    {
        return new class($resource, $configOverrides) extends ModxStub {
            private ModxObjectStub $res;

            public function __construct(ModxObjectStub $res, array $cfg)
            {
                parent::__construct(null, $cfg);
                $this->res = $res;
            }

            public function getObject(string $class, $conditions = null): ?object
            {
                return $class === 'modResource' ? $this->res : null;
            }
        };
    }

    public function testWritesAllowedResourceField(): void
    {
        $resource = new ModxObjectStub('modResource', ['id' => 5, 'context_key' => 'web']);
        $writer = new FieldWriter($this->makeModx($resource));

        $result = $writer->write(['type' => 'rfield', 'resourceId' => 5, 'fieldName' => 'content'], 'Новый текст');

        $this->assertTrue($result['success'], $result['message']);
        $this->assertSame('Новый текст', $resource->get('content'));
    }

    public function testRejectsNonEditableResourceField(): void
    {
        $resource = new ModxObjectStub('modResource', ['id' => 5]);
        $writer = new FieldWriter($this->makeModx($resource));

        $result = $writer->write(['type' => 'rfield', 'resourceId' => 5, 'fieldName' => 'template'], 7);

        $this->assertFalse($result['success']);
        $this->assertNull($resource->get('template'));
    }

    public function testWritesTvValue(): void
    {
        $resource = new ModxObjectStub('modResource', ['id' => 5, 'context_key' => 'web']);
        $writer = new FieldWriter($this->makeModx($resource));

        $result = $writer->write(['type' => 'tv', 'resourceId' => 5, 'fieldName' => 'subtitle'], 'TV value');

        $this->assertTrue($result['success'], $result['message']);
        $this->assertSame('TV value', $resource->getTVValue('subtitle'));
    }

    /**
     * Лексиконный режим: если у TV есть ключ mpc_resource_tv_<name> — правка
     * уходит в ЛЕКСИКОН, а само TV-значение (колонку) не трогаем (зеркало rfield).
     */
    public function testWritesTvLexicon(): void
    {
        $resource = new ModxObjectStub('modResource', ['id' => 5, 'context_key' => 'web']);
        $writer = new FieldWriter($this->makeModx($resource));

        $log = [];
        $lex = new class($log) extends \MpcServices\Handlers\LexiconWriter {
            private array $log;
            public function __construct(array &$log) { $this->log = &$log; }
            public function identifier(int $rid): string { return 'res' . $rid; }
            public function set(string $identifier, string $key, string $value): bool { $this->log[$key] = $value; return true; }
        };

        // Инжектим useLexicons + фейковый LexiconWriter (избегаем файловой записи).
        $ref = new \ReflectionObject($writer);
        $ul = $ref->getProperty('useLexicons');       $ul->setAccessible(true); $ul->setValue($writer, true);
        $lw = $ref->getProperty('lexWriterInstance');  $lw->setAccessible(true); $lw->setValue($writer, $lex);

        $result = $writer->write(['type' => 'tv', 'resourceId' => 5, 'fieldName' => 'subtitle'], 'Перевод');

        $this->assertTrue($result['success'], $result['message']);
        $this->assertSame('Перевод', $log['mpc_resource_tv_subtitle']);                  // значение → словарь
        $this->assertSame('mpc_resource_tv_subtitle', $resource->getTVValue('subtitle')); // в TV-значение → ключ
    }

    /** rfield при лексиконах: значение → словарь, в колонку ресурса → ключ. */
    public function testWritesRfieldLexicon(): void
    {
        $resource = new ModxObjectStub('modResource', ['id' => 5, 'context_key' => 'web']);
        $writer = new FieldWriter($this->makeModx($resource));

        $log = [];
        $lex = new class($log) extends \MpcServices\Handlers\LexiconWriter {
            private array $log;
            public function __construct(array &$log) { $this->log = &$log; }
            public function identifier(int $rid): string { return 'res' . $rid; }
            public function set(string $identifier, string $key, string $value): bool { $this->log[$key] = $value; return true; }
        };

        $ref = new \ReflectionObject($writer);
        $ul = $ref->getProperty('useLexicons');       $ul->setAccessible(true); $ul->setValue($writer, true);
        $lw = $ref->getProperty('lexWriterInstance');  $lw->setAccessible(true); $lw->setValue($writer, $lex);

        $result = $writer->write(['type' => 'rfield', 'resourceId' => 5, 'fieldName' => 'content'], 'Текст');

        $this->assertTrue($result['success'], $result['message']);
        $this->assertSame('Текст', $log['mpc_resource_content']);          // значение → словарь
        $this->assertSame('mpc_resource_content', $resource->get('content')); // в колонку → ключ
    }

    /** Канон: rfield/tv тоже учитывают mpc_translated_content. Если content-type не
     *  переводим — пишем в КОЛОНКУ, даже если лексикон-ключ у ресурса есть. */
    public function testRfieldAndTvStayInColumnWhenNotTranslatable(): void
    {
        $makeLex = function (array &$log) {
            return new class($log) extends \MpcServices\Handlers\LexiconWriter {
                private array $log;
                public function __construct(array &$log) { $this->log = &$log; }
                public function identifier(int $rid): string { return 'res' . $rid; }
                public function has(string $identifier, string $key): bool { return true; } // ключ якобы ЕСТЬ
                public function set(string $identifier, string $key, string $value): bool { $this->log[$key] = $value; return true; }
            };
        };
        $inject = function (FieldWriter $writer, $lex) {
            $ref = new \ReflectionObject($writer);
            $ul = $ref->getProperty('useLexicons');      $ul->setAccessible(true); $ul->setValue($writer, true);
            $lw = $ref->getProperty('lexWriterInstance'); $lw->setAccessible(true); $lw->setValue($writer, $lex);
        };

        // rfield (content-type 'text') при mpc_translated_content='image' → колонка
        $res1 = new ModxObjectStub('modResource', ['id' => 5, 'context_key' => 'web']);
        $w1 = new FieldWriter($this->makeModx($res1, ['mpc_translated_content' => 'image']));
        $log1 = []; $inject($w1, $makeLex($log1));
        $r1 = $w1->write(['type' => 'rfield', 'resourceId' => 5, 'fieldName' => 'content'], 'Текст');
        $this->assertTrue($r1['success'], $r1['message']);
        $this->assertSame([], $log1);                     // НЕ в лексикон
        $this->assertSame('Текст', $res1->get('content')); // в колонку

        // tv при mpc_translated_content='image' → колонка
        $res2 = new ModxObjectStub('modResource', ['id' => 5, 'context_key' => 'web']);
        $w2 = new FieldWriter($this->makeModx($res2, ['mpc_translated_content' => 'image']));
        $log2 = []; $inject($w2, $makeLex($log2));
        $r2 = $w2->write(['type' => 'tv', 'resourceId' => 5, 'fieldName' => 'subtitle'], 'Текст');
        $this->assertTrue($r2['success'], $r2['message']);
        $this->assertSame([], $log2);                          // НЕ в лексикон
        $this->assertSame('Текст', $res2->getTVValue('subtitle')); // в колонку
    }

    public function testRejectsInvalidAddress(): void
    {
        $resource = new ModxObjectStub('modResource', ['id' => 5]);
        $writer = new FieldWriter($this->makeModx($resource));

        $this->assertFalse($writer->write(['type' => 'rfield', 'fieldName' => 'content'], 'x')['success']);
        $this->assertFalse($writer->write(['type' => 'rfield', 'resourceId' => 5], 'x')['success']);
    }

    public function testWritesConfigFieldLocalLevel(): void
    {
        $config = json_encode([
            '1' => ['section_name' => 'hero', 'MIGX_formname' => 'mpc_hero', 'title' => 'Old'],
        ], JSON_UNESCAPED_UNICODE);
        $resource = new ModxObjectStub('modResource', ['id' => 5, 'context_key' => 'web', 'tv_mpc_config' => $config]);
        $writer = new FieldWriter($this->makeModx($resource));

        $result = $writer->write(
            ['type' => 'field', 'level' => 'local', 'resourceId' => 5, 'section' => 'hero', 'fieldName' => 'title'],
            'New'
        );

        $this->assertTrue($result['success'], $result['message']);
        $stored = json_decode($resource->getTVValue('mpc_config'), true);
        $this->assertSame('New', $stored['1']['title']);
    }

    /** Fake LexiconWriter: has() по списку ключей, set() в журнал. */
    private function injectLex(FieldWriter $writer, array $keysPresent, array &$log): void
    {
        $lex = new class($keysPresent, $log) extends \MpcServices\Handlers\LexiconWriter {
            private array $keys;
            private array $log;
            public function __construct(array $keys, array &$log) { $this->keys = $keys; $this->log = &$log; }
            public function identifier(int $rid): string { return 'res' . $rid; }
            public function has(string $identifier, string $key): bool { return in_array($key, $this->keys, true); }
            public function set(string $identifier, string $key, string $value): bool { $this->log[$key] = $value; return true; }
        };
        $ref = new \ReflectionObject($writer);
        $ul = $ref->getProperty('useLexicons');      $ul->setAccessible(true); $ul->setValue($writer, true);
        $lw = $ref->getProperty('lexWriterInstance'); $lw->setAccessible(true); $lw->setValue($writer, $lex);
    }

    /**
     * Задаёт список exclude_lexicons для писателя (свойство lmProps читается при
     * ленивом создании LexiconManager, поэтому достаточно подменить его до write).
     */
    private function injectExclude(FieldWriter $writer, array $patterns): void
    {
        $ref = new \ReflectionObject($writer);
        $lp = $ref->getProperty('lmProps'); $lp->setAccessible(true);
        $props = $lp->getValue($writer);
        $props['excludeLexiconFields'] = $patterns;
        $lp->setValue($writer, $props);
    }

    private function listConfig(): string
    {
        return json_encode([
            '1' => [
                'section_name'   => 'hero',
                'MIGX_formname'  => 'mpc_hero',
                'lexicon_prefix' => 'hero',
                'cards'          => json_encode([
                    ['MIGX_id' => 1, 'title' => 'hero_cards_title'],   // строка idx0 с лексикон-ключом (idx0 → без суффикса)
                    ['MIGX_id' => 2, 'title' => ''],                   // новая пустая строка
                ], JSON_UNESCAPED_UNICODE),
            ],
        ], JSON_UNESCAPED_UNICODE);
    }

    /** Новое поле лексиконизированного списка → генерится ключ + значение в лексикон, в конфиг ключ. */
    public function testNewRowFieldGetsLexiconKey(): void
    {
        $resource = new ModxObjectStub('modResource', ['id' => 5, 'context_key' => 'web', 'tv_mpc_config' => $this->listConfig()]);
        $writer = new FieldWriter($this->makeModx($resource));
        $log = [];
        $this->injectLex($writer, ['hero_cards_title'], $log); // у соседней строки (idx0) ключ ЕСТЬ

        $res = $writer->write(
            ['type' => 'field', 'level' => 'resource', 'resourceId' => 5, 'section' => 'hero', 'fieldName' => 'title', 'parentField' => 'cards', 'idx' => 1],
            'Новый текст'
        );

        $this->assertTrue($res['success'], $res['message']);
        $this->assertSame('Новый текст', $log['hero_cards_1_title_1']); // значение в лексиконе под сгенерированным ключом (idx1 → схема грабера)
        $cards = json_decode(json_decode($resource->getTVValue('mpc_config'), true)['1']['cards'], true);
        $this->assertSame('hero_cards_1_title_1', $cards[1]['title']);  // в конфиг лёг КЛЮЧ
    }

    /** Конфиг с ВЛОЖЕННЫМ списком: элемент [0] имеет вложенную строку с ключом,
     *  элемент [1] (новый) — вложенный список пуст (deep-clone). Как реальный
     *  грабер: вложенный список — нативный массив внутри строки. */
    private function nestedListConfig(): string
    {
        return json_encode([
            '1' => [
                'section_name'   => 'inner',
                'MIGX_formname'  => 'mpc_inner',
                'lexicon_prefix' => 'inner',
                'list_of_lists'  => json_encode([
                    ['MIGX_id' => 1, 'title' => 'inner_list_of_lists_title', 'list_triple_img' => [
                        ['MIGX_id' => 1, 'title' => 'inner_list_of_lists_list_triple_img_title'], // существующий КЛЮЧ
                    ]],
                    ['MIGX_id' => 2, 'title' => '', 'list_triple_img' => [
                        ['MIGX_id' => 1, 'title' => ''], // новый элемент: вложенная строка пуста
                    ]],
                ], JSON_UNESCAPED_UNICODE),
            ],
        ], JSON_UNESCAPED_UNICODE);
    }

    /** Поле ВЛОЖЕННОЙ строки НОВОГО элемента лексиконизируется по КАНОНУ
     *  (content-type 'text' ∈ mpc_translated_content), даже если свой вложенный
     *  список пуст — решение не зависит от соседних ключей. Ключ по схеме грабера. */
    public function testNewNestedFieldGetsLexiconKey(): void
    {
        $resource = new ModxObjectStub('modResource', ['id' => 5, 'context_key' => 'web', 'tv_mpc_config' => $this->nestedListConfig()]);
        $writer = new FieldWriter($this->makeModx($resource));
        $log = [];
        $this->injectLex($writer, [], $log); // соседних ключей нет — решает настройка, не precedent

        $res = $writer->write([
            'type' => 'field', 'level' => 'resource', 'resourceId' => 5, 'section' => 'inner', 'fieldName' => 'title',
            'path' => [['field' => 'list_of_lists', 'idx' => 1], ['field' => 'list_triple_img', 'idx' => 0]],
        ], 'Вложенный новый');

        $this->assertTrue($res['success'], $res['message']);
        // ключ сгенерён по схеме грабера для [1][0] и значение ушло в лексикон
        $this->assertSame('Вложенный новый', $log['inner_list_of_lists_1_list_triple_img_title']);
        // в конфиг (вложенную строку) лёг КЛЮЧ, а не литерал
        $lol = json_decode(json_decode($resource->getTVValue('mpc_config'), true)['1']['list_of_lists'], true);
        $lti = $lol[1]['list_triple_img'];
        $lti = is_string($lti) ? json_decode($lti, true) : $lti;
        $this->assertSame('inner_list_of_lists_1_list_triple_img_title', $lti[0]['title']);
    }

    /** Канон: решает mpc_translated_content, а не соседи. Если 'text' НЕ в списке
     *  переводимых типов → текстовое поле пишется литералом, даже если у соседа ключ. */
    public function testNewRowFieldStaysLiteralWhenContentTypeNotTranslatable(): void
    {
        $resource = new ModxObjectStub('modResource', ['id' => 5, 'context_key' => 'web', 'tv_mpc_config' => $this->listConfig()]);
        // mpc_translated_content без 'text' → текст не лексиконится (хотя у соседа idx0 ключ ЕСТЬ)
        $writer = new FieldWriter($this->makeModx($resource, ['mpc_translated_content' => 'image']));
        $log = [];
        $this->injectLex($writer, ['hero_cards_title'], $log);

        $res = $writer->write(
            ['type' => 'field', 'level' => 'resource', 'resourceId' => 5, 'section' => 'hero', 'fieldName' => 'title', 'parentField' => 'cards', 'idx' => 1],
            'Литерал'
        );

        $this->assertTrue($res['success'], $res['message']);
        $this->assertSame([], $log);                                  // в лексикон ничего не писали
        $cards = json_decode(json_decode($resource->getTVValue('mpc_config'), true)['1']['cards'], true);
        $this->assertSame('Литерал', $cards[1]['title']);             // литерал в конфиге
    }

    /** Новая media-запись (картинка строки list_images) → src/alt получают лексикон-ключи. */
    public function testNewMediaRecordGetsLexiconKeys(): void
    {
        $config = json_encode([
            '1' => [
                'section_name'   => 'gallery',
                'MIGX_formname'  => 'mpc_gallery',
                'lexicon_prefix' => 'gallery',
                'list_images'    => json_encode([
                    ['MIGX_id' => 1, 'img' => ''], // новая пустая строка
                ], JSON_UNESCAPED_UNICODE),
            ],
        ], JSON_UNESCAPED_UNICODE);
        $resource = new ModxObjectStub('modResource', ['id' => 5, 'context_key' => 'web', 'tv_mpc_config' => $config]);
        $writer = new FieldWriter($this->makeModx($resource));
        $log = [];
        $this->injectLex($writer, [], $log); // ключей ещё нет

        $record = json_encode([
            ['MIGX_id' => 1, 'src' => '/new.jpg', 'alt' => 'Картинка', 'width' => '500', 'height' => '350'],
        ], JSON_UNESCAPED_UNICODE);
        $res = $writer->write(
            ['type' => 'field', 'level' => 'resource', 'resourceId' => 5, 'section' => 'gallery', 'parentField' => 'list_images', 'idx' => 0, 'fieldName' => 'img'],
            $record
        );

        $this->assertTrue($res['success'], $res['message']);
        // idx=0 → ключи без суффикса (формат грабера через единую getLexiconKey)
        $this->assertSame('/new.jpg', $log['gallery_list_images']);
        $this->assertSame('Картинка', $log['gallery_list_images_alt']);
        // в конфиге у media-записи лежат КЛЮЧИ, width — литерал
        $img = json_decode(json_decode(json_decode($resource->getTVValue('mpc_config'), true)['1']['list_images'], true)[0]['img'], true);
        $this->assertSame('gallery_list_images', $img[0]['src']);
        $this->assertSame('gallery_list_images_alt', $img[0]['alt']);
        $this->assertSame('500', $img[0]['width']);
    }

    /** picture: глубокий merge — существующий source обновляется, новый получает ключ. */
    public function testMergeMediaRecordPictureSources(): void
    {
        $cover = json_encode([[
            'MIGX_id' => 1, 'preview' => '/old.jpg',
            'img' => json_encode([['MIGX_id' => 1, 'src' => 'media_cover', 'alt' => 'media_cover_alt', 'title' => '', 'width' => '600', 'height' => '500']], JSON_UNESCAPED_UNICODE),
            'sources' => [['MIGX_id' => 1, 'type' => null, 'media' => '(min-width: 992px)', 'srcset' => 'media_cover_source']],
        ]], JSON_UNESCAPED_UNICODE);
        $config = json_encode(['1' => ['section_name' => 'media', 'MIGX_formname' => 'mpc_media', 'lexicon_prefix' => 'media', 'cover' => $cover]], JSON_UNESCAPED_UNICODE);
        $resource = new ModxObjectStub('modResource', ['id' => 5, 'context_key' => 'web', 'tv_mpc_config' => $config]);
        $writer = new FieldWriter($this->makeModx($resource));
        $log = [];
        $this->injectLex($writer, ['media_cover', 'media_cover_alt', 'media_cover_source'], $log);

        $newRecord = json_encode([[
            'MIGX_id' => 1, 'preview' => '/new.jpg',
            'img' => json_encode([['MIGX_id' => 1, 'src' => '/new-main.jpg', 'alt' => 'Новый alt', 'title' => '', 'width' => '600', 'height' => '500']], JSON_UNESCAPED_UNICODE),
            'sources' => [
                ['MIGX_id' => 1, 'media' => '(min-width: 992px)', 'srcset' => '/new-src1.webp'],
                ['MIGX_id' => 2, 'media' => '(min-width: 600px)', 'srcset' => '/new-src2.webp'], // новый source
            ],
        ]], JSON_UNESCAPED_UNICODE);
        $res = $writer->write(['type' => 'field', 'level' => 'resource', 'resourceId' => 5, 'section' => 'media', 'fieldName' => 'cover'], $newRecord);

        $this->assertTrue($res['success'], $res['message']);
        $this->assertSame('/new-main.jpg', $log['media_cover']);        // img.src по существующему ключу
        $this->assertSame('Новый alt', $log['media_cover_alt']);        // img.alt по существующему ключу
        $this->assertSame('/new-src1.webp', $log['media_cover_source']);// source[0] по существующему ключу
        $this->assertSame('/new-src2.webp', $log['media_cover_source_1']); // source[1] — новый ключ idx=1
        $cover2 = json_decode(json_decode($resource->getTVValue('mpc_config'), true)['1']['cover'], true);
        $this->assertSame('media_cover_source', $cover2[0]['sources'][0]['srcset']);
        $this->assertSame('media_cover_source_1', $cover2[0]['sources'][1]['srcset']);
    }

    /**
     * 2.5.59-rc: поле, чей лексикон-ключ УЖЕ существует, но попал под
     * exclude_lexicons, перестаёт наполнять лексикон — значение ложится в конфиг
     * литералом. Раньше эта ветка exclude не спрашивала, и ключ, заведённый до
     * появления паттерна, принимал значения вечно.
     */
    public function testExistingLexiconKeyUnderExcludeStaysLiteral(): void
    {
        $config = json_encode([
            '1' => ['section_name' => 'hero', 'MIGX_formname' => 'mpc_hero', 'lexicon_prefix' => 'hero', 'title' => 'hero_title'],
        ], JSON_UNESCAPED_UNICODE);
        $resource = new ModxObjectStub('modResource', ['id' => 5, 'context_key' => 'web', 'tv_mpc_config' => $config]);
        $writer = new FieldWriter($this->makeModx($resource));
        $log = [];
        $this->injectLex($writer, ['hero_title'], $log);   // ключ в лексиконе ЕСТЬ
        $this->injectExclude($writer, ['hero_title']);

        $res = $writer->write(
            ['type' => 'field', 'level' => 'resource', 'resourceId' => 5, 'section' => 'hero', 'fieldName' => 'title'],
            'Новое значение'
        );

        $this->assertTrue($res['success'], $res['message']);
        $this->assertSame([], $log);                       // в лексикон не писали
        $stored = json_decode($resource->getTVValue('mpc_config'), true);
        $this->assertSame('Новое значение', $stored['1']['title']); // литерал в конфиге
    }

    /**
     * 2.5.59-rc: exclude действует и в медиа-ветке — новая img-запись под
     * паттерном не получает ключей вовсе, путь остаётся в конфиге литералом.
     */
    public function testNewMediaRecordUnderExcludeStaysLiteral(): void
    {
        $config = json_encode([
            '1' => [
                'section_name'   => 'gallery',
                'MIGX_formname'  => 'mpc_gallery',
                'lexicon_prefix' => 'gallery',
                'list_images'    => json_encode([['MIGX_id' => 1, 'img' => '']], JSON_UNESCAPED_UNICODE),
            ],
        ], JSON_UNESCAPED_UNICODE);
        $resource = new ModxObjectStub('modResource', ['id' => 5, 'context_key' => 'web', 'tv_mpc_config' => $config]);
        $writer = new FieldWriter($this->makeModx($resource));
        $log = [];
        $this->injectLex($writer, [], $log);
        $this->injectExclude($writer, ['gallery_list_images*']); // glob покрывает src и _alt

        $record = json_encode([
            ['MIGX_id' => 1, 'src' => '/new.jpg', 'alt' => 'Картинка', 'width' => '500'],
        ], JSON_UNESCAPED_UNICODE);
        $res = $writer->write(
            ['type' => 'field', 'level' => 'resource', 'resourceId' => 5, 'section' => 'gallery', 'parentField' => 'list_images', 'idx' => 0, 'fieldName' => 'img'],
            $record
        );

        $this->assertTrue($res['success'], $res['message']);
        $this->assertSame([], $log);                              // ни одного ключа не завели
        $img = json_decode(json_decode(json_decode($resource->getTVValue('mpc_config'), true)['1']['list_images'], true)[0]['img'], true);
        $this->assertSame('/new.jpg', $img[0]['src']);            // путь литералом
        $this->assertSame('Картинка', $img[0]['alt']);
        $this->assertSame('500', $img[0]['width']);
    }

    public function testConfigFieldEmptyConfigRejected(): void
    {
        $resource = new ModxObjectStub('modResource', ['id' => 5]); // нет tv_mpc_config
        $writer = new FieldWriter($this->makeModx($resource));

        $result = $writer->write(['type' => 'field', 'level' => 'local', 'resourceId' => 5, 'section' => 'hero', 'fieldName' => 'title'], 'x');
        $this->assertFalse($result['success']);
    }

    public function testReadConfigReturnsDecodedSections(): void
    {
        $config = json_encode([
            '1' => ['section_name' => 'hero', 'MIGX_formname' => 'mpc_hero', 'title' => 'T', 'resources' => '5,6'],
        ], JSON_UNESCAPED_UNICODE);
        $resource = new ModxObjectStub('modResource', ['id' => 5, 'tv_mpc_config' => $config]);
        $writer = new FieldWriter($this->makeModx($resource));

        $res = $writer->readConfig('resource', 5);

        $this->assertTrue($res['success'], $res['message']);
        $sections = $res['data']['config'];
        $this->assertSame('hero', $sections['1']['section_name']);
        $this->assertSame('T', $sections['1']['title']);
        $this->assertSame('5,6', $sections['1']['resources']); // скрытое поле есть в конфиге, хоть и не в DOM
    }

    public function testReadConfigEmptyWhenNoTv(): void
    {
        $resource = new ModxObjectStub('modResource', ['id' => 5]); // нет tv_mpc_config
        $writer = new FieldWriter($this->makeModx($resource));

        $res = $writer->readConfig('resource', 5);

        $this->assertTrue($res['success'], $res['message']);
        $this->assertSame([], $res['data']['config']);
    }

    public function testReadConfigRejectsMissingResource(): void
    {
        $writer = new FieldWriter(new ModxStub()); // getObject → null
        $res = $writer->readConfig('resource', 999);
        $this->assertFalse($res['success']);
        $this->assertStringContainsString('not found', $res['message']);
    }

    public function testUnknownTypeRejected(): void
    {
        $resource = new ModxObjectStub('modResource', ['id' => 5]);
        $writer = new FieldWriter($this->makeModx($resource));

        $this->assertFalse($writer->write(['type' => 'bogus', 'resourceId' => 5, 'fieldName' => 'x'], 'v')['success']);
    }

    public function testResourceNotFound(): void
    {
        // modx, у которого getObject всегда null
        $modx = new ModxStub();
        $writer = new FieldWriter($modx);

        $result = $writer->write(['type' => 'rfield', 'resourceId' => 999, 'fieldName' => 'content'], 'x');
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('not found', $result['message']);
    }

}
