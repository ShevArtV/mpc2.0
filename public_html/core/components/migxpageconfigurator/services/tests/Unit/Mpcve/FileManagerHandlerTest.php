<?php

namespace MpcTests\Unit\Mpcve;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 5)
    . '/mpcvisualeditor/services/custom/Handlers/FileManagerHandler.php';

/**
 * Юнит «чистой» логики безопасности FileManagerHandler без modX/source:
 * блок-лист исполняемых расширений (вкл. двойное расширение — V2/S2),
 * санитайз имени и cleanPath (anti-traversal — S11). Приватные методы через
 * рефлексию; конструктор обходим анонимным сабклассом.
 */
class FileManagerHandlerTest extends TestCase
{
    private \MpcVEServices\Handlers\FileManagerHandler $h;

    protected function setUp(): void
    {
        $this->h = new class extends \MpcVEServices\Handlers\FileManagerHandler {
            public function __construct() {}
        };
    }

    private function call(string $method, ...$args)
    {
        $m = new \ReflectionMethod(\MpcVEServices\Handlers\FileManagerHandler::class, $method);
        $m->setAccessible(true);
        return $m->invoke($this->h, ...$args);
    }

    /** @dataProvider blockedExtCases */
    public function testIsBlockedExt(string $ext, bool $blocked): void
    {
        $this->assertSame($blocked, $this->call('isBlockedExt', $ext), "ext=$ext");
    }

    public function blockedExtCases(): array
    {
        return [
            ['php', true], ['php5', true], ['phtml', true], ['phar', true],
            ['PHP', true], ['htaccess', true], ['sh', true], ['cgi', true],
            ['jpg', false], ['png', false], ['webp', false], ['mp4', false], ['', false],
        ];
    }

    /** @dataProvider blockedNameCases */
    public function testIsBlockedName(string $name, bool $blocked): void
    {
        $this->assertSame($blocked, $this->call('isBlockedName', $name), "name=$name");
    }

    public function blockedNameCases(): array
    {
        return [
            'plain php'        => ['shell.php', true],
            'double ext'       => ['shell.php.jpg', true],   // V2: pathinfo видит только jpg
            'double ext upper' => ['SHELL.PHP.JPG', true],
            'zip then php'     => ['arch.zip.php', true],
            'htaccess'         => ['.htaccess', true],
            'normal image'    => ['photo.jpg', false],
            'cyrillic image'  => ['картинка.png', false],
            'dotted normal'   => ['my.photo.2024.jpeg', false],
        ];
    }

    /** @dataProvider cleanPathCases */
    public function testCleanPath(string $in, string $out): void
    {
        $this->assertSame($out, $this->call('cleanPath', $in), "in=$in");
    }

    public function cleanPathCases(): array
    {
        return [
            'traversal'      => ['../../etc/passwd', 'etc/passwd'],
            'dot segment'    => ['a/./b', 'a/b'],
            // cleanPath выкидывает traversal-сегмент (anti-escape), не канонизирует:
            // 'a/../b' остаётся в дереве как 'a/b' — наружу не выходит, это и нужно.
            'mid traversal'  => ['a/../b', 'a/b'],
            'backslash'      => ['a\\b', 'a/b'],
            'leading slash'  => ['/foo/bar', 'foo/bar'],
            'dotdot embed'   => ['..foo/bar', 'bar'],          // сегмент с '..' выкидывается
            'clean'          => ['images/2024', 'images/2024'],
            'empty'          => ['', ''],
        ];
    }

    /** @dataProvider sanitizeCases */
    public function testSanitizeFileName(string $in, string $out): void
    {
        $this->assertSame($out, $this->call('sanitizeFileName', $in), "in=$in");
    }

    public function sanitizeCases(): array
    {
        return [
            'strip path'     => ['../../shell.jpg', 'shell.jpg'],
            'leading dot'    => ['.htaccess', 'htaccess'],
            'collapse dots'  => ['a..b.jpg', 'a.b.jpg'],
            'keep cyrillic'  => ['Фото 2024.png', 'Фото 2024.png'],
            'backslash path' => ['c:\\win\\x.jpg', 'x.jpg'],
        ];
    }
}
