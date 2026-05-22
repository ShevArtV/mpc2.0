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

    public function testRejectsInvalidAddress(): void
    {
        $resource = new ModxObjectStub('modResource', ['id' => 5]);
        $writer = new FieldWriter($this->makeModx($resource));

        $this->assertFalse($writer->write(['type' => 'rfield', 'fieldName' => 'content'], 'x')['success']);
        $this->assertFalse($writer->write(['type' => 'rfield', 'resourceId' => 5], 'x')['success']);
    }

    public function testConfigFieldNotImplementedYet(): void
    {
        $resource = new ModxObjectStub('modResource', ['id' => 5]);
        $writer = new FieldWriter($this->makeModx($resource));

        $result = $writer->write(['type' => 'field', 'resourceId' => 5, 'fieldName' => 'title', 'section' => 'hero'], 'x');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('not implemented', $result['message']);
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
