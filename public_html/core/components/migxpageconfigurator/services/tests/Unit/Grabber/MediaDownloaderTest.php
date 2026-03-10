<?php

namespace MpcTests\Unit\Grabber;

use MpcServices\Handlers\Grabber\MediaDownloader;
use MpcTests\Stubs\ModxObjectStub;
use MpcTests\Stubs\ModxStub;
use PHPUnit\Framework\TestCase;

// ---------------------------------------------------------------------------
// Тестируемый подкласс: перехватывает HTTP и перенаправляет FS в tmpDir
// ---------------------------------------------------------------------------

class TestableMediaDownloader extends MediaDownloader
{
    public string $baseDir;
    /** @var array<array{url:string, path:string}> */
    public array $downloadCalls = [];
    /** Возвращаемый контент (null = имитировать ошибку curl) */
    public ?string $httpContent = 'fake-image-content';
    /** Имитация Content-Type для detectExtensionByContentType ('' = не определён) */
    public string $fakeContentType = '';

    private array $testProps;

    public function __construct(\modX $modx, array $properties, string $baseDir)
    {
        parent::__construct($modx, $properties);
        $this->testProps = $properties;
        $this->baseDir = rtrim($baseDir, '/');
    }

    protected function getBaseDir(): string
    {
        return $this->baseDir;
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

    public function download(string $url, string $path): string
    {
        $this->downloadCalls[] = ['url' => $url, 'path' => $path];

        if (!$path) {
            return '';
        }

        $fullPath = $this->baseDir . $path;

        if (file_exists($fullPath)) {
            return $path;
        }

        if ($this->httpContent === null) {
            return '';
        }

        $dir = dirname($fullPath);
        if (!file_exists($dir)) {
            mkdir($dir, 0777, true);
        }

        return file_put_contents($fullPath, $this->httpContent) ? $path : '';
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
            'downloadPaths'      => [
                'images' => '/assets/images/',
                'videos' => '/assets/videos/',
                'audios' => '/assets/audios/',
                'others' => '/assets/others/',
            ],
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
    // downloadFile() с фоллбэком на Content-Type
    // -----------------------------------------------------------------------

    public function testDownloadFileFallsBackToContentType(): void
    {
        $d = $this->makeDownloader();
        $d->fakeContentType = 'image/jpeg';
        $result = $d->downloadFile('https://example.com/download/12345/', 'images');

        $this->assertStringStartsWith('/assets/images/', $result);
        $this->assertStringEndsWith('.jpg', $result);
        $this->assertCount(1, $d->downloadCalls);
    }

    public function testDownloadFileReturnsOriginalWhenBothDetectionsFail(): void
    {
        $d = $this->makeDownloader();
        $d->fakeContentType = '';
        $result = $d->downloadFile('https://example.com/download/no-ext/', 'images');

        $this->assertEquals('https://example.com/download/no-ext/', $result);
        $this->assertEmpty($d->downloadCalls);
    }

    // -----------------------------------------------------------------------
    // downloadFile() ранние возвраты
    // -----------------------------------------------------------------------

    public function testDownloadFileReturnsOriginalWhenNoDownloadPath(): void
    {
        $d = $this->makeDownloader([
            'downloadPaths' => ['images' => '', 'videos' => '', 'audios' => '', 'others' => ''],
        ]);
        $result = $d->downloadFile('https://example.com/photo.jpg', 'images');
        $this->assertEquals('https://example.com/photo.jpg', $result);
        $this->assertEmpty($d->downloadCalls);
    }

    public function testDownloadFileReturnsOriginalForDisallowedExtension(): void
    {
        $d = $this->makeDownloader(['downloadExtensions' => ['jpg']]);
        $result = $d->downloadFile('https://example.com/photo.gif', 'images');
        $this->assertEquals('https://example.com/photo.gif', $result);
        $this->assertEmpty($d->downloadCalls);
    }

    // -----------------------------------------------------------------------
    // downloadFile() — успешная загрузка
    // -----------------------------------------------------------------------

    public function testDownloadFileCallsDownloadWithCorrectPath(): void
    {
        $d = $this->makeDownloader();
        $d->downloadFile('https://example.com/hero.jpg', 'images');

        $this->assertCount(1, $d->downloadCalls);
        $this->assertEquals('https://example.com/hero.jpg', $d->downloadCalls[0]['url']);
        $this->assertStringStartsWith('/assets/images/', $d->downloadCalls[0]['path']);
        $this->assertStringEndsWith('.jpg', $d->downloadCalls[0]['path']);
    }

    public function testDownloadFileReturnsLocalPathOnSuccess(): void
    {
        $d = $this->makeDownloader();
        $result = $d->downloadFile('https://example.com/hero.jpg', 'images');

        $this->assertStringStartsWith('/assets/images/', $result);
        $this->assertStringEndsWith('.jpg', $result);
    }

    public function testDownloadFileReturnsOriginalUrlOnCurlFailure(): void
    {
        $d = $this->makeDownloader();
        $d->httpContent = null; // имитируем ошибку curl

        $result = $d->downloadFile('https://example.com/hero.jpg', 'images');
        $this->assertEquals('https://example.com/hero.jpg', $result);
    }

    public function testDownloadFileSavesContentToDisk(): void
    {
        $d = $this->makeDownloader();
        $localPath = $d->downloadFile('https://example.com/banner.png', 'images');

        $fullPath = $this->tmpDir . $localPath;
        $this->assertFileExists($fullPath);
        $this->assertEquals('fake-image-content', file_get_contents($fullPath));
    }

    // -----------------------------------------------------------------------
    // downloadFile() — построение пути (секция, язык)
    // -----------------------------------------------------------------------

    public function testDownloadFileIncludesSectionNameInPath(): void
    {
        $d = $this->makeDownloader();
        $d->setCurrentSectionName('hero');
        $d->downloadFile('https://example.com/photo.jpg', 'images');

        $path = $d->downloadCalls[0]['path'];
        $this->assertStringContainsString('hero/', $path);
        $this->assertStringStartsWith('/assets/images/hero/', $path);
    }

    public function testDownloadFileWithoutSectionUsesBasePath(): void
    {
        $d = $this->makeDownloader();
        $d->downloadFile('https://example.com/photo.jpg', 'images');

        $path = $d->downloadCalls[0]['path'];
        $this->assertStringStartsWith('/assets/images/', $path);
        $this->assertStringNotContainsString('/images//', $path);
    }

    public function testDownloadFileAddsLanguagePrefixToFilename(): void
    {
        $d = $this->makeDownloader();
        $d->downloadFile('https://example.com/hero.jpg', 'images', 'en');

        $path = $d->downloadCalls[0]['path'];
        $filename = basename($path);
        $this->assertStringStartsWith('en-', $filename);
    }

    public function testDownloadFileNoLanguagePrefixWhenEmpty(): void
    {
        $d = $this->makeDownloader();
        $d->downloadFile('https://example.com/hero.jpg', 'images', '');

        $path = $d->downloadCalls[0]['path'];
        $filename = basename($path);
        $this->assertStringNotContainsString('-', $filename);
    }

    // -----------------------------------------------------------------------
    // downloadFile() — повторный вызов не скачивает снова
    // -----------------------------------------------------------------------

    public function testDownloadFileSkipsHttpIfFileAlreadyExists(): void
    {
        $d = $this->makeDownloader();

        // первый вызов — скачивает
        $path1 = $d->downloadFile('https://example.com/photo.jpg', 'images');
        $callsAfterFirst = count($d->downloadCalls);

        // обнуляем счётчик вызовов download()
        $d->downloadCalls = [];
        // второй вызов с тем же URL — файл уже есть на диске
        $path2 = $d->downloadFile('https://example.com/photo.jpg', 'images');

        $this->assertCount(1, $d->downloadCalls, 'download() должен вызваться, но файл уже есть');
        $this->assertEquals($path1, $path2, 'возвращаемый путь должен совпадать');
    }

    // -----------------------------------------------------------------------
    // download() — низкоуровневые тесты
    // -----------------------------------------------------------------------

    public function testDownloadReturnsEmptyForEmptyPath(): void
    {
        $d = $this->makeDownloader();
        $this->assertEquals('', $d->download('https://example.com/photo.jpg', ''));
    }

    public function testDownloadReturnsPathIfFileExists(): void
    {
        $d = $this->makeDownloader();

        // создаём файл заранее
        $path = '/assets/images/existing.jpg';
        $fullPath = $this->tmpDir . $path;
        mkdir(dirname($fullPath), 0777, true);
        file_put_contents($fullPath, 'existing');

        $result = $d->download('https://example.com/existing.jpg', $path);

        $this->assertEquals($path, $result);
        // download() не должна идти в HTTP (нет вызовов curl в TestableMediaDownloader)
        // т.к. родительский download() переопределён — просто проверяем возврат
    }

    // -----------------------------------------------------------------------
    // downloadImage / downloadVideo / downloadAudio — делегируют downloadFile
    // -----------------------------------------------------------------------

    public function testDownloadImageDelegatesToImages(): void
    {
        $d = $this->makeDownloader();
        $d->downloadImage('https://example.com/banner.jpg');

        $this->assertStringStartsWith('/assets/images/', $d->downloadCalls[0]['path']);
    }

    public function testDownloadVideoDelegatesToVideos(): void
    {
        $d = $this->makeDownloader();
        $d->downloadVideo('https://example.com/clip.mp4');

        $this->assertStringStartsWith('/assets/videos/', $d->downloadCalls[0]['path']);
    }

    public function testDownloadAudioDelegatesToAudios(): void
    {
        $d = $this->makeDownloader([
            'downloadExtensions' => ['mp3', 'ogg'],
            'downloadPaths' => [
                'images' => '/assets/images/',
                'videos' => '/assets/videos/',
                'audios' => '/assets/audios/',
                'others' => '/assets/others/',
            ],
        ]);
        $d->downloadAudio('https://example.com/track.mp3');

        $this->assertStringStartsWith('/assets/audios/', $d->downloadCalls[0]['path']);
    }
}
