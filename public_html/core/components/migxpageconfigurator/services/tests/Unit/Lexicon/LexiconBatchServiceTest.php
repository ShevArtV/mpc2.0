<?php

namespace MpcTests\Unit\Lexicon;

use MpcServices\Handlers\Lexicon\LexiconBatchService;
use MpcServices\Handlers\Lexicon\LexiconMerge as M;
use MpcServices\Handlers\Lexicon\LexiconStore;
use MpcServices\Handlers\Lexicon\OrphanRegistry;
use MpcServices\Handlers\Lexicon\SnapshotStore;
use MpcServices\Handlers\LexiconImport;
use PHPUnit\Framework\TestCase;

/**
 * Сквозной цикл «экспорт → правка книги → импорт» на реальных файлах:
 * снимок, трёхстороннее слияние, запись со сверкой и чистка мёртвых ключей.
 */
class LexiconBatchServiceTest extends TestCase
{
    private string $base;
    private string $snapDir;
    private LexiconBatchService $service;

    protected function setUp(): void
    {
        $root          = sys_get_temp_dir() . '/mpc_batch_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '/';
        $this->base    = $root . 'lexicon/';
        $this->snapDir = $root . 'snapshots/';
        @mkdir($this->base . 'ru/', 0777, true);
        @mkdir($this->base . 'en/', 0777, true);

        $this->service = new LexiconBatchService(
            new LexiconStore($this->base),
            new SnapshotStore($this->snapDir),
            new OrphanRegistry($this->base),
            'ru',
            'static'
        );
    }

    protected function tearDown(): void
    {
        @system('rm -rf ' . escapeshellarg(dirname(rtrim($this->base, '/'))));
    }

    private function store(): LexiconStore
    {
        return $this->service->store();
    }

    public function testOldWorkbookDoesNotRevertServerEdits(): void
    {
        $this->store()->write('ru', '10', ['a' => 'старое', 'b' => 'b']);
        $snap = $this->service->snapshot(['10'], ['ru'], 'test');

        // Менеджер правит 'b' в CMP уже ПОСЛЕ выгрузки книги.
        $this->store()->write('ru', '10', ['a' => 'старое', 'b' => 'правка менеджера']);

        // В книге 'a' изменено, 'b' осталось таким, каким было при выгрузке.
        $desired = ['ru' => ['10' => ['a' => 'новое', 'b' => 'b']]];
        $plan    = $this->service->planImport($desired, $snap['id']);
        $this->assertSame('', $plan['error']);

        $res = $this->service->apply($plan['ops']);
        $this->assertSame(1, $res['applied']);
        $this->assertSame(
            ['a' => 'новое', 'b' => 'правка менеджера'],
            $this->store()->read('ru', '10')
        );
    }

    public function testRepeatedApplyOfSameBookChangesNothing(): void
    {
        $this->store()->write('ru', '10', ['a' => 'старое']);
        $snap    = $this->service->snapshot(['10'], ['ru'], 'test');
        $desired = ['ru' => ['10' => ['a' => 'новое']]];

        $this->service->apply($this->service->planImport($desired, $snap['id'])['ops']);
        $second = $this->service->apply($this->service->planImport($desired, $snap['id'])['ops']);

        $this->assertSame(0, $second['applied']);
        $this->assertSame(['a' => 'новое'], $this->store()->read('ru', '10'));
    }

    public function testConflictNeedsDecisionAndStaleDecisionIsNotApplied(): void
    {
        $this->store()->write('ru', '10', ['a' => 'старое']);
        $snap = $this->service->snapshot(['10'], ['ru'], 'test');
        $this->store()->write('ru', '10', ['a' => 'серверное']);

        $plan = $this->service->planImport(['ru' => ['10' => ['a' => 'книжное']]], $snap['id']);
        $this->assertCount(1, $plan['conflicts']);

        // Без решения запись не идёт.
        $none = $this->service->apply($plan['ops']);
        $this->assertSame(0, $none['applied']);
        $this->assertSame('серверное', $this->store()->read('ru', '10')['a']);

        $addr = LexiconBatchService::address($plan['conflicts'][0]);

        // Пока менеджер думал, файл переписали ещё раз — решение устарело.
        $this->store()->write('ru', '10', ['a' => 'ещё новее']);
        $stale = $this->service->apply($plan['ops'], [$addr => ['decision' => 'mine', 'seen' => 'серверное']]);
        $this->assertSame(0, $stale['applied']);
        $this->assertCount(1, $stale['conflicts']);
        $this->assertSame('ещё новее', $this->store()->read('ru', '10')['a']);

        // Решение по актуальному значению применяется.
        $ok = $this->service->apply(
            $this->service->planImport(['ru' => ['10' => ['a' => 'книжное']]], $snap['id'])['ops'],
            [$addr => ['decision' => 'mine', 'seen' => 'ещё новее']]
        );
        $this->assertSame(1, $ok['applied']);
        $this->assertSame('книжное', $this->store()->read('ru', '10')['a']);
    }

