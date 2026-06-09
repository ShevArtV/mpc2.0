<?php

namespace MpcTests\Unit\Grabber;

use MpcServices\Handlers\Grabber\MediaDownloader;
use MpcTests\Stubs\ModxStub;
use PHPUnit\Framework\TestCase;

// ---------------------------------------------------------------------------
// Тестируемый подкласс: мокает fetch() (HTTP) и getMediaSource() (источник).
// Фейковый источник пишет в baseDir (tmpDir) и копит createObject-вызовы.
// ---------------------------------------------------------------------------

class TestableMediaDownloader extends MediaDownloader
{
    public string $baseDir;
    /** Возвращаемый fetch()-контент (null = имитировать ошибку curl) */
    public ?string $httpContent = 'fake-image-content';
    /** Имитация Content-Type для detectExtensionByContentType ('' = не определён) */
    public string $fakeContentType = '';
    public int $fetchCalls = 0;
    public bool $noSource = false;
    /** @var object фейковый источник (createObject пишет в baseDir, копит created) */
    public $fakeSource;

    private array $testProps;

    public function __construct(\modX $modx, array $properties, string $baseDir)
    {
        parent::__construct($modx, $properties);
        $this->testProps = $properties;
        $this->baseDir = rtrim($baseDir, '/');

        $base = $this->baseDir;
        $this->fakeSource = new class($base) {
            public string $base;
            /** @var array<array{dir:string,name:string,content:string}> */
            public array $created = [];

            public function __construct(string $b)
            {
                $this->base = rtrim($b, '/');
            }

            public function initialize(): void {}

            public function createContainer($name, $parent)
            {
                $rel = ($parent === '/' ? '' : $parent) . $name;
                $p = $this->base . '/' . trim((string)$rel, '/');
                if (!is_dir($p)) {
                    @mkdir($p, 0777, true);
                }
                return true;
            }

            public function createObject($dir, $name, $content)
            {
                $this->created[] = ['dir' => $dir, 'name' => $name, 'content' => $content];
                $full = $this->base . '/' . ltrim($dir . $name, '/');
                @mkdir(dirname($full), 0777, true);
                return file_put_contents($full, $content) !== false ? ($dir . $name) : false;
            }

            public function getObjectUrl($path)
            {
                return '/' . ltrim((string)$path, '/');
            }

            public function getBasePath($object = '')
            {
                return $this->base . '/';
            }

            public function getErrors()
            {
                return [];
            }
        };
    }

    public function detectExtensionByContentType(string $url): string
    {
        if (!$this->fakeContentType) {
            return '';
        }
        $mime = strtolower(explode(';', $this->fakeContentType)[0]);
        $mimeToExt = $this->testProps['mimeToExt'] ?? [];
        $extension = $mimeToExt[$mime] ?? '';
        return in_array($extension, $this->testProps['downloadExtensions']) ? $extension : '';
    }

    protected function getMediaSource()
    {
        return $this->noSource ? null : $this->fakeSource;
    }

    protected function fetch(string $url): string
    {
        $this->fetchCalls++;
        return $this->httpContent ?? '';
    }
}

// ---------------------------------------------------------------------------
// Тесты
// ---------------------------------------------------------------------------

class MediaDownloaderTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/mpc_test_dl_' . uniqid();
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (glob($dir . '/*') as $entry) {
            is_dir($entry) ? $this->removeDir($entry) : unlink($entry);
        }
        rmdir($dir);
    }

    private function makeDownloader(array $extraProps = []): TestableMediaDownloader
    {
        $modx = new ModxStub();

        $props = array_merge([
            'downloadExtensions' => ['jpg', 'png', 'webp', 'mp4'],
            'mediaPath'          => '', // пусто → каталог в источнике = <type>/[section/]
            'mimeToExt' => [
                'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp',
                'video/mp4' => 'mp4', 'audio/mpeg' => 'mp3',
            ],
        ], $extraProps);

        return new TestableMediaDownloader($modx, $props, $this->tmpDir);
    }

    // -----------------------------------------------------------------------
    // checkDownloadExtension — pure, без FS/HTTP
    // -----------------------------------------------------------------------

    public function testCheckDownloadExtensionReturnsExtensionForAllowed(): void
    {
        $d = $this->makeDownloader(['downloadExtensions' => ['jpg', 'png', 'webp']]);
        $this->assertEquals('jpg', $d->checkDownloadExtension('https://example.com/photo.jpg'));
    }

    public function testCheckDownloadExtensionReturnsEmptyForDisallowed(): void
    {
        $d = $this->makeDownloader(['downloadExtensions' => ['jpg', 'png']]);
        $this->assertEquals('', $d->checkDownloadExtension('https://example.com/script.php'));
    }

    public function testCheckDownloadExtensionReturnsEmptyForNoExtension(): void
    {
        $d = $this->makeDownloader(['downloadExtensions' => ['jpg', 'png']]);
        $this->assertEquals('', $d->checkDownloadExtension('https://example.com/noextension'));
    }

    public function testCheckDownloadExtensionCaseInsensitive(): void
    {
        $d = $this->makeDownloader(['downloadExtensions' => ['jpg']]);
        $this->assertEquals('jpg', $d->checkDownloadExtension('https://example.com/photo.JPG'));
    }

    // -----------------------------------------------------------------------
    // sanitizeFileName
    // -----------------------------------------------------------------------

    public function testSanitizeFileNameRemovesSpecialChars(): void
    {
        $d = $this->makeDownloader();
        $this->assertEquals('hello-world', $d->sanitizeFileName('hello world!'));
    }

    public function testSanitizeFileNameCollapsesMultipleDashes(): void
    {
        $d = $this->makeDownloader();
        $this->assertEquals('a-b', $d->sanitizeFileName('a---b'));
    }

    public function testSanitizeFileNameHandlesCyrillic(): void
    {
        $d = $this->makeDownloader();
        $result = $d->sanitizeFileName('фото');
        $this->assertMatchesRegularExpression('/^[a-z0-9_\-]+$/', $result);
    }

    public function testSanitizeFileNameTrimsEdgeDashes(): void
    {
        $d = $this->makeDownloader();
        $this->assertEquals('file', $d->sanitizeFileName('--file--'));
    }

    // -----------------------------------------------------------------------
    // detectExtensionByContentType
    // -----------------------------------------------------------------------

    public function testDetectExtensionByContentTypeReturnsJpgForImageJpeg(): void
    {
        $d = $this->makeDownloader();
        $d->fakeContentType = 'image/jpeg';
        $this->assertEquals('jpg', $d->detectExtensionByContentType('https://example.com/download/123'));
    }

    public function testDetectExtensionByContentTypeReturnsMp4ForVideoMp4(): void
    {
        $d = $this->makeDownloader();
        $d->fakeContentType = 'video/mp4';
        $this->assertEquals('mp4', $d->detectExtensionByContentType('https://example.com/download/456'));
    }

    public function testDetectExtensionByContentTypeReturnsEmptyForUnknownMime(): void
    {
        $d = $this->makeDownloader();
        $d->fakeContentType = 'application/octet-stream';
        $this->assertEquals('', $d->detectExtensionByContentType('https://example.com/download/789'));
    }

    public function testDetectExtensionByContentTypeReturnsEmptyWhenNoResponse(): void
    {
        $d = $this->makeDownloader();
        $d->fakeContentType = '';
        $this->assertEquals('', $d->detectExtensionByContentType('https://example.com/download/000'));
    }

    public function testDetectExtensionByContentTypeStripsCharset(): void
    {
        $d = $this->makeDownloader();
        $d->fakeContentType = 'image/png; charset=utf-8';
        $this->assertEquals('png', $d->detectExtensionByContentType('https://example.com/download/111'));
    }

    public function testDetectExtensionByContentTypeRespectsAllowedExtensions(): void
    {
        $d = $this->makeDownloader(['downloadExtensions' => ['jpg']]);
        $d->fakeContentType = 'image/png';
        $this->assertEquals('', $d->detectExtensionByContentType('https://example.com/download/222'));
    }

    // -----------------------------------------------------------------------
    // downloadFile() — фоллбэк на Content-Type / ранние возвраты
    // -----------------------------------------------------------------------

    public function testDownloadFileFallsBackToContentType(): void
    {
        $d = $this->makeDownloader();
        $d->fakeContentType = 'image/jpeg';
        $result = $d->downloadFile('https://example.com/download/12345/', 'images');

        $this->assertStringStartsWith('/images/', $result);
        $this->assertStringEndsWith('.jpg', $result);
        $this->assertCount(1, $d->fakeSource->created);
    }

    public function testDownloadFileReturnsOriginalWhenBothDetectionsFail(): void
    {
        $d = $this->makeDownloader();
        $d->fakeContentType = '';
        $result = $d->downloadFile('https://example.com/download/no-ext/', 'images');

        $this->assertEquals('https://example.com/download/no-ext/', $result);
        $this->assertEmpty($d->fakeSource->created);
    }

    public function testDownloadFileReturnsOriginalWhenNoSource(): void
    {
        $d = $this->makeDownloader();
        $d->noSource = true;
        $result = $d->downloadFile('https://example.com/photo.jpg', 'images');

        $this->assertEquals('https://example.com/photo.jpg', $result);
        $this->assertEmpty($d->fakeSource->created);
    }

    public function testDownloadFileReturnsOriginalForDisallowedExtension(): void
    {
        $d = $this->makeDownloader(['downloadExtensions' => ['jpg']]);
        $result = $d->downloadFile('https://example.com/photo.gif', 'images');

        $this->assertEquals('https://example.com/photo.gif', $result);
        $this->assertEmpty($d->fakeSource->created);
    }

    // -----------------------------------------------------------------------
    // downloadFile() — успешная загрузка в источник
    // -----------------------------------------------------------------------

    public function testDownloadFileWritesToSourceWithCorrectPath(): void
    {
        $d = $this->makeDownloader();
        $d->downloadFile('https://example.com/hero.jpg', 'images');

        $this->assertCount(1, $d->fakeSource->created);
        $this->assertStringStartsWith('images/', $d->fakeSource->created[0]['dir']);
        $this->assertStringEndsWith('.jpg', $d->fakeSource->created[0]['name']);
    }

    public function testDownloadFileReturnsObjectUrlOnSuccess(): void
    {
        $d = $this->makeDownloader();
        $result = $d->downloadFile('https://example.com/hero.jpg', 'images');

        $this->assertStringStartsWith('/images/', $result);
        $this->assertStringEndsWith('.jpg', $result);
    }

    public function testDownloadFileReturnsOriginalUrlOnFetchFailure(): void
    {
        $d = $this->makeDownloader();
        $d->httpContent = null; // имитируем ошибку curl

        $result = $d->downloadFile('https://example.com/hero.jpg', 'images');
        $this->assertEquals('https://example.com/hero.jpg', $result);
        $this->assertEmpty($d->fakeSource->created);
    }

    public function testDownloadFileSavesContentToSource(): void
    {
        $d = $this->makeDownloader();
        $url = $d->downloadFile('https://example.com/banner.png', 'images');

        $full = $d->baseDir . $url; // url = /images/..., baseDir без слеша
        $this->assertFileExists($full);
        $this->assertEquals('fake-image-content', file_get_contents($full));
    }

    // -----------------------------------------------------------------------
    // downloadFile() — построение пути (секция, язык)
    // -----------------------------------------------------------------------

    public function testDownloadFileIncludesSectionNameInPath(): void
    {
        $d = $this->makeDownloader();
        $d->setCurrentSectionName('hero');
        $d->downloadFile('https://example.com/photo.jpg', 'images');

        $this->assertStringStartsWith('images/hero/', $d->fakeSource->created[0]['dir']);
    }

    public function testDownloadFileWithoutSectionUsesTypeDir(): void
    {
        $d = $this->makeDownloader();
        $d->downloadFile('https://example.com/photo.jpg', 'images');

        $this->assertEquals('images/', $d->fakeSource->created[0]['dir']);
    }

    public function testDownloadFileUsesCustomTypeDirFromDownloadPaths(): void
    {
        // mpc_download_paths задаёт подпапку типа относительно mediaPath
        $d = $this->makeDownloader([
            'mediaPath'     => 'assets/media',
            'downloadPaths' => ['images' => 'gallery/img', 'videos' => ''],
        ]);
        $d->downloadFile('https://example.com/photo.jpg', 'images');

        $this->assertEquals('assets/media/gallery/img/', $d->fakeSource->created[0]['dir']);
    }

    public function testDownloadFileFallsBackToTypeNameWhenDownloadPathEmpty(): void
    {
        // пустое значение типа в mpc_download_paths → имя типа
        $d = $this->makeDownloader([
            'mediaPath'     => 'assets/media',
            'downloadPaths' => ['images' => '', 'videos' => 'clips'],
        ]);
        $d->downloadFile('https://example.com/photo.jpg', 'images');

        $this->assertEquals('assets/media/images/', $d->fakeSource->created[0]['dir']);
    }

    public function testDownloadFileAddsLanguagePrefixToFilename(): void
    {
        $d = $this->makeDownloader();
        $d->downloadFile('https://example.com/hero.jpg', 'images', 'en');

        $this->assertStringStartsWith('en-', $d->fakeSource->created[0]['name']);
    }

    public function testDownloadFileNoLanguagePrefixWhenEmpty(): void
    {
        $d = $this->makeDownloader();
        $d->downloadFile('https://example.com/hero.jpg', 'images', '');

        $this->assertEquals('hero.jpg', $d->fakeSource->created[0]['name']);
    }

    // -----------------------------------------------------------------------
    // downloadFile() — повторный вызов не качает снова (файл уже в источнике)
    // -----------------------------------------------------------------------

    public function testDownloadFileSkipsFetchIfFileExists(): void
    {
        $d = $this->makeDownloader();

        $url1 = $d->downloadFile('https://example.com/photo.jpg', 'images');
        $this->assertEquals(1, $d->fetchCalls);

        // второй вызов с тем же URL — файл уже на диске источника
        $url2 = $d->downloadFile('https://example.com/photo.jpg', 'images');
        $this->assertEquals(1, $d->fetchCalls, 'повторно fetch() не вызывается');
        $this->assertEquals($url1, $url2);
    }

    // -----------------------------------------------------------------------
    // downloadImage / downloadVideo / downloadAudio — делегируют downloadFile
    // -----------------------------------------------------------------------

    public function testDownloadImageDelegatesToImages(): void
    {
        $d = $this->makeDownloader();
        $d->downloadImage('https://example.com/banner.jpg');
        $this->assertStringStartsWith('images/', $d->fakeSource->created[0]['dir']);
    }

    public function testDownloadVideoDelegatesToVideos(): void
    {
        $d = $this->makeDownloader();
        $d->downloadVideo('https://example.com/clip.mp4');
        $this->assertStringStartsWith('videos/', $d->fakeSource->created[0]['dir']);
    }

    public function testDownloadAudioDelegatesToAudios(): void
    {
        $d = $this->makeDownloader(['downloadExtensions' => ['mp3', 'ogg']]);
        $d->downloadAudio('https://example.com/track.mp3');
        $this->assertStringStartsWith('audios/', $d->fakeSource->created[0]['dir']);
    }
}
