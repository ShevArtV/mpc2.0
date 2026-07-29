<?php

namespace MpcTests\Unit\Media;

use MpcServices\Handlers\Media\RemoteMediaIngestor;
use MpcTests\Stubs\ModxStub;
use PHPUnit\Framework\TestCase;

/**
 * modX-заглушка, считающая invokeEvent (для проверки нативных событий загрузки).
 */
class CountingModxStub extends ModxStub
{
    /** @var array<int,array{name:string,params:array}> */
    public array $events = [];

    public function invokeEvent(string $name, array $params = []): void
    {
        $this->events[] = ['name' => $name, 'params' => $params];
        $this->event->returnedValues = [];
    }

    public function eventNames(): array
    {
        return array_map(function ($e) { return $e['name']; }, $this->events);
    }
}

/**
 * Фейковый источник: createObject пишет в baseDir, copит вызовы, отдаёт url от корня.
 */
function fakeSource(string $baseDir)
{
    return new class($baseDir) {
        public string $base;
        public array $created = [];
        public array $containers = [];
        public ?string $thumbnailType = null;

        public function __construct(string $b) { $this->base = rtrim($b, '/'); }
        public function initialize(): void {}
        public function createContainer($name, $parent)
        {
            $this->containers[] = ['name' => $name, 'parent' => $parent];
            $rel = ($parent === '/' ? '' : $parent) . $name;
            $p = $this->base . '/' . trim((string)$rel, '/');
            if (!is_dir($p)) { @mkdir($p, 0777, true); }
            return true;
        }
        public function createObject($dir, $name, $content)
        {
            $this->created[] = ['dir' => $dir, 'name' => $name, 'content' => $content];
            $full = $this->base . '/' . ltrim($dir . $name, '/');
            @mkdir(dirname($full), 0777, true);
            return file_put_contents($full, $content) !== false ? ($dir . $name) : false;
        }
        public function getObjectUrl($path) { return '/' . ltrim((string)$path, '/'); }
        public function getBasePath($object = '') { return $this->base . '/'; }
        public function getPropertyList() { return $this->thumbnailType ? ['thumbnailType' => $this->thumbnailType] : []; }
        public function getErrors() { return []; }
    };
}

class RemoteMediaIngestorTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/mpc_ingest_' . uniqid();
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) { return; }
        foreach (glob($dir . '/*') as $entry) {
            is_dir($entry) ? $this->removeDir($entry) : unlink($entry);
        }
        rmdir($dir);
    }

    private function ingestor(array $opts = []): RemoteMediaIngestor
    {
        return new RemoteMediaIngestor(new ModxStub(), $opts);
    }

    // --- SSRF-гард --------------------------------------------------------

    public function testIsSafeUrlRejectsNonHttpSchemes(): void
    {
        $i = $this->ingestor();
        $this->assertFalse($i->isSafeUrl('file:///etc/passwd'));
        $this->assertFalse($i->isSafeUrl('ftp://8.8.8.8/x.jpg'));
        $this->assertFalse($i->isSafeUrl('gopher://8.8.8.8/'));
    }

    public function testIsSafeUrlRejectsPrivateAndReservedIps(): void
    {
        $i = $this->ingestor();
        foreach (['http://127.0.0.1/a.jpg', 'http://10.0.0.1/a.jpg', 'http://192.168.1.1/a.jpg',
                  'http://169.254.169.254/latest/meta-data', 'http://[::1]/a.jpg'] as $url) {
            $this->assertFalse($i->isSafeUrl($url), $url . ' должен блокироваться');
        }
    }

    public function testIsSafeUrlAllowsPublicIp(): void
    {
        $i = $this->ingestor();
        $this->assertTrue($i->isSafeUrl('http://8.8.8.8/a.jpg'));
        $this->assertTrue($i->isSafeUrl('https://1.1.1.1/a.png'));
    }

    public function testIsSafeUrlRejectsGarbage(): void
    {
        $i = $this->ingestor();
        $this->assertFalse($i->isSafeUrl(''));
        $this->assertFalse($i->isSafeUrl('not-a-url'));
    }

    // --- Content sniffing -------------------------------------------------

    public function testIsAllowedContentRejectsScripts(): void
    {
        $i = $this->ingestor();
        $this->assertFalse($i->isAllowedContent('<?php echo 1; ?>'));
        $this->assertFalse($i->isAllowedContent('GIF<script>alert(1)</script>'));
        $this->assertFalse($i->isAllowedContent('<!DOCTYPE html><html></html>'));
        $this->assertFalse($i->isAllowedContent('<?= $x ?>'));
    }

    public function testIsAllowedContentAllowsBinaryish(): void
    {
        $i = $this->ingestor();
        $this->assertTrue($i->isAllowedContent("\x89PNG\r\n\x1a\n binary data"));
        $this->assertTrue($i->isAllowedContent('GIF89a' . str_repeat("\x00", 32)));
    }

    // --- store(): запись + события + резолв -------------------------------

    public function testStoreWritesViaCreateObjectAndResolvesUrl(): void
    {
        $i = $this->ingestor();
        $src = fakeSource($this->tmpDir);
        $res = $i->store($src, 'images/', 'hero.jpg', 'data', false);

        $this->assertNotNull($res);
        $this->assertSame('/images/hero.jpg', $res['url']);
        $this->assertSame('images/hero.jpg', $res['path']);
        $this->assertSame('hero.jpg', $res['name']);
        $this->assertCount(1, $src->created);
        $this->assertFileExists($this->tmpDir . '/images/hero.jpg');
    }

    public function testStoreFiresNativeUploadEvents(): void
    {
        $modx = new CountingModxStub();
        $i = new RemoteMediaIngestor($modx);
        $src = fakeSource($this->tmpDir);
        $i->store($src, 'images/', 'a.jpg', 'data', true);

        $names = $modx->eventNames();
        $this->assertContains('OnFileManagerBeforeUpload', $names);
        $this->assertContains('OnFileManagerUpload', $names);
    }

    public function testStoreCanDisableEvents(): void
    {
        $modx = new CountingModxStub();
        $i = new RemoteMediaIngestor($modx);
        $src = fakeSource($this->tmpDir);
        $i->store($src, 'images/', 'a.jpg', 'data', false);

        $this->assertSame([], $modx->eventNames());
    }

    public function testStoreHonoursNameChangedByPluginOnBeforeUpload(): void
    {
        // Раньше событие здесь было бутафорией: плагин менял имя в дескрипторе, а
        // store() всё равно писал свою переменную и от неё резолвил URL — плагин
        // не работал, а ссылка могла указывать не на тот файл.
        $modx = new class extends CountingModxStub {
            public function invokeEvent(string $name, array $params = []): void
            {
                $this->events[] = ['name' => $name, 'params' => $params];
                $this->event->returnedValues = [];
                if ($name === 'OnFileManagerBeforeUpload') {
                    $params['file']['name'] = 'Переименовано Плагином.JPG';
                }
            }
        };
        $i   = new RemoteMediaIngestor($modx);
        $src = fakeSource($this->tmpDir);
        $res = $i->store($src, 'images/', 'a.jpg', 'data', true);

        // Имя от плагина принято, но пропущено через единую точку нормализации.
        $this->assertSame('pereimenovano-plaginom.jpg', $res['name']);
        $this->assertSame('images/pereimenovano-plaginom.jpg', $res['path']);
        $this->assertFileExists($this->tmpDir . '/images/pereimenovano-plaginom.jpg');
        $this->assertFileDoesNotExist($this->tmpDir . '/images/a.jpg');
    }

    public function testStoreHardensHostileNameFromPlugin(): void
    {
        // Плагин на нативном событии не должен уметь увести запись из каталога.
        $modx = new class extends CountingModxStub {
            public function invokeEvent(string $name, array $params = []): void
            {
                $this->events[] = ['name' => $name, 'params' => $params];
                $this->event->returnedValues = [];
                if ($name === 'OnFileManagerBeforeUpload') {
                    $params['file']['name'] = '../../shell.php';
                }
            }
        };
        $i   = new RemoteMediaIngestor($modx);
        $src = fakeSource($this->tmpDir);
        $res = $i->store($src, 'images/', 'a.jpg', 'data', true);

        $this->assertStringStartsWith('images/', $res['path']);
        $this->assertStringNotContainsString('..', $res['path']);
        $this->assertStringNotContainsString('.php', $res['name']);
    }

    public function testStoreReturnsNullOnWriteFailure(): void
    {
        $i = $this->ingestor();
        $src = new class {
            public function createContainer($n, $p) { return true; }
            public function createObject($d, $n, $c) { return false; }
            public function getErrors() { return ['disk full']; }
        };
        $this->assertNull($i->store($src, 'images/', 'a.jpg', 'data', false));
    }

    // --- resolveFinal(): конвертация плагином -----------------------------

    public function testResolveFinalPrefersNewerConvertedFile(): void
    {
        $i = $this->ingestor();
        $src = fakeSource($this->tmpDir);
        $src->thumbnailType = 'webp';
        @mkdir($this->tmpDir . '/images', 0777, true);
        // оригинал старее, конвертированный новее (как после плагина-конвертера)
        file_put_contents($this->tmpDir . '/images/pic.jpg', 'orig');
        touch($this->tmpDir . '/images/pic.jpg', time() - 100);
        file_put_contents($this->tmpDir . '/images/pic.webp', 'conv');

        $res = $i->resolveFinal($src, 'images/', 'pic.jpg');
        $this->assertSame('pic.webp', $res['name']);
        $this->assertSame('/images/pic.webp', $res['url']);
    }

    public function testResolveFinalFallsBackToPredWhenNoConversion(): void
    {
        $i = $this->ingestor();
        $src = fakeSource($this->tmpDir);
        @mkdir($this->tmpDir . '/images', 0777, true);
        file_put_contents($this->tmpDir . '/images/pic.jpg', 'orig');

        $res = $i->resolveFinal($src, 'images/', 'pic.jpg');
        $this->assertSame('pic.jpg', $res['name']);
    }

    // --- ensureContainer --------------------------------------------------

    public function testEnsureContainerCreatesNestedLevels(): void
    {
        $i = $this->ingestor();
        $src = fakeSource($this->tmpDir);
        $i->ensureContainer($src, 'images/hero/');

        $this->assertDirectoryExists($this->tmpDir . '/images/hero');
        $this->assertSame('images/', $src->containers[0]['name']);
        $this->assertSame('hero/', $src->containers[1]['name']);
    }

    // --- getLastError -----------------------------------------------------

    public function testFetchSetsSsrfErrorForUnsafeUrl(): void
    {
        $i = $this->ingestor();
        $this->assertSame('', $i->fetch('http://127.0.0.1/a.jpg'));
        $this->assertSame(RemoteMediaIngestor::ERR_SSRF, $i->getLastError());
    }
}
