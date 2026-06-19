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

    // --- resolveUploaded (контракт sgUploads + фолбэк) ----------------------

    /** Источник с getObjectUrl (root-anchored) и опциональными sgUploads/fileHandler. */
    private function urlSource(?array $sgUploads, bool $withFileHandler = false): object
    {
        $src = new class {
            public ?array $sgUploads = null;
            public ?object $fileHandler = null;
            public function getObjectUrl($p) { return '/' . ltrim((string)$p, '/'); }
        };
        $src->sgUploads = $sgUploads;
        if ($withFileHandler) {
            $src->fileHandler = new class {
                // имитируем нормализацию ядра: пробел → подчёркивание
                public function sanitizePath($p) { return str_replace(' ', '_', (string)$p); }
            };
        }
        return $src;
    }

    public function testResolveUploadedFromContractByOriginal(): void
    {
        $src = $this->urlSource([
            ['original' => 'other.jpg', 'name' => 'other.webp', 'path' => 'm/images/other.webp', 'url' => 'https://x/other.webp'],
            ['original' => 'foo.jpg',   'name' => 'foo.webp',   'path' => 'm/images/foo.webp',   'url' => 'https://x/foo.webp'],
        ]);
        $res = MediaLibrary::resolveUploaded($src, 'm/images/', 'foo.jpg');
        $this->assertSame('https://x/foo.webp', $res['url']);
        $this->assertSame('m/images/foo.webp', $res['path']);
    }

    public function testResolveUploadedSingleElementWhenOriginalMissing(): void
    {
        // редактор грузит по одному → если original не совпал, берём единственный
        $src = $this->urlSource([
            ['original' => 'whatever.jpg', 'path' => 'm/images/conv.webp', 'url' => 'https://x/conv.webp'],
        ]);
        $res = MediaLibrary::resolveUploaded($src, 'm/images/', 'foo.jpg');
        $this->assertSame('https://x/conv.webp', $res['url']);
    }

    public function testResolveUploadedPathOnlyBuildsUrl(): void
    {
        // в контракте только path → URL строим через getObjectUrl(path)
        $src = $this->urlSource([
            ['original' => 'foo.jpg', 'path' => 'm/images/foo.webp'],
        ]);
        $res = MediaLibrary::resolveUploaded($src, 'm/images/', 'foo.jpg');
        $this->assertSame('/m/images/foo.webp', $res['url']);
        $this->assertSame('m/images/foo.webp', $res['path']);
    }

    public function testResolveUploadedFallbackNoPlugin(): void
    {
        // sgUploads пуст (sleepandglow нет) → детерминированный путь + sanitizePath
        $src = $this->urlSource(null, true);
        $res = MediaLibrary::resolveUploaded($src, 'm/images/', 'my photo.jpg');
        $this->assertSame('m/images/my_photo.jpg', $res['path']);
        $this->assertSame('/m/images/my_photo.jpg', $res['url']);
    }

    public function testResolveUploadedFallbackWithoutFileHandler(): void
    {
        // нет fileHandler → имя как есть
        $src = $this->urlSource(null, false);
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
