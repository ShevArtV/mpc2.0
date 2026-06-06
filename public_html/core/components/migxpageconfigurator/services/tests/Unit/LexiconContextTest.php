<?php

namespace MpcTests\Unit;

use MpcServices\Handlers\LexiconContext;
use PHPUnit\Framework\TestCase;

/**
 * Тесты ЧИСТОЙ логики резолвера контекста (разбор ключа + подбор подписи).
 * DB-зависимая сборка карт (ensureMaps) тут не покрывается.
 */
class LexiconContextTest extends TestCase
{
    // splitSectionKey -------------------------------------------------

    public function testSplitPicksLongestPrefix(): void
    {
        // features_demo длиннее features → не должен перехватываться коротким
        $r = LexiconContext::splitSectionKey('features_demo_title', ['features', 'features_demo']);
        $this->assertSame(['features_demo', 'title'], $r);
    }

    public function testSplitExactPrefixNoRemainder(): void
    {
        $this->assertSame(['howto', ''], LexiconContext::splitSectionKey('howto', ['howto']));
    }

    public function testSplitSimpleField(): void
    {
        $this->assertSame(['howto', 'title'], LexiconContext::splitSectionKey('howto_title', ['howto']));
    }

    public function testSplitNoMatchReturnsNull(): void
    {
        $this->assertNull(LexiconContext::splitSectionKey('unknown_key', ['howto', 'features']));
    }

    public function testSplitIgnoresEmptyPrefix(): void
    {
        $this->assertNull(LexiconContext::splitSectionKey('howto_title', ['']));
    }

    // buildBreadcrumb -------------------------------------------------

    private array $caps = ['title' => 'Заголовок', 'content' => 'Текст', 'cards' => 'Карточки', 'picture' => 'Изображение', 'alt' => 'Альтернативный текст', 'cta_text' => 'Текст кнопки'];
    private array $lists = ['cards' => true, 'stack' => true];

    public function testCrumbTopLevelField(): void
    {
        $this->assertSame('Заголовок', LexiconContext::buildBreadcrumb('title', $this->caps, $this->lists));
    }

    public function testCrumbListRowIdxZero(): void
    {
        // cards_title (idx0 опущен): cards — список → Элемент 1
        $this->assertSame('Карточки › Элемент 1 › Заголовок',
            LexiconContext::buildBreadcrumb('cards_title', $this->caps, $this->lists));
    }

    public function testCrumbListRowIdxN(): void
    {
        // cards_1_title_1: явный idx=1 → Элемент 2; хвостовой _1 листа игнорим
        $this->assertSame('Карточки › Элемент 2 › Заголовок',
            LexiconContext::buildBreadcrumb('cards_1_title_1', $this->caps, $this->lists));
    }

    public function testCrumbListContentField(): void
    {
        $this->assertSame('Карточки › Элемент 3 › Текст',
            LexiconContext::buildBreadcrumb('cards_2_content_2', $this->caps, $this->lists));
    }

    public function testCrumbMediaSubfieldNoElement(): void
    {
        // picture — не список → без «Элемент»; alt — лист
        $this->assertSame('Изображение › Альтернативный текст',
            LexiconContext::buildBreadcrumb('picture_alt', $this->caps, $this->lists));
    }

    public function testCrumbMultiSegmentFieldName(): void
    {
        // cta_text — двусегментное имя поля (жадный матч)
        $this->assertSame('Текст кнопки',
            LexiconContext::buildBreadcrumb('cta_text', $this->caps, $this->lists));
    }

    public function testCrumbUnknownSegmentsRaw(): void
    {
        // неизвестные сегменты (опции listbox) — как есть
        $this->assertSame('plan › start',
            LexiconContext::buildBreadcrumb('plan_start', $this->caps, $this->lists));
    }

    public function testCrumbEmpty(): void
    {
        $this->assertSame('', LexiconContext::buildBreadcrumb('', $this->caps, $this->lists));
    }

    // stripStaticMarker -----------------------------------------------

    public function testStripStaticParenthetical(): void
    {
        $this->assertSame('CTA', LexiconContext::stripStaticMarker('CTA (статичная секция)'));
    }

    public function testStripStaticTrailingWord(): void
    {
        $this->assertSame('Секция со списками', LexiconContext::stripStaticMarker('Секция со списками статичная'));
    }

    public function testStripStaticKeepsCleanName(): void
    {
        $this->assertSame('Секция с простыми полями', LexiconContext::stripStaticMarker('Секция с простыми полями'));
    }

    // collectPrefixDisplay --------------------------------------------

    public function testCollectPrefixDisplayUsesSectionName(): void
    {
        $into = [];
        LexiconContext::collectPrefixDisplay([
            ['lexicon_prefix' => 'howto', 'section_name' => 'Как это работает', 'MIGX_formname' => 'howto'],
        ], $into);
        $this->assertSame(['howto' => 'Как это работает'], $into);
    }

    public function testCollectPrefixDisplayFallsBackToFormnameAndPrefix(): void
    {
        $into = [];
        // нет lexicon_prefix → берём MIGX_formname; нет section_name → display = prefix
        LexiconContext::collectPrefixDisplay([
            ['MIGX_formname' => 'cta', 'section_name' => ''],
        ], $into);
        $this->assertSame(['cta' => 'cta'], $into);
    }

    public function testCollectPrefixDisplayDoesNotOverwriteHumanName(): void
    {
        $into = ['howto' => 'howto']; // кодовый фолбэк из манифеста
        LexiconContext::collectPrefixDisplay([
            ['lexicon_prefix' => 'howto', 'section_name' => 'Как это работает'],
        ], $into);
        $this->assertSame('Как это работает', $into['howto']); // человекочитаемое перетёрло кодовое

        // но человекочитаемое НЕ перетирается кодовым/пустым
        LexiconContext::collectPrefixDisplay([
            ['lexicon_prefix' => 'howto', 'section_name' => ''],
        ], $into);
        $this->assertSame('Как это работает', $into['howto']);
    }

    // dedupeConsecutive -----------------------------------------------

    public function testDedupeCollapsesAdjacentDuplicates(): void
    {
        $this->assertSame(['Возможности', 'Элемент 1', 'Заголовок'],
            LexiconContext::dedupeConsecutive(['Возможности', 'Возможности', 'Элемент 1', 'Заголовок']));
    }

    public function testDedupeKeepsNonAdjacentDuplicates(): void
    {
        $this->assertSame(['A', 'B', 'A'],
            LexiconContext::dedupeConsecutive(['A', 'B', 'A']));
    }
}
