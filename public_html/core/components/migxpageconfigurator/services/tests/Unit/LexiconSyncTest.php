<?php

namespace MpcTests\Unit;

use MpcServices\Handlers\LexiconSync;
use MpcServices\Handlers\PendingTranslations;
use PHPUnit\Framework\TestCase;

/**
 * Unit-тесты общего сервиса синхронизации лексиконов (нарезка + редактор).
 */
class LexiconSyncTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/mpc_lexsync_' . uniqid() . '/';
        mkdir($this->base . 'ru', 0777, true);
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->base, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->base);
    }

    private function readLex(string $lang, string $id): array
    {
        $_lang = [];
        $file = $this->base . $lang . '/' . $id . '.inc.php';
        if (is_file($file)) {
            include $file;
        }
        return is_array($_lang) ? $_lang : [];
    }

    private function sync(): LexiconSync
    {
        return new LexiconSync($this->base, 'ru', ['ru', 'en']);
    }

    /** syncResource: ключи дефолта раскладываются в другие языки + pending. */
    public function testSyncResourcePropagatesAndMarksPending(): void
    {
        $this->sync()->syncResource('5', ['howto_title' => 'Заголовок', 'howto_text' => 'Текст']);

        $en = $this->readLex('en', '5');
        $this->assertSame('Заголовок', $en['howto_title']); // плейсхолдер = значение дефолта
        $this->assertSame('Текст', $en['howto_text']);

        $pending = (new PendingTranslations($this->base))->load('en', '5');
        $this->assertContains('howto_title', $pending); // новые ключи → непереведённые
        $this->assertContains('howto_text', $pending);
    }

    /** syncResource: уже переведённый ключ сохраняется и уходит из pending. */
    public function testSyncResourceKeepsExistingTranslation(): void
    {
        // в en уже есть перевод одного ключа
        mkdir($this->base . 'en', 0777, true);
        file_put_contents(
            $this->base . 'en/5.inc.php',
            "<?php\n\$_lang['howto_title'] = 'Title';\n"
        );

        $this->sync()->syncResource('5', ['howto_title' => 'Заголовок', 'howto_text' => 'Текст']);

        $en = $this->readLex('en', '5');
        $this->assertSame('Title', $en['howto_title']);  // существующий перевод сохранён
        $this->assertSame('Текст', $en['howto_text']);   // новый — плейсхолдер

        $pending = (new PendingTranslations($this->base))->load('en', '5');
        $this->assertNotContains('howto_title', $pending); // переведён — не в pending
        $this->assertContains('howto_text', $pending);     // новый — в pending
    }

    /** syncKey на дефолтном языке: новый ключ → плейсхолдер в др. язык + pending. */
    public function testSyncKeyNewOriginalPropagates(): void
    {
        $this->sync()->syncKey('5', 'howto_title', 'Заголовок', 'ru');

        $this->assertSame('Заголовок', $this->readLex('en', '5')['howto_title']);
        $this->assertContains('howto_title', (new PendingTranslations($this->base))->load('en', '5'));
    }

    /** syncKey на не-дефолтном языке (ввод перевода): ключ снимается с pending. */
    public function testSyncKeyTranslationRemovesPending(): void
    {
        $pending = new PendingTranslations($this->base);
        $pending->save('en', '5', ['howto_title', 'howto_text']);

        $this->sync()->syncKey('5', 'howto_title', 'Title', 'en');

        $left = $pending->load('en', '5');
        $this->assertNotContains('howto_title', $left); // переведён — снят
        $this->assertContains('howto_text', $left);     // остальные остаются
    }
}
