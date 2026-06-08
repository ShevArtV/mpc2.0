<?php

namespace MpcTests\Unit\Cli;

use MpcServices\Cli\Apply\PluginsApply;
use MpcServices\Cli\Apply\SettingsApply;
use PHPUnit\Framework\TestCase;

/**
 * Тесты чистых методов движков apply (без modX).
 */
class ApplyEnginesTest extends TestCase
{
    // PluginsApply::diff ----------------------------------------------

    public function testEventsDiffBindUnbindKeep(): void
    {
        $d = PluginsApply::diff(['A', 'B', 'C'], ['B', 'C', 'D']);
        $this->assertSame(['A'], $d['bind']);     // в желаемом, нет в текущем
        $this->assertSame(['D'], $d['unbind']);   // в текущем, нет в желаемом
        $this->assertSame(['B', 'C'], $d['keep']);
    }

    public function testEventsDiffNoChange(): void
    {
        $d = PluginsApply::diff(['A', 'B'], ['B', 'A']);
        $this->assertSame([], $d['bind']);
        $this->assertSame([], $d['unbind']);
    }

    public function testEventsDiffDedupes(): void
    {
        $d = PluginsApply::diff(['A', 'A'], []);
        $this->assertSame(['A'], $d['bind']);
    }

    // SettingsApply::normalizeValue ----------------------------------

    public function testSettingNormalizeBool(): void
    {
        $this->assertSame('1', SettingsApply::normalizeValue(true));
        $this->assertSame('0', SettingsApply::normalizeValue(false));
    }

    public function testSettingNormalizeScalarAndArray(): void
    {
        $this->assertSame('42', SettingsApply::normalizeValue(42));
        $this->assertSame('hi', SettingsApply::normalizeValue('hi'));
        $this->assertSame('["a","b"]', SettingsApply::normalizeValue(['a', 'b']));
    }

    // SettingsApply::summarize ---------------------------------------

    public function testSettingSummarize(): void
    {
        $plan = [
            ['action' => 'create', 'ref' => 'a'],
            ['action' => 'update', 'ref' => 'b'],
            ['action' => 'skip', 'ref' => 'c'],
            ['action' => 'skip', 'ref' => 'd'],
        ];
        $this->assertSame(['create' => 1, 'update' => 1, 'skip' => 2], SettingsApply::summarize($plan));
    }
}
