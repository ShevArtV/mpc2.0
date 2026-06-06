<?php

namespace MpcTests\Unit\Grabber;

use MpcServices\Handlers\PendingTranslations;
use PHPUnit\Framework\TestCase;

/**
 * Тесты сайдкар-реестра непереведённых ключей (подход «явный pending-list»).
 */
class PendingTranslationsTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/mpc_pending_' . uniqid();
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmpDir);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            $p = $dir . '/' . $f;
            is_dir($p) ? $this->rrmdir($p) : unlink($p);
        }
        rmdir($dir);
    }

    public function testLoadEmptyWhenNoFile(): void
    {
        $p = new PendingTranslations($this->tmpDir);
        $this->assertSame([], $p->load('en', '42'));
    }

    public function testSaveThenLoadRoundTrip(): void
    {
        $p = new PendingTranslations($this->tmpDir);
        $p->save('en', '42', ['hero_title', 'cta_text']);
        $this->assertSame(['hero_title', 'cta_text'], $p->load('en', '42'));
        // файл лежит в .pending/, невидим для *.inc.php-сканов
        $this->assertFileExists($this->tmpDir . '/en/.pending/42.json');
    }

    public function testSaveEmptyRemovesFile(): void
    {
        $p = new PendingTranslations($this->tmpDir);
        $p->save('en', '42', ['x']);
        $p->save('en', '42', []);
        $this->assertFileDoesNotExist($this->tmpDir . '/en/.pending/42.json');
        $this->assertSame([], $p->load('en', '42'));
    }

    public function testSaveDedupesAndDropsNonStrings(): void
    {
        $p = new PendingTranslations($this->tmpDir);
        $p->save('en', '42', ['a', 'a', 'b', 123, null]);
        $this->assertSame(['a', 'b'], $p->load('en', '42'));
    }

    public function testRemoveDropsKey(): void
    {
        $p = new PendingTranslations($this->tmpDir);
        $p->save('en', '42', ['a', 'b', 'c']);
        $p->remove('en', '42', 'b');
        $this->assertSame(['a', 'c'], $p->load('en', '42'));
    }

    public function testRemoveLastKeyDeletesFile(): void
    {
        $p = new PendingTranslations($this->tmpDir);
        $p->save('en', '42', ['only']);
        $p->remove('en', '42', 'only');
        $this->assertFileDoesNotExist($this->tmpDir . '/en/.pending/42.json');
    }

    public function testSyncAddsNewKeysOnly(): void
    {
        $p = new PendingTranslations($this->tmpDir);
        // default имеет 3 ключа; в языке уже был один (title) → pending = новые два
        $p->sync('en', '42', ['title', 'lead', 'cta'], ['title']);
        $this->assertSame(['lead', 'cta'], $p->load('en', '42'));
    }

    public function testSyncKeepsPreviouslyPending(): void
    {
        $p = new PendingTranslations($this->tmpDir);
        $p->save('en', '42', ['lead']);            // lead уже числился pending
        // lead есть и в файле языка (existing), но остаётся pending (был в реестре)
        $p->sync('en', '42', ['title', 'lead'], ['title', 'lead']);
        $this->assertSame(['lead'], $p->load('en', '42'));
    }

    public function testSyncDropsOrphanPending(): void
    {
        $p = new PendingTranslations($this->tmpDir);
        $p->save('en', '42', ['gone', 'lead']);
        // 'gone' ушёл из дефолтного набора → выкидываем; 'lead' остаётся
        $p->sync('en', '42', ['title', 'lead'], ['title']);
        $this->assertSame(['lead'], $p->load('en', '42'));
    }

    public function testSyncTranslatedKeyNotReadded(): void
    {
        $p = new PendingTranslations($this->tmpDir);
        // ключ был переведён ранее (есть в existing, НЕ в pending) → не возвращаем
        $p->sync('en', '42', ['title', 'lead'], ['title', 'lead']);
        $this->assertSame([], $p->load('en', '42'));
    }
}
