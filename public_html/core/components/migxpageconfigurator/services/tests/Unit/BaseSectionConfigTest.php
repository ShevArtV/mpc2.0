<?php

namespace MpcTests\Unit;

use MpcServices\Handlers\Base;
use MpcTests\Stubs\ModxStub;
use PHPUnit\Framework\TestCase;

/**
 * Источник конфига для решения о статике: тип страницы — база, ресурс
 * перекрывает одноимённые секции (#2609-151). То же правило, что у
 * `Render::parseConfig`, — читатель и писатель обязаны видеть один конфиг.
 */
class BaseSectionConfigTest extends TestCase
{
    private Base $base;

    protected function setUp(): void
    {
        $modx = new ModxStub();
        $this->base = new Base($modx, ['corePath' => dirname(__DIR__, 3) . '/']);
    }

    private function section(string $name, bool $isStatic, string $prefix = ''): array
    {
        return [
            'section_name'   => $name,
            'is_static'      => $isStatic ? '1' : '0',
            'lexicon_prefix' => $prefix ?: $name,
        ];
    }

    public function testTypeSectionsSurviveWhenResourceConfigIsEmpty(): void
    {
        $type = [$this->section('hero', true), $this->section('cards', false)];

        $merged = $this->base->mergeSectionConfigs($type, []);

        $this->assertSame(['hero', 'cards'], array_column($merged, 'section_name'));
        $this->assertSame(['hero'], $this->base->getStaticSectionNamesFromConfig($merged));
    }

    public function testResourceOverridesSameNamedSection(): void
    {
        $type     = [$this->section('hero', true), $this->section('cards', true)];
        $resource = [$this->section('cards', false)];

        $merged = $this->base->mergeSectionConfigs($type, $resource);

        $this->assertSame(['hero', 'cards'], array_column($merged, 'section_name'));
        // cards переопределена ресурсом → статичной остаётся только hero
        $this->assertSame(['hero'], $this->base->getStaticSectionNamesFromConfig($merged));
    }

    public function testSectionsMissingInResourceKeepTypeStaticFlag(): void
    {
        // Ровно случай инцидента: у контекстной копии устаревший снимок из
        // 11 секций, у типа секция difference статична. Копия про неё не знает —
        // решение должно остаться за типом.
        $type     = [$this->section('difference', true), $this->section('proofs', true)];
        $resource = [$this->section('first', false)];

        $merged = $this->base->mergeSectionConfigs($type, $resource);

        $this->assertSame(
            ['difference', 'proofs'],
            $this->base->getStaticSectionNamesFromConfig($merged)
        );
    }

    public function testResourceOnlySectionIsAdded(): void
    {
        $type     = [$this->section('hero', true)];
        $resource = [$this->section('promo', false)];

        $merged = $this->base->mergeSectionConfigs($type, $resource);

        $this->assertSame(['hero', 'promo'], array_column($merged, 'section_name'));
    }

    public function testNamelessEntriesAreKept(): void
    {
        $type     = [['is_static' => '0', 'lexicon_prefix' => 'orphan']];
        $resource = [$this->section('hero', true)];

        $merged = $this->base->mergeSectionConfigs($type, $resource);

        $this->assertCount(2, $merged);
    }

    public function testAllFlagReturnsEveryName(): void
    {
        $config = [$this->section('hero', true), $this->section('cards', false)];

        $this->assertSame(
            ['hero', 'cards'],
            $this->base->getStaticSectionNamesFromConfig($config, true)
        );
    }
}
