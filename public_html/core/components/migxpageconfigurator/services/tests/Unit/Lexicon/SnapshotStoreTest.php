<?php

namespace MpcTests\Unit\Lexicon;

use MpcServices\Handlers\Lexicon\SnapshotStore;
use PHPUnit\Framework\TestCase;

/** Хранилище снимков выгрузки: пригодность, отказ и срок жизни. */
class SnapshotStoreTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/mpc_snap_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '/';
    }

    protected function tearDown(): void
    {
        @system('rm -rf ' . escapeshellarg(rtrim($this->dir, '/')));
    }

    public function testCreatedSnapshotIsLoadedBackWithScopeAndEntries(): void
    {
        $s  = new SnapshotStore($this->dir);
        $id = $s->create(['10' => ['ru' => ['a' => 'значение']]], ['mode' => 'export', 'rids' => ['10'], 'langs' => ['ru']], 7);

        $loaded = $s->load($id);
        $this->assertNotNull($loaded);
        $this->assertSame('значение', $loaded['entries']['10']['ru']['a']);
        $this->assertSame('export', $loaded['scope']['mode']);
        $this->assertSame(7, $loaded['created_by']);
        $this->assertSame('', $s->reject($id));
    }

    public function testDirectoryIsClosedFromDirectDownload(): void
    {
        $s = new SnapshotStore($this->dir);
        $s->create([], []);

        $this->assertFileExists($this->dir . 'index.php');
        $this->assertFileExists($this->dir . '.htaccess');
    }

    public function testUnknownAndMalformedIdsAreRejected(): void
    {
        $s = new SnapshotStore($this->dir);

        $this->assertSame('unknown', $s->reject('snp_' . str_repeat('a', 24)));
        $this->assertSame('unknown', $s->reject('../../etc/passwd'));
        $this->assertNull($s->load('snp_нет'));
    }

    public function testForeignFormatVersionIsRejectedNotSilentlyImported(): void
    {
        $s  = new SnapshotStore($this->dir);
        $id = $s->create(['10' => ['ru' => []]]);

        $path = $this->dir . $id . '.json';
        $data = json_decode((string)file_get_contents($path), true);
        $data['format_version'] = SnapshotStore::FORMAT_VERSION + 1;
        file_put_contents($path, json_encode($data));

        $this->assertSame('format', $s->reject($id));
        $this->assertNull($s->load($id));
    }

    public function testSweepRemovesOnlyExpiredSnapshots(): void
    {
        $s   = new SnapshotStore($this->dir);
        $old = $s->create([]);
        $new = $s->create([]);
        touch($this->dir . $old . '.json', time() - 40 * 86400);

        $this->assertSame(1, $s->sweep(30));
        $this->assertFalse($s->exists($old));
        $this->assertTrue($s->exists($new));
    }

    public function testBackupStoresFilesPerLanguage(): void
    {
        $s   = new SnapshotStore($this->dir);
        $dir = $s->backup('prune', ['10' => ['ru' => ['a' => 'значение']]]);

        $file = $dir . 'ru/10.json';
        $this->assertFileExists($file);
        $this->assertSame(['a' => 'значение'], json_decode((string)file_get_contents($file), true));
    }
}
