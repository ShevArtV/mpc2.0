<?php

namespace MpcTests\Unit\Mpcve;

use PHPUnit\Framework\TestCase;
use MpcVEServices\Handlers\Support\MediaLibrary;

/**
 * Общее ядро загрузки/валидации медиа (MediaLibrary): единая типизация, accept,
 * каноническая папка из mpc_download_paths, гибридная защита «тип ↔ папка»,
 * симметричные коллизии (дедуп+авто-суффикс / sha1-фолбэк) и обратный резолвер
 * url→папка для locate. Чистые хелперы статичны; настроечные — через modX-стаб.
 */
class MediaLibraryTest extends TestCase
{
    /** modX-стаб с переопределяемыми опциями (mpc_media_path / mpc_download_paths). */
    private function modx(array $opts = []): \modX
    {
        return new class($opts) extends \modX {
            public array $opts;
            public function __construct(array $opts) { parent::__construct(); $this->opts = $opts; }
            public function getOption(string $key, $default = null, $defaultValue = null): mixed
            {
                return $this->opts[$key] ?? ($defaultValue ?? $default);
            }
        };
    }

    // --- Типизация / accept -------------------------------------------------

    public function testTypeKeyOfExt(): void
    {
        $this->assertSame('images', MediaLibrary::typeKeyOfExt('JPG'));
        $this->assertSame('videos', MediaLibrary::typeKeyOfExt('mp4'));
        $this->assertSame('audios', MediaLibrary::typeKeyOfExt('mp3'));
        $this->assertSame('others', MediaLibrary::typeKeyOfExt('pdf'));
    }

    public function testAcceptExt(): void
    {
        $this->assertTrue(MediaLibrary::acceptExt('jpg', 'image'));
        $this->assertFalse(MediaLibrary::acceptExt('mp4', 'image'));
        $this->assertTrue(MediaLibrary::acceptExt('mp4', 'media'));
        $this->assertTrue(MediaLibrary::acceptExt('mp3', 'media'));
        $this->assertTrue(MediaLibrary::acceptExt('pdf', 'any'));
        $this->assertFalse(MediaLibrary::acceptExt('pdf', 'media'));
    }

    public function testSanitizeBase(): void
    {
        // транслит + lowercase + спецсимволы→дефис (URL-безопасное имя файла)
        $this->assertSame('my-photo_2', MediaLibrary::sanitizeBase('My Photo_2'));
        $this->assertSame('a-b', MediaLibrary::sanitizeBase('A!!!B'));
        $this->assertSame('', MediaLibrary::sanitizeBase('---'));
    }

    public function testTypeKeyOfAccept(): void
    {
        $this->assertSame('images', MediaLibrary::typeKeyOfAccept('image'));
        $this->assertSame('videos', MediaLibrary::typeKeyOfAccept('video'));
        $this->assertSame('audios', MediaLibrary::typeKeyOfAccept('audio'));
        $this->assertSame('others', MediaLibrary::typeKeyOfAccept('any'));
    }

    // --- Каноническая папка из mpc_download_paths ---------------------------

    public function testCanonicalDirDefault(): void
    {
        // База — источник файлов; canonicalDir относителен ему (без префикса).
        $lib = new MediaLibrary($this->modx());
        $this->assertSame('images/', $lib->canonicalDir('images'));
        $this->assertSame('videos/', $lib->canonicalDir('videos'));
    }

    public function testCanonicalDirCustomSubfolder(): void
    {
        $lib = new MediaLibrary($this->modx([
            'mpc_download_paths' => json_encode(['images' => 'gallery/img', 'videos' => '']),
        ]));
        $this->assertSame('gallery/img/', $lib->canonicalDir('images'));
        $this->assertSame('videos/', $lib->canonicalDir('videos')); // пусто → имя типа
    }

    public function testCanonicalDirAcceptsArrayOption(): void
    {
        // getOption может вернуть уже распарсенный массив (как у грабера).
        $lib = new MediaLibrary($this->modx([
            'mpc_download_paths' => ['audios' => 'sound'],
        ]));
        $this->assertSame('sound/', $lib->canonicalDir('audios'));
    }

    // --- Гибридная защита «тип ↔ папка» -------------------------------------

    public function testDirTypeKey(): void
    {
        $lib = new MediaLibrary($this->modx());
        $this->assertSame('images', $lib->dirTypeKey('images'));
        $this->assertSame('images', $lib->dirTypeKey('images/2024')); // вложенная
        $this->assertSame('videos', $lib->dirTypeKey('videos/'));
        $this->assertNull($lib->dirTypeKey('random'));
        $this->assertNull($lib->dirTypeKey('uploads/foo'));
    }

    public function testTypeFitsDir(): void
    {
        $lib = new MediaLibrary($this->modx());
        // картинка в папку картинок — ок; видео в папку картинок — нет.
        $this->assertTrue($lib->typeFitsDir('jpg', 'images'));
        $this->assertFalse($lib->typeFitsDir('mp4', 'images'));
        // картинка в папку аудио — нет.
        $this->assertFalse($lib->typeFitsDir('png', 'audios'));
        // обычная (нетипизированная) папка и others/ — пропускаем всё.
        $this->assertTrue($lib->typeFitsDir('mp4', 'uploads/clips'));
        $this->assertTrue($lib->typeFitsDir('pdf', 'others'));
    }

    // --- resolveUploaded (резолв финала от источника: pred + base.<thumbnailType>) --

    private string $tmp = '';

    protected function tearDown(): void
    {
        if ($this->tmp !== '') {
            @system('rm -rf ' . escapeshellarg($this->tmp));
            $this->tmp = '';
        }
    }

