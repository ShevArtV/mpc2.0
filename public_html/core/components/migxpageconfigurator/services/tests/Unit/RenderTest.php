<?php

namespace MpcTests\Unit;

use MpcServices\Handlers\Render;
use MpcServices\Handlers\FenomFormatter;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Unit-тесты для pure-методов Render.
 *
 * convertStaticHashToBrace тестируем через рефлексию: метод чистый и не
 * зависит от modx/pdo, поэтому инстанс создаём без конструктора.
 */
class RenderTest extends TestCase
{
    private function convert(string $html): string
    {
        return FenomFormatter::convertStaticHashToBrace($html);
    }

    private function chunkBinding(array $section, array $properties): string
    {
        $ref      = new ReflectionClass(Render::class);
        $instance = $ref->newInstanceWithoutConstructor();
        $instance->properties = array_merge([
            'pdotoolsElementsPath' => '/var/www/core/elements/',
            'pathToSections'       => 'sections/',
            'extension'            => '.tpl',
        ], $properties);
        $method = $ref->getMethod('getSectionChunkBinding');
        $method->setAccessible(true);

        return $method->invoke($instance, $section);
    }

    private function quoteParams(string $html): string
    {
        return FenomFormatter::quoteSnippetParamValues($html);
    }

    /** Прод-разметка без маркеров: ##-плейсхолдеры → {-теги. */
    public function testConvertsHashPlaceholdersWithoutMarkers(): void
    {
        $this->assertSame('<div>{foo}</div>', $this->convert('<div>##foo}</div>'));
    }

    /**
     * edit-mode: data-mpc-symbol="##" должен ОСТАТЬСЯ "##" (исходное значение
     * для mpcVE + безопасно на фронте), а ## в теле — конвертироваться.
     */
    public function testKeepsHashSymbolMarkerIntact(): void
    {
        $in  = '<div data-mpc-symbol="##" data-mpc-field="title">##title}</div>';
        $out = '<div data-mpc-symbol="##" data-mpc-field="title">{title}</div>';
        $this->assertSame($out, $this->convert($in));
    }

    /**
     * Литерал '{' внутри data-mpc-* кодируется в &#123;: фронтовый Fenom не
     * парсит сущность, а браузер декодирует её обратно в '{' для редактора.
     */
    public function testEncodesLiteralBraceInsideMarker(): void
    {
        $in  = '<span data-mpc-symbol="{" data-mpc-field="name">{name}</span>';
        $out = '<span data-mpc-symbol="&#123;" data-mpc-field="name">{name}</span>';
        $this->assertSame($out, $this->convert($in));
    }

    /** Несколько маркеров (вложенное поле списка) обрабатываются корректно. */
    public function testHandlesMultipleMarkersOnElement(): void
    {
        $in  = '<li data-mpc-item data-mpc-field-1="x" data-mpc-symbol="##">##x}</li>';
        $out = '<li data-mpc-item data-mpc-field-1="x" data-mpc-symbol="##">{x}</li>';
        $this->assertSame($out, $this->convert($in));
    }

    // --- getSectionChunkBinding -------------------------------------------

    /** Без file_name: привязка деривится из MIGX_formname (lower-case). */
    public function testChunkBindingFallsBackToFormname(): void
    {
        $section = ['MIGX_formname' => 'Hero', 'is_static' => true];
        $this->assertSame('@FILE sections/hero.tpl', $this->chunkBinding($section, []));
    }

    /** file_name (относительный путь от папки элементов) используется как есть. */
    public function testChunkBindingUsesRelativeFileName(): void
    {
        $section = ['file_name' => 'custom/promo.tpl', 'is_static' => true];
        $this->assertSame('@FILE custom/promo.tpl', $this->chunkBinding($section, []));
    }

    /** Абсолютный file_name (легаси/грабер) — префикс папки элементов срезается. */
    public function testChunkBindingStripsElementsPathPrefix(): void
    {
        $section = ['file_name' => '/var/www/core/elements/sections/hero.tpl', 'is_static' => true];
        $this->assertSame('@FILE sections/hero.tpl', $this->chunkBinding($section, []));
    }

    /** Не-статичная секция: при наличии _unstatic-файла берётся он. */
    public function testChunkBindingPrefersUnstaticVariant(): void
    {
        $dir = sys_get_temp_dir() . '/mpc_render_test_' . getmypid() . '/';
        @mkdir($dir . 'sections/', 0777, true);
        file_put_contents($dir . 'sections/hero_unstatic.tpl', 'x');
        try {
            $section = ['MIGX_formname' => 'hero', 'is_static' => false];
            $binding = $this->chunkBinding($section, ['pdotoolsElementsPath' => $dir]);
            $this->assertSame('@FILE sections/hero_unstatic.tpl', $binding);
        } finally {
            @unlink($dir . 'sections/hero_unstatic.tpl');
            @rmdir($dir . 'sections/');
            @rmdir($dir);
        }
    }

