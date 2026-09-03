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

    // --- темы оформления (applyTheme / resolveTheme) ----------------------

    /** Привязка чанка с активной темой и заданными свойствами темы. */
    private function themedBinding(array $section, string $theme, array $properties = []): string
    {
        $ref      = new ReflectionClass(Render::class);
        $instance = $ref->newInstanceWithoutConstructor();
        $instance->properties = array_merge([
            'pdotoolsElementsPath' => '/var/www/core/elements/',
            'pathToSections'       => 'sections/',
            'extension'            => '.tpl',
            'themesSubdir'         => '_themes/',
        ], $properties);
        $instance->currentTheme = $theme;
        $method = $ref->getMethod('getSectionChunkBinding');
        $method->setAccessible(true);

        return $method->invoke($instance, $section);
    }

    /** Тема переопределяет секцию: при наличии файла темы берётся он. */
    public function testChunkBindingAppliesThemeOverride(): void
    {
        $dir = sys_get_temp_dir() . '/mpc_theme_test_' . getmypid() . '/';
        @mkdir($dir . 'sections/_themes/dark/', 0777, true);
        file_put_contents($dir . 'sections/_themes/dark/hero.tpl', 'x');
        try {
            $section = ['MIGX_formname' => 'Hero', 'is_static' => true];
            $binding = $this->themedBinding($section, 'dark', ['pdotoolsElementsPath' => $dir]);
            $this->assertSame('@FILE sections/_themes/dark/hero.tpl', $binding);
        } finally {
            @unlink($dir . 'sections/_themes/dark/hero.tpl');
            @rmdir($dir . 'sections/_themes/dark/');
            @rmdir($dir . 'sections/_themes/');
            @rmdir($dir . 'sections/');
            @rmdir($dir);
        }
    }

    /** Тема задана, но файла секции в ней нет → fallback на базовую вёрстку. */
    public function testChunkBindingFallsBackWhenThemeFileMissing(): void
    {
        $section = ['MIGX_formname' => 'Hero', 'is_static' => true];
        $binding = $this->themedBinding($section, 'dark', ['pdotoolsElementsPath' => '/no/such/dir/']);
        $this->assertSame('@FILE sections/hero.tpl', $binding);
    }

    /** Кастомный file_name вне sections/ темой не затрагивается. */
    public function testChunkBindingIgnoresThemeForPathOutsideSections(): void
    {
        $section = ['file_name' => 'custom/promo.tpl', 'is_static' => true];
        $binding = $this->themedBinding($section, 'dark', ['pdotoolsElementsPath' => '/no/such/dir/']);
        $this->assertSame('@FILE custom/promo.tpl', $binding);
    }

    /** Имя темы с обходом пути (`..`) игнорируется — берётся базовый путь. */
    public function testChunkBindingRejectsThemeTraversal(): void
    {
        $section = ['MIGX_formname' => 'Hero', 'is_static' => true];
        $binding = $this->themedBinding($section, '../../etc', ['pdotoolsElementsPath' => '/no/such/dir/']);
        $this->assertSame('@FILE sections/hero.tpl', $binding);
    }

    private function resolveTheme(int $templateId, array $properties): string
    {
        $ref      = new ReflectionClass(Render::class);
        $instance = $ref->newInstanceWithoutConstructor();
        $instance->properties = $properties;
        $method = $ref->getMethod('resolveTheme');
        $method->setAccessible(true);

        return $method->invoke($instance, $templateId);
    }

    /** Карта шаблонов приоритетнее глобальной темы. */
    public function testResolveThemePrefersTemplateMap(): void
    {
        $props = ['theme' => 'light', 'themeTemplates' => [5 => 'dark']];
        $this->assertSame('dark', $this->resolveTheme(5, $props));
        $this->assertSame('light', $this->resolveTheme(12, $props));
    }

    /** Нет ни карты, ни глобалки → пустая тема (базовая вёрстка). */
    public function testResolveThemeEmptyByDefault(): void
    {
        $this->assertSame('', $this->resolveTheme(5, ['theme' => '', 'themeTemplates' => []]));
    }

    private function parseThemeTemplates(string $raw): array
    {
        $ref    = new ReflectionClass(Render::class);
        $method = $ref->getMethod('parseThemeTemplates');
        $method->setAccessible(true);

        return $method->invoke(null, $raw);
    }

    /** JSON-карта парсится в [int=>string], пустые/нулевые ключи отбрасываются. */
    public function testParseThemeTemplatesParsesJson(): void
    {
        $this->assertSame([5 => 'dark', 12 => 'summer'], $this->parseThemeTemplates('{"5":"dark","12":"summer","0":"x","7":""}'));
    }

    /** Невалидный JSON / пустая строка → пустая карта. */
    public function testParseThemeTemplatesHandlesInvalid(): void
    {
        $this->assertSame([], $this->parseThemeTemplates(''));
        $this->assertSame([], $this->parseThemeTemplates('not json'));
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

    private function inheritFields(array $resource, array $type, array $editable): array
    {
        $ref      = new ReflectionClass(Render::class);
        $instance = $ref->newInstanceWithoutConstructor();
        $instance->properties = ['editableResourceFields' => $editable];
        $method = $ref->getMethod('inheritEditableFields');
        $method->setAccessible(true);

        return $method->invoke($instance, $resource, $type);
    }

    /** Пустое контентное поле ресурса наследует значение «типа страницы». */
    public function testInheritsEmptyEditableFieldFromType(): void
    {
        $out = $this->inheritFields(
            ['content' => '', 'pagetitle' => 'Страница'],
            ['content' => 'Текст из типа', 'pagetitle' => 'Тип'],
            ['content', 'pagetitle']
        );
        $this->assertSame('Текст из типа', $out['content']);
    }

    /** Заполненное поле ресурса НЕ перетирается значением типа. */
    public function testKeepsFilledEditableField(): void
    {
        $out = $this->inheritFields(
            ['content' => 'Свой текст'],
            ['content' => 'Текст из типа'],
            ['content']
        );
        $this->assertSame('Свой текст', $out['content']);
    }

    /** Поля вне белого списка (структурные) каскадом не затрагиваются. */
    public function testDoesNotInheritNonEditableField(): void
    {
        $out = $this->inheritFields(
            ['alias' => ''],
            ['alias' => 'type-alias'],
            ['content', 'pagetitle']
        );
        $this->assertSame('', $out['alias']);
    }

    /** Пустое поле у ресурса и пустое у типа — остаётся пустым (нечего наследовать). */
    public function testKeepsEmptyWhenTypeAlsoEmpty(): void
    {
        $out = $this->inheritFields(
            ['content' => ''],
            ['content' => '   '],
            ['content']
        );
        $this->assertSame('', $out['content']);
    }

    // --- applySectionIdentity: имя/лексикон ЭТОЙ записи конфига ---------------

    private function identity(string $html, array $section): string
    {
        $ref      = new ReflectionClass(Render::class);
        $instance = $ref->newInstanceWithoutConstructor();
        $method   = $ref->getMethod('applySectionIdentity');
        $method->setAccessible(true);

        return $method->invoke($instance, $html, $section);
    }

    /** Копия секции получает своё имя и свой лексикон-префикс, а не первой. */
    public function testAppliesOwnNameAndLexiconToSectionTag(): void
    {
        $in = '<section data-mpc-lexicon="card_grid" data-mpc-section="card_grid" '
            . 'data-mpc-name="Card grid" class="card-grid"><h2>x</h2></section>';
        $out = $this->identity($in, [
            'section_name'   => 'Card grid 2',
            'lexicon_prefix' => 'card_grid_2',
            'MIGX_formname'  => 'card_grid',
        ]);
        $this->assertStringContainsString('data-mpc-name="Card grid 2"', $out);
        $this->assertStringContainsString('data-mpc-lexicon="card_grid_2"', $out);
        // Имя ТИПА секции не трогаем: по нему рендерится чанк.
        $this->assertStringContainsString('data-mpc-section="card_grid"', $out);
    }

    /** Вложенные data-mpc-lexicon (произвольные ключи полей) остаются как были. */
    public function testDoesNotTouchNestedLexiconMarkers(): void
    {
        $in = '<section data-mpc-lexicon="card_grid" data-mpc-section="card_grid" '
            . 'data-mpc-name="Card grid"><span data-mpc-lexicon="common:more">…</span></section>';
        $out = $this->identity($in, [
            'section_name'   => 'Card grid 3',
            'lexicon_prefix' => 'card_grid_3',
        ]);
        $this->assertStringContainsString('<span data-mpc-lexicon="common:more">', $out);
        $this->assertStringContainsString('data-mpc-lexicon="card_grid_3" data-mpc-section=', $out);
    }

    /** Не edit-режим (маркеров нет) — HTML возвращается дословно. */
    public function testLeavesHtmlIntactWithoutMarkers(): void
    {
        $in = '<section class="card-grid"><h2>x</h2></section>';
        $this->assertSame($in, $this->identity($in, [
            'section_name'   => 'Card grid 2',
            'lexicon_prefix' => 'card_grid_2',
        ]));
    }

    /** Пустые значения конфига разметку не затирают. */
    public function testKeepsMarkupWhenConfigValuesEmpty(): void
    {
        $in = '<section data-mpc-section="card_grid" data-mpc-name="Card grid"></section>';
        $this->assertSame($in, $this->identity($in, ['section_name' => '', 'lexicon_prefix' => '']));
    }

    /** Кавычки и спецсимволы в имени экранируются, атрибут не разваливается. */
    public function testEscapesQuotesInSectionName(): void
    {
        $in  = '<section data-mpc-section="card_grid" data-mpc-name="Card grid"></section>';
        $out = $this->identity($in, ['section_name' => 'Grid "A" & $B']);
        $this->assertStringContainsString('data-mpc-name="Grid &quot;A&quot; &amp; $B"', $out);
    }
}