    /** Файловый fake-source: getBasePath(temp), thumbnailType, sanitizePath(no-op), getObjectUrl. */
    private function fsUploadSource(string $thumbnailType = 'webp'): object
    {
        $this->tmp = sys_get_temp_dir() . '/mpcve_ru_' . uniqid('', true);
        @mkdir($this->tmp, 0777, true);
        return new class($this->tmp, $thumbnailType) {
            public string $base;
            public string $tt;
            public object $fileHandler;
            public function __construct(string $b, string $tt)
            {
                $this->base = rtrim($b, '/');
                $this->tt   = $tt;
                $this->fileHandler = new class {
                    public function sanitizePath($p) { return (string)$p; }
                };
            }
            public function getBasePath($o = '') { return $this->base . '/'; }
            public function getOption($k, $o = null, $d = null) { return $k === 'thumbnailType' ? $this->tt : $d; }
            public function getObjectUrl($p) { return '/' . ltrim((string)$p, '/'); }
        };
    }

    /** Положить файл в источник (относительно base) с опц. mtime. */
    private function putFile(object $src, string $relPath, int $mtime = 0): void
    {
        $full = $src->getBasePath() . ltrim($relPath, '/');
        @mkdir(dirname($full), 0777, true);
        file_put_contents($full, 'x');
        if ($mtime) { @touch($full, $mtime); }
    }

    public function testResolveUploadedExactName(): void
    {
        // конвертации не было (файл под исходным именем на месте)
        $src = $this->fsUploadSource('webp');
        $this->putFile($src, 'images/foo.jpg');
        $res = MediaLibrary::resolveUploaded($src, 'images/', 'foo.jpg');
        $this->assertSame('images/foo.jpg', $res['path']);
        $this->assertSame('/images/foo.jpg', $res['url']);
    }

    public function testResolveUploadedConvertedReplaced(): void
    {
        // конвертер заменил: оригинала нет, есть base.<thumbnailType>
        $src = $this->fsUploadSource('webp');
        $this->putFile($src, 'images/foo.webp');
        $res = MediaLibrary::resolveUploaded($src, 'images/', 'foo.jpg');
        $this->assertSame('images/foo.webp', $res['path']);
    }

    public function testResolveUploadedConvertedOriginalKept(): void
    {
        // оба файла есть (конвертер не удалил оригинал) → новее по mtime (alt)
        $src = $this->fsUploadSource('webp');
        $this->putFile($src, 'images/foo.jpg', 1000);
        $this->putFile($src, 'images/foo.webp', 2000);
        $res = MediaLibrary::resolveUploaded($src, 'images/', 'foo.jpg');
        $this->assertSame('images/foo.webp', $res['path']);
    }

    public function testResolveUploadedStaleAltDoesNotWin(): void
    {
        // старый одноимённый .webp с прошлой загрузки НЕ перебивает свежий оригинал
        $src = $this->fsUploadSource('webp');
        $this->putFile($src, 'images/foo.webp', 1000); // старый
        $this->putFile($src, 'images/foo.jpg', 2000);  // свежий, конвертации не было
        $res = MediaLibrary::resolveUploaded($src, 'images/', 'foo.jpg');
        $this->assertSame('images/foo.jpg', $res['path']);
    }

    public function testResolveUploadedFallbackMissing(): void
    {
        // ни pred, ни alt нет (плагин унёс файл) → фолбэк на ожидаемое имя
        $src = $this->fsUploadSource('webp');
        $res = MediaLibrary::resolveUploaded($src, 'images/', 'foo.jpg');
        $this->assertSame('images/foo.jpg', $res['path']);
    }

    public function testResolveUploadedNonFileSource(): void
    {
        // источник без getBasePath → диск не проверяем, отдаём ожидаемое имя
        $src = new class {
            public function getObjectUrl($p) { return '/' . ltrim((string)$p, '/'); }
        };
        $res = MediaLibrary::resolveUploaded($src, 'img/', 'foo.png');
        $this->assertSame('img/foo.png', $res['path']);
        $this->assertSame('/img/foo.png', $res['url']);
    }

    // --- folderOfUrl (обратный резолвер для locate) -------------------------

    public function testFolderOfUrlRootAnchored(): void
    {
        $src = new class {
            public function getBaseUrl() { return '/assets/components/migxpageconfigurator/media/'; }
        };
        $this->assertSame(
            'images',
            MediaLibrary::folderOfUrl($src, '/assets/components/migxpageconfigurator/media/images/foo.jpg')
        );
    }

    public function testFolderOfUrlAbsoluteWithNesting(): void
    {
        $src = new class {
            public function getBaseUrl() { return '/assets/components/migxpageconfigurator/media/'; }
        };
        $this->assertSame(
            'images/2024',
            MediaLibrary::folderOfUrl($src, 'http://example.com/assets/components/migxpageconfigurator/media/images/2024/foo.jpg')
        );
    }

    public function testFolderOfUrlRootFile(): void
    {
        $src = new class {
            public function getBaseUrl() { return '/media/'; }
        };
        // файл в корне источника → пустая папка
        $this->assertSame('', MediaLibrary::folderOfUrl($src, '/media/foo.jpg'));
    }

    public function testFolderOfUrlExternalReturnsRoot(): void
    {
        $src = new class {
            public function getBaseUrl() { return '/media/'; }
        };
        // URL вне источника (чужой домен/префикс) → корень, не «папка» из чужого дерева
        $this->assertSame('', MediaLibrary::folderOfUrl($src, 'https://cdn.other.net/x/y/foo.jpg'));
        $this->assertSame('', MediaLibrary::folderOfUrl($src, ''));
    }
}
