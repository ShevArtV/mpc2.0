<?php

namespace MpcTests\Unit\Cli;

use MpcServices\Cli\ArgvParser;
use PHPUnit\Framework\TestCase;

class ArgvParserTest extends TestCase
{
    private function p(array $tokens): array
    {
        return ArgvParser::parse(array_merge(['mpc.php'], $tokens));
    }

    public function testGroupAction(): void
    {
        $r = $this->p(['resource', 'create']);
        $this->assertSame('resource', $r['group']);
        $this->assertSame('create', $r['action']);
    }

    public function testColonForm(): void
    {
        $r = $this->p(['plugin:event-bind', 'MyPlugin']);
        $this->assertSame('plugin', $r['group']);
        $this->assertSame('event-bind', $r['action']);
        $this->assertSame(['MyPlugin'], $r['args']);
    }

    public function testKeyValueEquals(): void
    {
        $r = $this->p(['setting', 'update', '--key=site_name', '--value=Hello']);
        $this->assertSame('site_name', $r['opts']['key']);
        $this->assertSame('Hello', $r['opts']['value']);
    }

    public function testKeySpaceValue(): void
    {
        $r = $this->p(['setting', 'get', '--key', 'site_name']);
        $this->assertSame('site_name', $r['opts']['key']);
        $this->assertSame([], $r['args']);
    }

    public function testBooleanFlags(): void
    {
        $r = $this->p(['resource', 'delete', '5', '--force', '--json']);
        $this->assertTrue($r['opts']['force']);
        $this->assertTrue($r['opts']['json']);
        $this->assertSame(['5'], $r['args']);
    }

    public function testFlagNotEatingNextPositionalWhenBoolList(): void
    {
        // --force булев → следующий токен остаётся позиционным
        $r = $this->p(['package', 'remove', '--force', 'minishop2']);
        $this->assertTrue($r['opts']['force']);
        $this->assertSame(['minishop2'], $r['args']);
    }

    public function testDoubleDashStopsOptions(): void
    {
        $r = $this->p(['lexicon', 'set', '--', '--weird-key']);
        $this->assertSame(['--weird-key'], $r['args']);
    }

    public function testEmpty(): void
    {
        $r = $this->p([]);
        $this->assertSame('', $r['group']);
        $this->assertSame('', $r['action']);
    }
}
