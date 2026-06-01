<?php

namespace MpcTests\Unit;

use MpcServices\Handlers\LexiconWriter;
use MpcTests\Stubs\ModxStub;
use PHPUnit\Framework\TestCase;

/**
 * Тест точечной записи лексикона (has/set) на временных файлах.
 * lexiconFilenameField=id → identifier(rid)=rid (без запроса к БД).
 */
class LexiconWriterTest extends TestCase
{
    private string $core;

    protected function setUp(): void
    {
        $this->core = sys_get_temp_dir() . '/mpc_lex_' . getmypid() . '/';
        @mkdir($this->core . 'lex/ru/', 0777, true);
    }

    protected function tearDown(): void
    {
        @system('rm -rf ' . escapeshellarg(rtrim($this->core, '/')));
    }

    private function writer(): LexiconWriter
    {
        return new LexiconWriter(new ModxStub(), [
            'culture'              => 'ru',
            'corePath'             => $this->core,
            'lexiconPath'          => 'lex',
            'lexiconFilenameField' => 'id',
            'allowedTags'          => [''],
            'allowModxTags'        => false,
        ]);
    }

    private function seed(string $content): void
    {
        file_put_contents($this->core . 'lex/ru/1.inc.php', "<?php\n" . $content);
    }

    private function load(): array
    {
        $_lang = [];
        include $this->core . 'lex/ru/1.inc.php';
        return $_lang;
    }

    public function testHasDetectsKey(): void
    {
        $this->seed("\$_lang['hero_title'] = 'Заголовок';\n");
        $w = $this->writer();
        $this->assertTrue($w->has('1', 'hero_title'));
        $this->assertFalse($w->has('1', 'nope'));
        $this->assertFalse($w->has('1', ''));
        $this->assertFalse($w->has('', 'hero_title'));
    }

    public function testSetUpdatesKeyAndPreservesOthers(): void
    {
        $this->seed("\$_lang['hero_title'] = 'Старый';\n\$_lang['hero_sub'] = 'Подзаголовок';\n");
        $w = $this->writer();

        $this->assertTrue($w->set('1', 'hero_title', 'Новый заголовок'));

        $lang = $this->load();
        $this->assertSame('Новый заголовок', $lang['hero_title']); // обновлён
        $this->assertSame('Подзаголовок', $lang['hero_sub']);      // сохранён
    }

    public function testSetEscapesApostrophe(): void
    {
        $this->seed("\$_lang['k'] = 'v';\n");
        $w = $this->writer();
        $w->set('1', 'k', "Д'Артаньян");
        // санитайз LexiconManager: ' → &apos; (валидный одинарно-кавыченный PHP)
        $this->assertSame("Д&apos;Артаньян", $this->load()['k']);
    }

    public function testSetCreatesKeyWhenAbsent(): void
    {
        $this->seed("\$_lang['a'] = '1';\n");
        $w = $this->writer();
        $w->set('1', 'b', 'two');
        $lang = $this->load();
        $this->assertSame('1', $lang['a']);
        $this->assertSame('two', $lang['b']);
    }
}
