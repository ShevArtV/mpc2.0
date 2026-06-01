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

    public function testArraySerializedAsFenomLiteral(): void
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

        // Массив → Fenom array-литерал; строковый лист — в кавычках
        $this->assertStringContainsString("'params' => ['key' => 'value']", $result);
    }

    /** Вложенное условие с переменной доезжает живым ($resource.alias без кавычек). */
    public function testNestedConditionKeepsLiveExpression(): void
    {
        $presets = [
            'pdoresources' => [
                'default' => [
                    'where'  => ['alias' => '$resource.alias'],
                    'sortby' => ['menuindex' => 'ASC'],
                    'extends' => null,
                ]
            ]
        ];

        $builder = $this->makeBuilder($presets);
        $result  = $builder->getSnippetCall('!pdoResources|default', '{');

        $this->assertStringContainsString("'where' => ['alias' => \$resource.alias]", $result);
        $this->assertStringContainsString("'sortby' => ['menuindex' => 'ASC']", $result);
    }

    /** Сложное условие: числа/list/выражения сериализуются корректно. */
    public function testComplexConditionMixedTypes(): void
    {
        $presets = [
            'pdoresources' => [
                'default' => [
                    'where'  => [
                        'id:in'     => '[1,2,3]',
                        'published' => 1,
                        'alias:LIKE' => '$resource.alias',
                    ],
                    'extends' => null,
                ]
            ]
        ];

        $builder = $this->makeBuilder($presets);
        $result  = $builder->getSnippetCall('!pdoResources|default', '{');

        $this->assertStringContainsString(
            "'where' => ['id:in' => [1,2,3], 'published' => 1, 'alias:LIKE' => \$resource.alias]",
            $result
        );
    }

    /** ## внутри массива (eager-плейсхолдер статики) по-прежнему → {. */
    public function testHashPlaceholderInArrayConvertedToBrace(): void
    {
        $presets = [
            'mysnippet' => [
                'mypreset' => [
                    'where'  => ['field' => '##title}'],
                    'extends' => null,
                ]
            ]
        ];

        $builder = $this->makeBuilder($presets);
        $result  = $builder->getSnippetCall('mySnippet|mypreset', '{');

        $this->assertStringContainsString("'where' => ['field' => '{title}']", $result);
    }

    /** #/path внутри вложенного массива разворачивается в @FILE-чанк. */
    public function testChunkPathPrefixInsideArray(): void
    {
        $presets = [
            'mysnippet' => [
                'mypreset' => [
                    'tpls'    => ['item' => '#/item.tpl'],
                    'extends' => null,
                ]
            ]
        ];

        $builder = $this->makeBuilder($presets, 'chunks/');
        $result  = $builder->getSnippetCall('mySnippet|mypreset', '{');

        $this->assertStringContainsString("'tpls' => ['item' => '@FILE chunks/item.tpl']", $result);
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
