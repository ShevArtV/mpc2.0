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
    private function makeModx(ModxObjectStub $resource): ModxStub
    {
        return new class($resource) extends ModxStub {
            private ModxObjectStub $res;

            public function __construct(ModxObjectStub $res)
            {
                parent::__construct();
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
    public function testWritesTvLexiconWhenKeyExists(): void
    {
        $resource = new ModxObjectStub('modResource', ['id' => 5, 'context_key' => 'web']);
        $writer = new FieldWriter($this->makeModx($resource));

        $log = [];
        $lex = new class($log) extends \MpcServices\Handlers\LexiconWriter {
            private array $log;
            public function __construct(array &$log) { $this->log = &$log; }
            public function identifier(int $rid): string { return 'res' . $rid; }
            public function has(string $identifier, string $key): bool { return $key === 'mpc_resource_tv_subtitle'; }
            public function set(string $identifier, string $key, string $value): bool { $this->log[$key] = $value; return true; }
        };

        // Инжектим useLexicons + фейковый LexiconWriter (избегаем файловой записи).
        $ref = new \ReflectionObject($writer);
        $ul = $ref->getProperty('useLexicons');       $ul->setAccessible(true); $ul->setValue($writer, true);
        $lw = $ref->getProperty('lexWriterInstance');  $lw->setAccessible(true); $lw->setValue($writer, $lex);

        $result = $writer->write(['type' => 'tv', 'resourceId' => 5, 'fieldName' => 'subtitle'], 'Перевод');

        $this->assertTrue($result['success'], $result['message']);
        $this->assertSame('Перевод', $log['mpc_resource_tv_subtitle']); // ушло в лексикон по tv-ключу
        $this->assertSame('', $resource->getTVValue('subtitle'));       // TV-значение (колонку) не трогали
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

    // --- isRecordValue / mergeRecordWithLexicon (pure) ---------------------

    private function bareWriter(): FieldWriter
    {
        return (new \ReflectionClass(FieldWriter::class))->newInstanceWithoutConstructor();
    }

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

    public function testIsRecordValue(): void
    {
        $w = $this->bareWriter();
        $this->assertTrue($w->isRecordValue('[{"src":"a"}]'));
        $this->assertFalse($w->isRecordValue('hero_title'));
        $this->assertFalse($w->isRecordValue('[1,2,3]'));
        $this->assertFalse($w->isRecordValue('{"a":1}'));
        $this->assertFalse($w->isRecordValue(''));
    }

    /** Лексиконная запись: ключи src/alt сохраняются, лексикон обновляется, width — литерал. */
    public function testMergeRecordKeepsKeysAndWritesLexicon(): void
    {
        $w = $this->bareWriter();
        $log = [];
        $lex = $this->fakeLex(['hero_img', 'hero_img_alt'], $log);

        $cur = '[{"MIGX_id":1,"src":"hero_img","alt":"hero_img_alt","width":"100"}]';
        $new = '[{"MIGX_id":1,"src":"/new.jpg","alt":"Новый alt","width":"200"}]';
        $out = json_decode($w->mergeRecordWithLexicon($lex, 'res', $cur, $new), true);

        $this->assertSame('hero_img', $out[0]['src']);        // ключ сохранён
        $this->assertSame('hero_img_alt', $out[0]['alt']);    // ключ сохранён
        $this->assertSame('200', $out[0]['width']);           // литерал обновлён
        $this->assertSame('/new.jpg', $log['hero_img']);      // лексикон обновлён
        $this->assertSame('Новый alt', $log['hero_img_alt']);
    }

    /** Не-лексиконная запись (ключей нет) → литералы как есть. */
    public function testMergeRecordWithoutLexiconKeysIsLiteral(): void
    {
        $w = $this->bareWriter();
        $log = [];
        $lex = $this->fakeLex([], $log);

        $cur = '[{"src":"/old.jpg","alt":"old"}]';
        $new = '[{"src":"/new.jpg","alt":"new"}]';
        $out = json_decode($w->mergeRecordWithLexicon($lex, 'res', $cur, $new), true);

        $this->assertSame('/new.jpg', $out[0]['src']);
        $this->assertSame('new', $out[0]['alt']);
        $this->assertSame([], $log); // в лексикон ничего не писали
    }
}
