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

    public function testColumnsMergedSameRule(): void
    {
        $bundle = [
            'columns' => json_encode([
                ['field' => 'mpc_id', 'header' => 'NEW'],
                ['field' => 'name', 'header' => 'std-new'],
            ]),
        ];
        $existing = [
            'columns' => json_encode([
                ['field' => 'mpc_id', 'header' => 'OLD', 'width' => 100],
                ['field' => 'name', 'header' => 'USER'],
                ['field' => 'custom_col', 'header' => 'mine'],
            ]),
        ];
        $merged = (new MigxConfigMerger())->merge($bundle, $existing);
        $byName = [];
        foreach (json_decode($merged['columns'], true) as $c) {
            $byName[$c['field']] = $c;
        }
        $this->assertSame('NEW', $byName['mpc_id']['header']);
        $this->assertArrayNotHasKey('width', $byName['mpc_id']);
        $this->assertSame('USER', $byName['name']['header']);
        $this->assertSame('mine', $byName['custom_col']['header']);
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
