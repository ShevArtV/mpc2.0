<?php

namespace MpcTests\Unit;

use MpcServices\Handlers\CacheInvalidator;
use MpcTests\Stubs\ModxObjectStub;
use MpcTests\Stubs\ModxStub;
use PHPUnit\Framework\TestCase;

/**
 * Юнит инвалидации parsed-кэша (вынос из FieldWriter): level=resource сносит
 * только файл ресурса; global ИЛИ донор (parent=sbp) — весь parsed.
 * cacheManager->refresh — no-op стаба (проверяем именно parsed-логику на tmp).
 */
class CacheInvalidatorTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/mpc_ci_' . uniqid();
        mkdir($this->dir, 0777, true);
        foreach (['1', '2', '3'] as $id) {
            file_put_contents($this->dir . '/' . $id . '.tpl', 'x');
        }
    }

    protected function tearDown(): void
    {
        foreach ((array)@scandir($this->dir) as $f) {
            if ($f !== '.' && $f !== '..' && is_file($this->dir . '/' . $f)) {
                @unlink($this->dir . '/' . $f);
            }
        }
        @rmdir($this->dir);
    }

    private function modx(): ModxStub
    {
        return new ModxStub(null, [
            'pdotools_elements_path'    => $this->dir . '/',
            'mpc_path_to_dist'          => '',
            'mpc_static_block_page_id'  => 1,
            'mpc_tpl_file_extension'    => '.tpl',
        ]);
    }

    private function remaining(): array
    {
        $out = [];
        foreach ((array)scandir($this->dir) as $f) {
            if (is_file($this->dir . '/' . $f)) { $out[] = $f; }
        }
        sort($out);
        return $out;
    }

    public function testResourceLevelDeletesOnlyOwnFile(): void
    {
        $res = new ModxObjectStub('modResource', ['id' => 2, 'parent' => 5, 'context_key' => 'web']);
        (new CacheInvalidator($this->modx()))->invalidate($res, 'resource');
        $this->assertSame(['1.tpl', '3.tpl'], $this->remaining());
    }

    public function testGlobalLevelDeletesAll(): void
    {
        $res = new ModxObjectStub('modResource', ['id' => 2, 'parent' => 5, 'context_key' => 'web']);
        (new CacheInvalidator($this->modx()))->invalidate($res, 'global');
        $this->assertSame([], $this->remaining());
    }

    public function testDonorResourceDeletesAllEvenOnResourceLevel(): void
    {
        // parent == staticBlocksPage(1) → донор → сносим весь parsed (влияет на всех наследников)
        $res = new ModxObjectStub('modResource', ['id' => 2, 'parent' => 1, 'context_key' => 'web']);
        (new CacheInvalidator($this->modx()))->invalidate($res, 'resource');
        $this->assertSame([], $this->remaining());
    }

    public function testMissingParsedDirIsNoop(): void
    {
        $modx = new ModxStub(null, ['pdotools_elements_path' => '/no_such_dir_xyz/', 'mpc_path_to_dist' => '']);
        $res = new ModxObjectStub('modResource', ['id' => 2, 'parent' => 5, 'context_key' => 'web']);
        (new CacheInvalidator($modx))->invalidate($res, 'resource'); // не должно падать
        $this->assertSame(['1.tpl', '2.tpl', '3.tpl'], $this->remaining());
    }
}
