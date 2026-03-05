<?php

namespace MpcTests\Unit\Cutter;

use MpcServices\Handlers\Cutter\SnippetCallBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Unit-тесты для SnippetCallBuilder.
 * Класс не зависит от MODX или DOM — полностью pure.
 */
class SnippetCallBuilderTest extends TestCase
{
    private function makeBuilder(array $presets = [], string $pathToChunks = 'chunks/'): SnippetCallBuilder
    {
        return new SnippetCallBuilder([
            'presets'      => $presets,
            'pathToChunks' => $pathToChunks,
        ]);
    }

    // ---------------------------------------------------------------
    // Без пресетов
    // ---------------------------------------------------------------

    public function testReturnsEmptySnippetCallWhenNoPreset(): void
    {
        $builder = $this->makeBuilder();
        $result  = $builder->getSnippetCall('mySnippet|nonexistentPreset', '{');

        $this->assertStringContainsString("'mySnippet' | snippet:", $result);
        $this->assertStringContainsString('snippet: []', $result);
    }

    public function testUsesFirstSymbolInCall(): void
    {
        $builder = $this->makeBuilder();

        $withBrace  = $builder->getSnippetCall('mySnippet|preset', '{');
        $withHash   = $builder->getSnippetCall('mySnippet|preset', '##');

        $this->assertStringContainsString("{", $withBrace);
        $this->assertStringContainsString("##", $withHash);
    }

    // ---------------------------------------------------------------
    // С пресетами
    // ---------------------------------------------------------------

    public function testUsesPresetParams(): void
    {
        $presets = [
            'mysnippet' => [
                'mypreset' => [
                    'tpl'     => '@FILE chunks/item.tpl',
                    'limit'   => '10',
                    'extends' => null,
                ]
            ]
        ];

        $builder = $this->makeBuilder($presets);
        $result  = $builder->getSnippetCall('mySnippet|mypreset', '{');

        $this->assertStringContainsString("'tpl' => '@FILE chunks/item.tpl'", $result);
        $this->assertStringContainsString("'limit' => '10'", $result);
    }

    public function testReplacesChunkPathPrefix(): void
    {
        $presets = [
            'mysnippet' => [
                'mypreset' => [
                    'tpl'     => '#/item.tpl',
                    'extends' => null,
                ]
            ]
        ];

        $builder = $this->makeBuilder($presets, 'chunks/');
        $result  = $builder->getSnippetCall('mySnippet|mypreset', '{');

        $this->assertStringContainsString('@FILE chunks/item.tpl', $result);
    }

    public function testExtendsResolvesParentPreset(): void
    {
        $presets = [
            'mysnippet' => [
                'base' => [
                    'tpl'     => 'base.tpl',
                    'limit'   => '5',
                    'extends' => null,
                ],
                'child' => [
                    'tpl'     => 'child.tpl',  // переопределяет
                    'extends' => 'mysnippet.base',
                ],
            ]
        ];

        $builder = $this->makeBuilder($presets);
        $result  = $builder->getSnippetCall('mySnippet|child', '{');

        // tpl из child переопределяет base
        $this->assertStringContainsString("'tpl' => 'child.tpl'", $result);
        // limit унаследован от base
        $this->assertStringContainsString("'limit' => '5'", $result);
    }

    public function testArrayValueIsJsonEncoded(): void
    {
        $presets = [
            'mysnippet' => [
                'mypreset' => [
                    'params'  => ['key' => 'value'],
                    'extends' => null,
                ]
            ]
        ];

        $builder = $this->makeBuilder($presets);
        $result  = $builder->getSnippetCall('mySnippet|mypreset', '{');

        // Массивы JSON-кодируются, { заменяется на { (пробел) для совместимости с Fenom
        $this->assertStringContainsString('{ "key":"value"}', $result);
    }

    // ---------------------------------------------------------------
    // @FILE сниппеты
    // ---------------------------------------------------------------

    public function testHandlesAtFileSnippet(): void
    {
        $presets = [
            'mysnippet' => [
                'mypreset' => [
                    'tpl'     => 'something.tpl',
                    'extends' => null,
                ]
            ]
        ];

        $builder = $this->makeBuilder($presets);
        $result  = $builder->getSnippetCall('@FILE mySnippet.inc.php|mypreset', '{');

        $this->assertStringContainsString("'@FILE mySnippet.inc.php' | snippet:", $result);
    }
}