    /** Не-статичная секция без _unstatic-файла — остаётся базовый путь. */
    public function testChunkBindingNonStaticWithoutUnstaticKeepsBase(): void
    {
        $section = ['MIGX_formname' => 'hero', 'is_static' => false];
        // pdotoolsElementsPath указывает в несуществующую папку → _unstatic нет.
        $binding = $this->chunkBinding($section, ['pdotoolsElementsPath' => '/no/such/dir/']);
        $this->assertSame('@FILE sections/hero.tpl', $binding);
    }

    // --- quoteSnippetParamValues ------------------------------------------

    /** Голое скалярное значение (eager-резолв ## в static) → в кавычки. */
    public function testQuotesBareScalarParam(): void
    {
        $in  = "{'pdoResources' | snippet: ['parents' => about-us]}";
        $out = "{'pdoResources' | snippet: ['parents' => 'about-us']}";
        $this->assertSame($out, $this->quoteParams($in));
    }

    /** Числа/булево/null/выражения/уже-квотированное — не трогаем. */
    public function testLeavesNonBareValuesUntouched(): void
    {
        $in = "{'s' | snippet: ['limit' => 5, 'flag' => true, 'n' => null, "
            . "'e' => \$resource.alias, 'tpl' => '@FILE chunks/x.tpl']}";
        $this->assertSame($in, $this->quoteParams($in));
    }

    /** Голые значения ВНУТРИ массива квотируются, сам массив — нет. */
    public function testQuotesBareValuesInsideArray(): void
    {
        $in  = "{'s' | snippet: ['where' => ['alias' => about-us, 'published' => 1]]}";
        $out = "{'s' | snippet: ['where' => ['alias' => 'about-us', 'published' => 1]]}";
        $this->assertSame($out, $this->quoteParams($in));
    }

    /** Выражение $… в отложенном массиве остаётся выражением (не квотим). */
    public function testKeepsExpressionInsideArray(): void
    {
        $in = "{'s' | snippet: ['where' => ['alias' => \$resource.alias]]}";
        $this->assertSame($in, $this->quoteParams($in));
    }

    /** Смешанный вызов: скаляр + массив + число + выражение. */
    public function testMixedSnippetCall(): void
    {
        $in  = "{'s' | snippet: ['p' => about-us, 'limit' => 5, "
            . "'where' => ['alias' => about-us, 'id' => \$_modx->resource.id]]}";
        $out = "{'s' | snippet: ['p' => 'about-us', 'limit' => 5, "
            . "'where' => ['alias' => 'about-us', 'id' => \$_modx->resource.id]]}";
        $this->assertSame($out, $this->quoteParams($in));
    }

    /** Вне вызовов сниппета '=>' в разметке не трогается. */
    public function testIgnoresArrowsOutsideSnippetCalls(): void
    {
        $in = '<div data-x="a => b">text => more</div>';
        $this->assertSame($in, $this->quoteParams($in));
    }

    /** Несколько вызовов в одной строке обрабатываются независимо. */
    public function testHandlesMultipleSnippetCalls(): void
    {
        $in  = "{'a' | snippet: ['x' => foo]} and {'b' | snippet: ['y' => bar]}";
        $out = "{'a' | snippet: ['x' => 'foo']} and {'b' | snippet: ['y' => 'bar']}";
        $this->assertSame($out, $this->quoteParams($in));
    }

    /** Выражение в скобках с внутренними кавычками (mpcThumb input) — не трогаем. */
    public function testKeepsParenthesizedExpression(): void
    {
        $in = "{'mpcThumb' | snippet: [ 'input' => ('hero_bg_img' | lexicon), 'options' => '&w=100']}";
        $this->assertSame($in, $this->quoteParams($in));
    }

    /** Скобочное выражение + голое слово рядом: скобки не трогаем, слово квотим. */
    public function testMixesParenExpressionAndBareWord(): void
    {
        $in  = "{'s' | snippet: ['input' => ('a' | lexicon), 'alias' => about-us]}";
        $out = "{'s' | snippet: ['input' => ('a' | lexicon), 'alias' => 'about-us']}";
        $this->assertSame($out, $this->quoteParams($in));
    }

    /** Значение-выражение с операторами (без скобок) не квотируется. */
    public function testKeepsOperatorExpression(): void
    {
        $in = "{'s' | snippet: ['x' => \$a ?: \$b, 'y' => foo]}";
        $out = "{'s' | snippet: ['x' => \$a ?: \$b, 'y' => 'foo']}";
        $this->assertSame($out, $this->quoteParams($in));
    }
}
