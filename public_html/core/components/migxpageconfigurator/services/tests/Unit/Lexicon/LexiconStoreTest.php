<?php

namespace MpcTests\Unit\Lexicon;

use MpcServices\Handlers\Lexicon\LexiconMerge as M;
use MpcServices\Handlers\Lexicon\LexiconStore;
use PHPUnit\Framework\TestCase;

/** Запись словаря: атомарность, сверка ожидаемого значения, спецсимволы. */
class LexiconStoreTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/mpc_store_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '/';
        @mkdir($this->base . 'ru/', 0777, true);
    }

    protected function tearDown(): void
    {
        @system('rm -rf ' . escapeshellarg(rtrim($this->base, '/')));
    }

    private function store(): LexiconStore
    {
        return new LexiconStore($this->base);
    }

    public function testWriteAndReadRoundTripKeepsTrickyValues(): void
    {
        $s  = $this->store();
        $kv = [
            'quote'   => "О'Генри \\ бэкслеш",
            'html'    => "<p>Строка 1</p>\n<p>Строка 2</p>",
            'ph'      => '[[+placeholder]] и {fenom}',
            'unicode' => 'Ünïcode — тире',
        ];
        $this->assertTrue($s->write('ru', '10', $kv));

        $read = $s->read('ru', '10');
        ksort($kv); // файл пишется отсортированным по ключу
        $this->assertSame($kv, $read);
    }

    public function testApplyWritesOnlyWhenCurrentStillMatches(): void
    {
        $s = $this->store();
        $s->write('ru', '10', ['a' => 'старое', 'b' => 'b']);

        $res = $s->apply([
            ['lang' => 'ru', 'rid' => '10', 'key' => 'a', 'action' => M::WRITE,
             'current' => 'старое', 'desired' => 'новое'],
            // План считали, когда 'b' было 'устарело' — файл с тех пор другой.
            ['lang' => 'ru', 'rid' => '10', 'key' => 'b', 'action' => M::WRITE,
             'current' => 'устарело', 'desired' => 'затирающее'],
        ]);

        $this->assertSame(1, $res['applied']);
        $this->assertCount(1, $res['stale']);
        $this->assertSame('b', $res['stale'][0]['key']);
        $this->assertSame(['a' => 'новое', 'b' => 'b'], $s->read('ru', '10'));
    }

    public function testAtomicApplyWritesNothingWhenOneOperationIsStale(): void
    {
        $s = $this->store();
        $s->write('de', 'a', ['k' => 'old']);
        $s->write('de', 'b', ['k' => 'manager']); // менеджер успел до применения

        $res = $s->apply([
            ['lang' => 'de', 'rid' => 'a', 'key' => 'k', 'action' => M::WRITE,
             'current' => 'old', 'desired' => 'new'],
            ['lang' => 'de', 'rid' => 'b', 'key' => 'k', 'action' => M::WRITE,
             'current' => 'old', 'desired' => 'new'],
        ], ['atomic' => true]);

        // Страница не должна получить половину своих ключей.
        $this->assertTrue($res['aborted']);
        $this->assertSame(0, $res['applied']);
        $this->assertCount(1, $res['stale']);
        $this->assertSame([], $res['touched']);
        $this->assertSame('old', $s->read('de', 'a')['k']);
        $this->assertSame('manager', $s->read('de', 'b')['k']);
    }

    public function testFailedWriteRollsBackFilesAlreadyWrittenInTheSameSet(): void
    {
        $s = $this->store();
        $s->write('de', 'a', ['k' => 'old']);
        $s->write('fr', 'a', ['k' => 'old']);
        // Второй язык становится незаписываемым: файл de уже переписан, fr — нет.
        @chmod($this->base . 'fr', 0555);

        $res = $s->apply([
            ['lang' => 'de', 'rid' => 'a', 'key' => 'k', 'action' => M::WRITE,
             'current' => 'old', 'desired' => 'new'],
            ['lang' => 'fr', 'rid' => 'a', 'key' => 'k', 'action' => M::WRITE,
             'current' => 'old', 'desired' => 'new'],
        ], ['atomic' => true]);
        @chmod($this->base . 'fr', 0755);

        $this->assertTrue($res['aborted']);
        $this->assertSame(0, $res['applied']);
        $this->assertSame(['fr/a'], $res['failed']);
        $this->assertSame(['de/a'], $res['rolledBack']);
        $this->assertSame('old', $s->read('de', 'a')['k']);
        $this->assertSame('old', $s->read('fr', 'a')['k']);
    }

    public function testClearRemovesKeyAndKeepsTheRest(): void
    {
        $s = $this->store();
        $s->write('ru', '10', ['a' => 'a', 'b' => 'b']);

        $res = $s->apply([
            ['lang' => 'ru', 'rid' => '10', 'key' => 'a', 'action' => M::CLEAR,
             'current' => 'a', 'desired' => null],
        ]);

        $this->assertSame(1, $res['cleared']);
        $this->assertSame(['b' => 'b'], $s->read('ru', '10'));
    }

    public function testNewKeyIsWrittenWhenItWasAbsent(): void
    {
        $s = $this->store();
        $s->write('ru', '10', ['a' => 'a']);

        $res = $s->apply([
            ['lang' => 'ru', 'rid' => '10', 'key' => 'new', 'action' => M::WRITE,
             'current' => null, 'desired' => 'значение'],
        ]);

        $this->assertSame(1, $res['applied']);
        $this->assertSame('значение', $s->read('ru', '10')['new']);
    }

    public function testSanitizerIsAppliedOnWrite(): void
    {
        $s = new LexiconStore($this->base, static fn(string $v): string => strip_tags($v));
        $s->apply([
            ['lang' => 'ru', 'rid' => '10', 'key' => 'a', 'action' => M::WRITE,
             'current' => null, 'desired' => '<b>жирный</b>'],
        ]);
        $this->assertSame('жирный', $s->read('ru', '10')['a']);
    }

    public function testWriteOfEmptySetDeletesFileAndReadIsSafe(): void
    {
        $s = $this->store();
        $s->write('ru', '10', ['a' => 'a']);
        $s->write('ru', '10', []);

        $this->assertFileDoesNotExist($this->base . 'ru/10.inc.php');
        $this->assertSame([], $s->read('ru', '10'));
    }

    public function testNestedLockDoesNotDeadlock(): void
    {
        $s = $this->store();
        $res = $s->withLock(static function (LexiconStore $inner) {
            return $inner->apply([
                ['lang' => 'ru', 'rid' => '10', 'key' => 'a', 'action' => M::WRITE,
                 'current' => null, 'desired' => 'ок'],
            ]);
        });
        $this->assertSame(1, $res['applied']);
    }

    public function testExistingRidsSkipsSystemFiles(): void
    {
        $s = $this->store();
        $s->write('ru', '10', ['a' => 'a']);
        $s->write('ru', 'default', ['a' => 'a']);

        $this->assertSame(['10'], $s->existingRids('ru'));
    }

    public function testPathIsConfinedToLexiconBase(): void
    {
        $this->assertSame(
            $this->base . 'ru/10.inc.php',
            $this->store()->path('../../ru', '../../10')
        );
    }
}
