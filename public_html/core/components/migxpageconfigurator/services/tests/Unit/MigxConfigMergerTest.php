<?php

namespace MpcTests\Unit;

use MpcServices\Helpers\MigxConfigMerger;
use PHPUnit\Framework\TestCase;

class MigxConfigMergerTest extends TestCase
{
    private function tab(string $caption, array $fields, int $migxId = 1): array
    {
        return ['MIGX_id' => $migxId, 'caption' => $caption, 'fields' => $fields];
    }

    private function field(string $name, array $extra = [], int $migxId = 1): array
    {
        return array_merge(['MIGX_id' => $migxId, 'field' => $name, 'caption' => $name], $extra);
    }

    public function testReturnsBundleWhenNoExisting(): void
    {
        $bundle = ['name' => 'mpc_base', 'formtabs' => json_encode([])];
        $this->assertSame($bundle, (new MigxConfigMerger())->merge($bundle, null));
    }

    public function testMpcPrefixedFieldOverwrittenFromBundle(): void
    {
        $bundle = [
            'formtabs' => json_encode([$this->tab('Tab1', [$this->field('mpc_color', ['caption' => 'NEW'])])]),
        ];
        $existing = [
            'formtabs' => json_encode([$this->tab('Tab1', [$this->field('mpc_color', ['caption' => 'OLD', 'default' => 'red'])])]),
        ];
        $merged = (new MigxConfigMerger())->merge($bundle, $existing);
        $tabs = json_decode($merged['formtabs'], true);
        $this->assertSame('NEW', $tabs[0]['fields'][0]['caption']);
        $this->assertArrayNotHasKey('default', $tabs[0]['fields'][0]);
    }

    public function testNonPrefixedExistingPreserved(): void
    {
        $bundle = [
            'formtabs' => json_encode([$this->tab('Tab1', [$this->field('title', ['caption' => 'NEW'])])]),
        ];
        $existing = [
            'formtabs' => json_encode([$this->tab('Tab1', [$this->field('title', ['caption' => 'USER', 'extra' => 'kept'])])]),
        ];
        $merged = (new MigxConfigMerger())->merge($bundle, $existing);
        $tabs = json_decode($merged['formtabs'], true);
        $this->assertSame('USER', $tabs[0]['fields'][0]['caption']);
        $this->assertSame('kept', $tabs[0]['fields'][0]['extra']);
    }

    public function testNonPrefixedNewFieldAddedFromBundle(): void
    {
        $bundle = [
            'formtabs' => json_encode([$this->tab('Tab1', [$this->field('title2', ['caption' => 'New std'])])]),
        ];
        $existing = [
            'formtabs' => json_encode([$this->tab('Tab1', [$this->field('title', ['caption' => 'USER'])])]),
        ];
        $merged = (new MigxConfigMerger())->merge($bundle, $existing);
        $fields = json_decode($merged['formtabs'], true)[0]['fields'];
        $names = array_column($fields, 'field');
        $this->assertContains('title', $names);
        $this->assertContains('title2', $names);
    }

    public function testUserCustomFieldsPreservedInTab(): void
    {
        $bundle = [
            'formtabs' => json_encode([$this->tab('Tab1', [$this->field('mpc_x')])]),
        ];
        $existing = [
            'formtabs' => json_encode([$this->tab('Tab1', [
                $this->field('mpc_x'),
                $this->field('user_custom', ['caption' => 'mine'], 2),
            ])]),
        ];
        $merged = (new MigxConfigMerger())->merge($bundle, $existing);
        $names = array_column(json_decode($merged['formtabs'], true)[0]['fields'], 'field');
        $this->assertContains('user_custom', $names);
    }

    public function testUserOnlyTabsPreserved(): void
    {
        $bundle = [
            'formtabs' => json_encode([$this->tab('System', [$this->field('mpc_a')])]),
        ];
        $existing = [
            'formtabs' => json_encode([
                $this->tab('System', [$this->field('mpc_a', ['caption' => 'old'])]),
                $this->tab('Мои поля', [$this->field('my_field', ['caption' => 'mine'])], 2),
            ]),
        ];
        $merged = (new MigxConfigMerger())->merge($bundle, $existing);
        $tabs = json_decode($merged['formtabs'], true);
        $this->assertCount(2, $tabs);
        $this->assertContains('Мои поля', array_column($tabs, 'caption'));
    }

    public function testEmptyCaptionTabNotDuplicatedOnUpgrade(): void
    {
        // Почти все конфиги — единственный tab с пустым caption. При апгрейде
        // безымянный tab из бандла не должен задваиваться с безымянным из БД.
        $bundle = [
            'formtabs' => json_encode([$this->tab('', [$this->field('mpc_a', ['caption' => 'NEW'])])]),
        ];
        $existing = [
            'formtabs' => json_encode([$this->tab('', [
                $this->field('mpc_a', ['caption' => 'OLD']),
                $this->field('user_custom', ['caption' => 'mine'], 2),
            ])]),
        ];
        $merged = (new MigxConfigMerger())->merge($bundle, $existing);
        $tabs = json_decode($merged['formtabs'], true);
        $this->assertCount(1, $tabs);
        $names = array_column($tabs[0]['fields'], 'field');
        $this->assertSame('NEW', $tabs[0]['fields'][0]['caption']);
        $this->assertContains('user_custom', $names);
    }

