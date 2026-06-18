<?php

namespace MpcTests\Unit;

use MpcServices\Handlers\ArbitraryLexiconResolver;
use PHPUnit\Framework\TestCase;

/**
 * Резолв топик-файла произвольного ключа: текущий язык → дефолт (создать в
 * текущем) → не найден. Топик явный (только он) vs скан списка топиков.
 */
class ArbitraryLexiconResolverTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/mpc_alr_' . getmypid() . '/';
        @mkdir($this->base . 'ru/', 0777, true);
        @mkdir($this->base . 'en/', 0777, true);
    }

    protected function tearDown(): void
    {
        @system('rm -rf ' . escapeshellarg(rtrim($this->base, '/')));
    }

    private function seed(string $culture, string $topic, array $entries): void
    {
        $php = "<?php\n";
        foreach ($entries as $k => $v) {
            $php .= '$_lang[' . var_export($k, true) . '] = ' . var_export($v, true) . ";\n";
        }
        file_put_contents($this->base . $culture . '/' . $topic . '.inc.php', $php);
    }

    private function resolver(array $topics = ['blocks', 'promo']): ArbitraryLexiconResolver
    {
        return new ArbitraryLexiconResolver($this->base, 'ru', $topics);
    }

    public function testFoundInCurrentLanguageExplicitTopic(): void
    {
        $this->seed('en', 'blocks', ['promo_title' => 'Hello']);
        $res = $this->resolver()->resolve('blocks', 'promo_title', 'en');
        $this->assertSame(['topic' => 'blocks', 'fromDefault' => false], $res);
    }

    public function testFoundInDefaultCreatesInCurrent(): void
    {
        // ключ есть только в дефолтном (ru), правим en → пишем в blocks текущего языка
        $this->seed('ru', 'blocks', ['promo_title' => 'Привет']);
        $res = $this->resolver()->resolve('blocks', 'promo_title', 'en');
        $this->assertSame(['topic' => 'blocks', 'fromDefault' => true], $res);
    }

    public function testNotFoundAnywhere(): void
    {
        $this->seed('ru', 'blocks', ['other' => 'x']);
        $this->assertNull($this->resolver()->resolve('blocks', 'promo_title', 'en'));
    }

    public function testScanTopicsWhenTopicOmitted(): void
    {
        // ключ во втором топике списка, топик в адресе не задан → скан находит
        $this->seed('en', 'promo', ['promo_title' => 'Hello']);
        $res = $this->resolver(['blocks', 'promo'])->resolve('', 'promo_title', 'en');
        $this->assertSame(['topic' => 'promo', 'fromDefault' => false], $res);
    }

    public function testExplicitTopicDoesNotScanOthers(): void
    {
        // ключ есть в promo, но просим blocks → не найден (скан других топиков нет)
        $this->seed('en', 'promo', ['promo_title' => 'Hello']);
        $this->assertNull($this->resolver()->resolve('blocks', 'promo_title', 'en'));
    }

    public function testCurrentLanguageWinsOverDefault(): void
    {
        $this->seed('ru', 'blocks', ['promo_title' => 'Привет']);
        $this->seed('en', 'blocks', ['promo_title' => 'Hello']);
        $res = $this->resolver()->resolve('blocks', 'promo_title', 'en');
        $this->assertSame(['topic' => 'blocks', 'fromDefault' => false], $res);
    }

    public function testInvalidKeyRejected(): void
    {
        $this->seed('en', 'blocks', ["a'b" => 'x']);
        $this->assertNull($this->resolver()->resolve('blocks', "a'b", 'en'));
    }

    public function testDefaultCultureNoDoubleLookup(): void
    {
        // правка в дефолтном языке: если ключа нет в ru — сразу не найден
        $this->assertNull($this->resolver()->resolve('blocks', 'promo_title', 'ru'));
    }
}
