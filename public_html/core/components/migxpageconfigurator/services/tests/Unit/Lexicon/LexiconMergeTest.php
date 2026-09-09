<?php

namespace MpcTests\Unit\Lexicon;

use MpcServices\Handlers\Lexicon\LexiconMerge as M;
use PHPUnit\Framework\TestCase;

/**
 * Правила трёхстороннего слияния: снимок (base) × книга (desired) × файл
 * (current). Проверяется главное свойство — старая книга НЕ откатывает правку,
 * сделанную на сервере после экспорта.
 */
class LexiconMergeTest extends TestCase
{
    private function entry(?string $base, ?string $current, string $desired): array
    {
        return M::planEntry('ru', '10', 'k', $base, $current, $desired);
    }

    public function testBlankCellIsNeverAWrite(): void
    {
        $op = $this->entry('старое', 'новое', '   ');
        $this->assertSame(M::SKIP, $op['action']);
        $this->assertSame('blank-cell', $op['reason']);
    }

    public function testUnchangedCellDoesNotRevertServerEdit(): void
    {
        // Книга выгружена со «старое», менеджер её не трогал, на сервере уже
        // «новое» — ровно тот случай, из-за которого правки терялись.
        $op = $this->entry('старое', 'новое', 'старое');
        $this->assertSame(M::NOOP, $op['action']);
        $this->assertSame('unchanged-in-excel', $op['reason']);
    }

    public function testChangedCellOnUntouchedServerIsWritten(): void
    {
        $op = $this->entry('старое', 'старое', 'свежее');
        $this->assertSame(M::WRITE, $op['action']);
        $this->assertSame('свежее', $op['desired']);
    }

    public function testBothChangedIsConflict(): void
    {
        $op = $this->entry('старое', 'серверное', 'книжное');
        $this->assertSame(M::CONFLICT, $op['action']);
        $this->assertSame('both-changed', $op['reason']);
    }

    public function testSameChangeOnBothSidesIsNoop(): void
    {
        $op = $this->entry('старое', 'одинаково', 'одинаково');
        $this->assertSame(M::NOOP, $op['action']);
        $this->assertSame('already-applied', $op['reason']);
    }

    public function testNewKeyIsWrittenButKeyAddedOnServerIsConflict(): void
    {
        $this->assertSame(M::WRITE, $this->entry(null, null, 'новый')['action']);

        $op = $this->entry(null, 'уже завели', 'новый');
        $this->assertSame(M::CONFLICT, $op['action']);
        $this->assertSame('added-on-server', $op['reason']);
    }

    public function testClearLiteralRemovesKeyOnlyWhenServerUntouched(): void
    {
        $op = $this->entry('текст', 'текст', M::CLEAR_LITERAL);
        $this->assertSame(M::CLEAR, $op['action']);
        $this->assertNull($op['desired']);

        $this->assertSame(M::CONFLICT, $this->entry('текст', 'другое', M::CLEAR_LITERAL)['action']);
        $this->assertSame(M::NOOP, $this->entry('текст', null, M::CLEAR_LITERAL)['action']);
    }

    public function testClearLiteralIsCaseAndSpaceTolerant(): void
    {
        $this->assertTrue(M::isClear(' [[CLEAR]] '));
        $this->assertFalse(M::isClear('[[clear]] и текст'));
    }

    public function testUnicodeMultilineAndPlaceholdersSurviveComparison(): void
    {
        $html = "<p>Строка 1</p>\n<p>Ünïcode — тире</p>";
        $this->assertSame(M::NOOP, $this->entry($html, $html, $html)['action']);
        $this->assertSame(M::WRITE, $this->entry($html, $html, $html . '!')['action']);
        $this->assertSame(M::NOOP, $this->entry('[[+ph]]', '[[+ph]]', '[[+ph]]')['action']);
    }

    public function testPlanAndSummaryCoverWholeBook(): void
    {
        $base    = ['ru' => ['10' => ['a' => 'старое', 'b' => 'b']]];
        $current = ['ru' => ['10' => ['a' => 'старое', 'b' => 'b-серверное']]];
        $desired = ['ru' => ['10' => ['a' => 'новое', 'b' => 'b-книжное', 'c' => '']]];

        $ops     = M::plan($base, $desired, $current);
        $summary = M::summary($ops);

        $this->assertSame(3, $summary['total']);
        $this->assertSame(1, $summary[M::WRITE]);
        $this->assertSame(1, $summary[M::CONFLICT]);
        $this->assertSame(1, $summary[M::SKIP]);
        $this->assertCount(1, M::conflicts($ops));
    }

    public function testResolveMineAppliesButStaleResolutionStaysConflict(): void
    {
        $op = $this->entry('старое', 'серверное', 'книжное');

        $applied = M::resolve($op, 'mine', 'серверное');
        $this->assertSame(M::WRITE, $applied['action']);

        // Менеджеру показали «серверное», а пока он думал, файл переписали.
        $stale = M::resolve($op, 'mine', 'ещё новее');
        $this->assertSame(M::CONFLICT, $stale['action']);
        $this->assertSame('stale-resolution', $stale['reason']);
    }

    public function testResolveServerKeepsFileAsIs(): void
    {
        $op = M::resolve($this->entry('старое', 'серверное', 'книжное'), 'server', 'серверное');
        $this->assertSame(M::NOOP, $op['action']);
        $this->assertSame('kept-server', $op['reason']);
    }
}
