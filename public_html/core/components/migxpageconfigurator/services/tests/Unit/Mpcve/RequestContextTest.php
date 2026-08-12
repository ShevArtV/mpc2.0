<?php

namespace MpcTests\Unit\Mpcve;

use MpcVEServices\Handlers\Support\RequestContext;
use MpcTests\Stubs\ModxObjectStub;
use PHPUnit\Framework\TestCase;

class RequestContextTest extends TestCase
{
    private function modx(array $keys, string $current = 'web'): \modX
    {
        return new class($keys, $current) extends \modX {
            public array $keys;
            public object $context;
            public int $switches = 0;

            public function __construct(array $keys, string $current)
            {
                parent::__construct();
                $this->keys = $keys;
                $this->context = new ModxObjectStub('modContext', ['key' => $current]);
            }

            public function getObject(string $class, $conditions = null): ?object
            {
                $key = is_array($conditions) ? (string)($conditions['key'] ?? '') : (string)$conditions;
                return $class === 'modContext' && in_array($key, $this->keys, true)
                    ? new ModxObjectStub('modContext', ['key' => $key]) : null;
            }

            public function switchContext($key): bool
            {
                $this->switches++;
                $this->context = new ModxObjectStub('modContext', ['key' => (string)$key]);
                return true;
            }
        };
    }

    public function testRejectsMissingAndUnsafeContext(): void
    {
        $modx = $this->modx(['web', 'it']);
        $this->assertFalse((new RequestContext($modx))->switchTo(''));
        $this->assertFalse((new RequestContext($modx))->switchTo('../it'));
        $this->assertFalse((new RequestContext($modx))->switchTo('de'));
        $this->assertSame(0, $modx->switches);
    }

    public function testKeepsAlreadyActiveContext(): void
    {
        $modx = $this->modx(['web', 'it'], 'it');
        $this->assertTrue((new RequestContext($modx))->switchTo('it'));
        $this->assertSame(0, $modx->switches);
    }

    public function testSwitchesToRequestedExistingContext(): void
    {
        $modx = $this->modx(['web', 'it']);
        $this->assertTrue((new RequestContext($modx))->switchTo('it'));
        $this->assertSame('it', $modx->context->get('key'));
        $this->assertSame(1, $modx->switches);
    }
}
