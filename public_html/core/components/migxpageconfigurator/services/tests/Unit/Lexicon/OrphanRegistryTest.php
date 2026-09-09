<?php

namespace MpcTests\Unit\Lexicon;

use MpcServices\Handlers\Lexicon\OrphanRegistry;
use PHPUnit\Framework\TestCase;

/** Реестр кандидатов на удаление: учёт, возраст, снятие с учёта. */
class OrphanRegistryTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/mpc_orph_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '/';
        @mkdir($this->base . 'ru/', 0777, true);
    }

    protected function tearDown(): void
    {
        @system('rm -rf ' . escapeshellarg(rtrim($this->base, '/')));
    }

    public function testRecordKeepsFirstSeenDate(): void
    {
        $r = new OrphanRegistry($this->base);
        $r->record('ru', '10', ['a' => 'значение'], 'sec_');
        $first = $r->load('ru', '10')['a']['seen_at'];

        $r->record('ru', '10', ['a' => 'другое значение']);

        $entry = $r->load('ru', '10')['a'];
        $this->assertSame($first, $entry['seen_at'], 'возраст кандидата не должен обнуляться');
        $this->assertSame('другое значение', $entry['value']);
        $this->assertSame('sec_', $entry['prefix']);
    }

    public function testForgetRemovesReturnedKey(): void
    {
        $r = new OrphanRegistry($this->base);
        $r->record('ru', '10', ['a' => 'a', 'b' => 'b']);
        $r->forget('ru', '10', ['a']);

        $this->assertSame(['b'], array_keys($r->load('ru', '10')));
    }

    public function testAllFiltersByAge(): void
    {
        $r = new OrphanRegistry($this->base);
        $r->record('ru', '10', ['fresh' => 'f']);
        $entries = $r->load('ru', '10');
        $entries['old'] = ['value' => 'o', 'seen_at' => date('c', time() - 40 * 86400), 'prefix' => ''];
        $r->save('ru', '10', $entries);

        $this->assertSame(['fresh', 'old'], array_keys($r->all('ru')['10']));
        $this->assertSame(['old'], array_keys($r->all('ru', 30)['10']));
    }

    public function testMissingRegistryIsEmptyNotAnError(): void
    {
        $r = new OrphanRegistry($this->base);
        $this->assertSame([], $r->load('ru', 'нет'));
        $this->assertSame([], $r->all('ru'));
    }
}