    public function testEmptyCaptionTabsMatchedPositionally(): void
    {
        $bundle = [
            'formtabs' => json_encode([
                $this->tab('', [$this->field('mpc_a')]),
                $this->tab('', [$this->field('mpc_b')], 2),
            ]),
        ];
        $existing = [
            'formtabs' => json_encode([
                $this->tab('', [$this->field('mpc_a'), $this->field('u1', [], 2)]),
                $this->tab('', [$this->field('mpc_b'), $this->field('u2', [], 2)], 2),
            ]),
        ];
        $merged = (new MigxConfigMerger())->merge($bundle, $existing);
        $tabs = json_decode($merged['formtabs'], true);
        $this->assertCount(2, $tabs);
        $this->assertContains('u1', array_column($tabs[0]['fields'], 'field'));
        $this->assertContains('u2', array_column($tabs[1]['fields'], 'field'));
    }

    public function testColumnsMergedByDataIndex(): void
    {
        $bundle = [
            'columns' => json_encode([
                ['dataIndex' => 'mpc_id', 'header' => 'NEW'],
                ['dataIndex' => 'name', 'header' => 'std-new'],
            ]),
        ];
        $existing = [
            'columns' => json_encode([
                ['dataIndex' => 'mpc_id', 'header' => 'OLD', 'width' => 100],
                ['dataIndex' => 'name', 'header' => 'USER'],
                ['dataIndex' => 'custom_col', 'header' => 'mine'],
            ]),
        ];
        $merged = (new MigxConfigMerger())->merge($bundle, $existing);
        $byIdx = [];
        foreach (json_decode($merged['columns'], true) as $c) {
            $byIdx[$c['dataIndex']] = $c;
        }
        $this->assertSame('NEW', $byIdx['mpc_id']['header']);
        $this->assertArrayNotHasKey('width', $byIdx['mpc_id']);
        $this->assertSame('USER', $byIdx['name']['header']);
        $this->assertSame('mine', $byIdx['custom_col']['header']);
    }

    public function testColumnsDedupedFromDoubledExisting(): void
    {
        // Воспроизводит сломанное состояние 2.4.3-rc: после ошибочного апгрейда
        // колонки с одинаковым dataIndex задвоились. Новый прогон merger'а
        // должен схлопнуть их обратно (map по dataIndex → один entry).
        $bundle = [
            'columns' => json_encode([
                ['dataIndex' => 'preview', 'header' => 'Превью', 'renderer' => 'this.renderImage'],
            ]),
        ];
        $existing = [
            'columns' => json_encode([
                ['dataIndex' => 'preview', 'header' => 'Превью', 'renderer' => 'this.renderImage'],
                ['dataIndex' => 'preview', 'header' => 'Превью', 'renderer' => 'this.renderImage'],
            ]),
        ];
        $merged = (new MigxConfigMerger())->merge($bundle, $existing);
        $cols = json_decode($merged['columns'], true);
        $this->assertCount(1, $cols);
        $this->assertSame('preview', $cols[0]['dataIndex']);
    }

    public function testMigxIdsRenumberedSequentially(): void
    {
        $bundle = [
            'formtabs' => json_encode([$this->tab('Tab1', [
                $this->field('mpc_a', [], 5),
                $this->field('mpc_b', [], 9),
            ])]),
        ];
        $existing = [
            'formtabs' => json_encode([$this->tab('Tab1', [
                $this->field('mpc_a', [], 1),
                $this->field('custom', [], 7),
            ])]),
        ];
        $merged = (new MigxConfigMerger())->merge($bundle, $existing);
        $ids = array_column(json_decode($merged['formtabs'], true)[0]['fields'], 'MIGX_id');
        $this->assertSame([1, 2, 3], $ids);
    }

    public function testScalarTopFieldsTakenFromBundle(): void
    {
        $bundle = ['name' => 'mpc_base', 'category' => 'new-cat', 'formtabs' => json_encode([])];
        $existing = ['name' => 'mpc_base', 'category' => 'old-cat', 'formtabs' => json_encode([])];
        $this->assertSame('new-cat', (new MigxConfigMerger())->merge($bundle, $existing)['category']);
    }

    public function testEmptyBundleColumnsLeaveExistingTouchedOnlyIfPresent(): void
    {
        $bundle = ['formtabs' => json_encode([])];
        $existing = ['formtabs' => json_encode([]), 'columns' => json_encode([['field' => 'keep']])];
        $merged = (new MigxConfigMerger())->merge($bundle, $existing);
        $this->assertSame([['field' => 'keep']], json_decode($merged['columns'], true));
    }
}
