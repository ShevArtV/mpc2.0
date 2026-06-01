<?php

namespace MpcTests\Unit\Mpcve;

use PHPUnit\Framework\TestCase;

// Хендлер живёт в соседнем компоненте mpcVisualEditor (другой namespace),
// своего тест-харнесса там нет — подключаем класс напрямую.
require_once dirname(__DIR__, 5)
    . '/mpcvisualeditor/services/custom/Handlers/ImageUploadHandler.php';

/**
 * Тест «чистого» ядра ImageUploadHandler (processUpload, sanitizeFileName) без
 * реального modX/$_FILES: подменяем lexicon() и подсовываем fake media source.
 */
class ImageUploadHandlerTest extends TestCase
{
    /** Хендлер с no-op конструктором и lexicon()→ключ (без modX). */
    private function makeHandler(): \MpcVEServices\Handlers\ImageUploadHandler
    {
        return new class extends \MpcVEServices\Handlers\ImageUploadHandler {
            public function __construct() {}
            protected function lexicon(string $key): string { return $key; }
        };
    }

    /** Fake media source, пишущий во временную папку (как в MediaDownloaderTest). */
    private function makeSource(string $base): object
    {
        return new class($base) {
            public string $base;
            public array $created = [];
            public array $containers = [];
            public function __construct(string $b) { $this->base = rtrim($b, '/'); }
            public function initialize(): void {}
            public function createContainer($name, $parent)
            {
                $this->containers[] = $parent . $name;
                @mkdir($this->base . '/' . trim(($parent === '/' ? '' : $parent) . $name, '/'), 0777, true);
                return true;
            }
            public function createObject($dir, $name, $content)
            {
                $this->created[] = ['dir' => $dir, 'name' => $name];
                $full = $this->base . '/' . ltrim($dir . $name, '/');
                @mkdir(dirname($full), 0777, true);
                return file_put_contents($full, $content) !== false ? ($dir . $name) : false;
            }
            public function getObjectUrl($path) { return '/' . ltrim((string)$path, '/'); }
            public function getErrors() { return []; }
        };
    }

    public function testSanitizeFileName(): void
    {
        $h = $this->makeHandler();
        $this->assertSame('my-photo_2', $h->sanitizeFileName('My Photo_2'));
        $this->assertSame('a-b', $h->sanitizeFileName('A!!!B'));
        $this->assertSame('', $h->sanitizeFileName('---'));
    }

    public function testProcessUploadWritesAndReturnsUrl(): void
    {
        $dirTmp  = sys_get_temp_dir() . '/mpcve_upl_' . getmypid();
        @mkdir($dirTmp, 0777, true);
        $source  = $this->makeSource($dirTmp);
        $content = 'PNGDATA';
        $hash    = substr(sha1($content), 0, 8);

        $res = $this->makeHandler()->processUpload(
            $source,
            'assets/media/images/',
            'My Photo.png',
            'png',
            $content
        );

        $this->assertTrue($res['success']);
        $expectedName = 'my-photo-' . $hash . '.png';
        $this->assertSame('/assets/media/images/' . $expectedName, $res['data']['url']);
        // файл реально записан в источник
        $this->assertSame($expectedName, $source->created[0]['name']);
        $this->assertFileExists($dirTmp . '/assets/media/images/' . $expectedName);

        // cleanup
        @unlink($dirTmp . '/assets/media/images/' . $expectedName);
        @system('rm -rf ' . escapeshellarg($dirTmp));
    }

    public function testProcessUploadDeterministicName(): void
    {
        $source = $this->makeSource(sys_get_temp_dir() . '/mpcve_upl2_' . getmypid());
        $r1 = $this->makeHandler()->processUpload($source, 'd/', 'x.jpg', 'jpg', 'AAA');
        $r2 = $this->makeHandler()->processUpload($source, 'd/', 'x.jpg', 'jpg', 'AAA');
        // одинаковый контент → одинаковое имя (дедуп)
        $this->assertSame($r1['data']['url'], $r2['data']['url']);
        @system('rm -rf ' . escapeshellarg(sys_get_temp_dir() . '/mpcve_upl2_' . getmypid()));
    }

    public function testProcessUploadFailsWhenCreateObjectFails(): void
    {
        $failSource = new class {
            public function createContainer($n, $p) { return true; }
            public function createObject($d, $n, $c) { return false; }
            public function getObjectUrl($p) { return '/' . ltrim((string)$p, '/'); }
            public function getErrors() { return ['disk full']; }
        };
        $res = $this->makeHandler()->processUpload($failSource, 'd/', 'x.png', 'png', 'DATA');
        $this->assertFalse($res['success']);
    }
}