    public function testExplicitClearLiteralRemovesKeyAndBlankCellDoesNot(): void
    {
        $this->store()->write('ru', '10', ['a' => 'a', 'b' => 'b']);
        $snap = $this->service->snapshot(['10'], ['ru'], 'test');

        $res = $this->service->apply($this->service->planImport(
            ['ru' => ['10' => ['a' => M::CLEAR_LITERAL, 'b' => '']]],
            $snap['id']
        )['ops']);

        $this->assertSame(1, $res['cleared']);
        $this->assertSame(['b' => 'b'], $this->store()->read('ru', '10'));
    }

    public function testSameKeyInDifferentFilesAndLanguagesStaysSeparate(): void
    {
        $this->store()->write('ru', '10', ['common' => 'ru-10']);
        $this->store()->write('ru', '20', ['common' => 'ru-20']);
        $this->store()->write('en', '10', ['common' => 'en-10']);
        $snap = $this->service->snapshot(['10', '20'], ['ru', 'en'], 'test');

        $this->service->apply($this->service->planImport([
            'ru' => ['10' => ['common' => 'изменили только тут']],
            'en' => ['10' => ['common' => 'en-10']],
        ], $snap['id'])['ops']);

        $this->assertSame('изменили только тут', $this->store()->read('ru', '10')['common']);
        $this->assertSame('ru-20', $this->store()->read('ru', '20')['common']);
        $this->assertSame('en-10', $this->store()->read('en', '10')['common']);
    }

    public function testForeignSnapshotIsRefusedInsteadOfBlindImport(): void
    {
        $this->store()->write('ru', '10', ['a' => 'a']);

        $this->assertSame('no-snapshot', $this->service->planImport(['ru' => ['10' => ['a' => 'x']]], '')['error']);
        $this->assertSame(
            'unknown',
            $this->service->planImport(['ru' => ['10' => ['a' => 'x']]], 'snp_' . str_repeat('b', 24))['error']
        );
        $this->assertSame(['a' => 'a'], $this->store()->read('ru', '10'));
    }

    public function testWriteBetweenPlanAndApplyIsReportedAsStale(): void
    {
        $this->store()->write('ru', '10', ['a' => 'старое']);
        $snap = $this->service->snapshot(['10'], ['ru'], 'test');
        $plan = $this->service->planImport(['ru' => ['10' => ['a' => 'книжное']]], $snap['id']);

        // Гонка: между планом и применением словарь переписал другой писатель.
        $this->store()->write('ru', '10', ['a' => 'нарезка']);

        $res = $this->service->apply($plan['ops']);
        $this->assertSame(0, $res['applied']);
        $this->assertCount(1, $res['stale']);
        $this->assertSame('нарезка', $this->store()->read('ru', '10')['a']);
    }

    public function testSheetPlanUsesManifestForLongAndAmbiguousSheetNames(): void
    {
        $rid = str_replace('/', '-', 'blog-очень-длинный-адрес-страницы-2026');
        $this->store()->write('ru', $rid, ['a' => 'a']);
        $sheetName = LexiconImport::sheetNameFor($rid);

        $sheets = [
            ['file' => 'b.xlsx', 'book' => 'b', 'sheet' => $sheetName,
             'headers' => ['lexicon_key', 'ru'], 'rows' => [['a', 'новое']]],
            ['file' => 'b.xlsx', 'book' => 'b', 'sheet' => LexiconImport::MANIFEST_SHEET,
             'headers' => ['sheet', 'rid'], 'rows' => [[$sheetName, $rid]]],
            ['file' => 'b.xlsx', 'book' => 'b', 'sheet' => LexiconImport::META_SHEET,
             'headers' => ['key', 'value'], 'rows' => [['snapshot_id', 'snp_x']]],
        ];

        $plan = $this->service->sheetPlan($sheets);
        $this->assertCount(1, $plan, 'служебные листы в план не попадают');
        $this->assertSame($rid, $plan[0]['target']);
        $this->assertSame(['ru' => [$rid => ['a' => 'новое']]], $this->service->desiredFromPlan($plan));
    }

    public function testDesiredFromPlanRejectsUnknownTargetsAndBadLanguageColumns(): void
    {
        $this->store()->write('ru', '10', ['a' => 'a']);

        $plan = [
            ['id' => 0, 'file' => 'b', 'sheet' => 'x', 'target' => 'нет-такого',
             'langs' => ['ru'], 'data' => ['a' => ['ru' => 'x']]],
            ['id' => 1, 'file' => 'b', 'sheet' => 'y', 'target' => '10',
             'langs' => ['../../etc'], 'data' => ['a' => ['../../etc' => 'x', 'ru' => 'ок']]],
        ];

        $this->assertSame(['ru' => ['10' => ['a' => 'ок']]], $this->service->desiredFromPlan($plan));
    }

