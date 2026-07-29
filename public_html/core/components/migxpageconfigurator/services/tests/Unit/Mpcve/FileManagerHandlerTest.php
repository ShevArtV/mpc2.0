<?php

namespace MpcTests\Unit\Mpcve;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 5)
    . '/mpcvisualeditor/services/custom/Handlers/FileManagerHandler.php';

/**
 * Юнит «чистой» логики безопасности FileManagerHandler без modX/source.
 *
 * Блок-лист и политика имён здесь больше НЕ живут: они переехали в единую точку
 * mpc (FileName) и покрыты FileNameTest/MediaLibraryTest. Тут остаётся только
 * то, что принадлежит самому хендлеру, — cleanPath (anti-traversal, S11) и
 * accept. Приватные методы через рефлексию; конструктор обходим сабклассом.
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

    /** @dataProvider acceptCases */
    public function testAcceptExt(string $ext, string $accept, bool $ok): void
    {
        $this->assertSame($ok, $this->call('acceptExt', $ext, $accept), "$ext/$accept");
    }

    public function acceptCases(): array
    {
        return [
            'image ok'    => ['jpg', 'image', true],
            'image no'    => ['mp4', 'image', false],
            'media video' => ['mp4', 'media', true],
            'media pdf'   => ['pdf', 'media', false],
            'any pdf'     => ['pdf', 'any', true],
        ];
    }
}
