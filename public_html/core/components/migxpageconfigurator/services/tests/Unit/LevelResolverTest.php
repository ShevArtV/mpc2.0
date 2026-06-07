<?php

namespace MpcTests\Unit;

use MpcServices\Handlers\LevelResolver;
use MpcTests\Stubs\ModxObjectStub;
use MpcTests\Stubs\ModxStub;
use PHPUnit\Framework\TestCase;

/**
 * Юнит резолвинга уровней (вынос из FieldWriter): global→staticBlocksPage,
 * type→донор (parent=sbp + template ресурса), resource→сам ресурс; алиасы
 * local/template; null-кейсы.
 */
class LevelResolverTest extends TestCase
{
    /** modX-стаб: int id → byId[id]; массив-условие [parent,template] → byArray. */
    private function modx(array $byId, $byArray): ModxStub
    {
        return new class($byId, $byArray) extends ModxStub {
            private array $byId;
            private $byArray;
            public function __construct(array $byId, $byArray)
            {
                parent::__construct(null, []);
                $this->byId = $byId;
                $this->byArray = $byArray;
            }
            public function getObject(string $class, $conditions = null): ?object
            {
                if ($class !== 'modResource') {
                    return null;
                }
                return is_array($conditions) ? $this->byArray : ($this->byId[(int)$conditions] ?? null);
            }
        };
    }

    public function testGlobalReturnsStaticBlocksPage(): void
    {
        $sbp = new ModxObjectStub('modResource', ['id' => 3]);
        $r = new LevelResolver($this->modx([3 => $sbp], null), 3);
        $this->assertSame($sbp, $r->resolve('global', 999));
    }

    public function testResourceReturnsSelf(): void
    {
        $res = new ModxObjectStub('modResource', ['id' => 10]);
        $r = new LevelResolver($this->modx([10 => $res], null), 3);
        $this->assertSame($res, $r->resolve('resource', 10));
    }

    public function testResourceZeroIdIsNull(): void
    {
        $r = new LevelResolver($this->modx([], null), 3);
        $this->assertNull($r->resolve('resource', 0));
    }

    public function testTypeResolvesDonorByParentAndTemplate(): void
    {
        $res  = new ModxObjectStub('modResource', ['id' => 10, 'template' => 7]);
        $type = new ModxObjectStub('modResource', ['id' => 43]);
        $r = new LevelResolver($this->modx([10 => $res], $type), 3);
        $this->assertSame($type, $r->resolve('type', 10));
    }

    public function testTypeNullWhenResourceMissing(): void
    {
        $r = new LevelResolver($this->modx([], null), 3);
        $this->assertNull($r->resolve('type', 10));
    }

    public function testAliasesMapToNewLevels(): void
    {
        $res  = new ModxObjectStub('modResource', ['id' => 10, 'template' => 7]);
        $type = new ModxObjectStub('modResource', ['id' => 43]);
        $m = $this->modx([10 => $res], $type);
        $r = new LevelResolver($m, 3);
        $this->assertSame($res, $r->resolve('local', 10));     // local → resource
        $this->assertSame($type, $r->resolve('template', 10)); // template → type
    }
}