    public function testDeliveredManifestIsSkippedOnRepeatedDeploy(): void
    {
        $this->store()->write('de', 'common', ['k' => 'v0']);
        $m1 = ['expected' => ['de' => ['common' => ['k' => 'v0']]], 'desired' => ['de' => ['common' => ['k' => 'v1']]]];
        $m2 = ['expected' => ['de' => ['common' => ['k' => 'v1']]], 'desired' => ['de' => ['common' => ['k' => 'v2']]]];

        $this->assertSame(1, $this->service->release($m1, 'm1.json', true)['result']['applied']);
        $this->assertSame(1, $this->service->release($m2, 'm2.json', true)['result']['applied']);
        $this->assertSame('v2', $this->store()->read('de', 'common')['k']);

        // Следующий деплой снова перебирает весь каталог манифестов: исторический
        // m1 не сходится с сервером, но он уже доставлен — это не конфликт.
        $again = $this->service->release($m1, 'm1.json', true);
        $this->assertTrue($again['skipped']);
        $this->assertSame([], $again['plan']['conflicts']);
        $this->assertSame('v2', $this->store()->read('de', 'common')['k']);
        $this->assertSame('m1.json', $this->service->releases()->applied(
            \MpcServices\Handlers\Lexicon\ReleaseLedger::fingerprint($m1)
        )['manifest']);
    }

    public function testReleaseWritesNothingWhenManagerEditsBetweenPlanAndApply(): void
    {
        $this->store()->write('de', 'a', ['k' => 'old']);
        $this->store()->write('de', 'b', ['k' => 'old']);
        $manifest = [
            'expected' => ['de' => ['a' => ['k' => 'old'], 'b' => ['k' => 'old']]],
            'desired'  => ['de' => ['a' => ['k' => 'new'], 'b' => ['k' => 'new']]],
        ];
        $plan = $this->service->planRelease($manifest['expected'], $manifest['desired']);
        $this->store()->write('de', 'b', ['k' => 'manager']); // правка между планом и применением

        $res = $this->service->apply($plan['ops'], [], ['tag' => 'release', 'atomic' => true]);

        $this->assertTrue($res['aborted']);
        $this->assertSame(0, $res['applied']);
        $this->assertSame('old', $this->store()->read('de', 'a')['k']);
        $this->assertSame('manager', $this->store()->read('de', 'b')['k']);
        // Незавершённый релиз в журнал не попадает — доставка повторяется.
        $this->assertNull($this->service->releases()->applied(
            \MpcServices\Handlers\Lexicon\ReleaseLedger::fingerprint($manifest)
        ));
    }

    public function testConflictingReleaseIsNotRecordedAndKeepsManagerValue(): void
    {
        $this->store()->write('de', 'a', ['k' => 'менеджер']);
        $manifest = [
            'expected' => ['de' => ['a' => ['k' => 'было']]],
            'desired'  => ['de' => ['a' => ['k' => 'станет']]],
        ];

        $res = $this->service->release($manifest, 'conflict.json', true);

        $this->assertFalse($res['skipped']);
        $this->assertCount(1, $res['plan']['conflicts']);
        $this->assertSame('менеджер', $this->store()->read('de', 'a')['k']);
        $this->assertNull($this->service->releases()->applied($res['fingerprint']));
    }

    public function testPruneIsDryRunUntilAskedAndBacksUpBeforeDeleting(): void
    {
        $this->store()->write('ru', '10', ['dead' => 'мёртвый', 'alive' => 'живой']);
        $this->service->orphans()->record('ru', '10', ['dead' => 'мёртвый']);

        $dry = $this->service->prune('ru');
        $this->assertTrue($dry['dryRun']);
        $this->assertCount(1, $dry['candidates']);
        $this->assertArrayHasKey('dead', $this->store()->read('ru', '10'));

        $done = $this->service->prune('ru', [], false);
        $this->assertSame(1, $done['cleared']);
        $this->assertSame(['alive' => 'живой'], $this->store()->read('ru', '10'));
        $this->assertFileExists($done['backup'] . 'ru/10.json');
        $this->assertSame([], $this->service->orphans()->load('ru', '10'));
    }

    public function testPruneKeepsKeyThatSomeoneRestored(): void
    {
        $this->store()->write('ru', '10', ['dead' => 'вернули руками']);
        $this->service->orphans()->record('ru', '10', ['dead' => 'мёртвый']);

        // Кандидат записан со старым значением, в файле уже другое — сверка
        // expected не даст удалить чужую правку.
        $res = $this->service->prune('ru', [], false);
        $this->assertSame(0, $res['cleared']);
        $this->assertCount(1, $res['stale']);
        $this->assertSame('вернули руками', $this->store()->read('ru', '10')['dead']);
        $this->assertArrayHasKey('dead', $this->service->orphans()->load('ru', '10'));
    }
}
