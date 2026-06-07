<?php

namespace MpcTests\Unit\Grabber;

use MpcServices\Handlers\Grabber\LexiconKeyHelper;
use MpcServices\Handlers\Grabber\LexiconManager;
use PHPUnit\Framework\TestCase;

/**
 * Юнит чистых key-утилит, вынесенных из LexiconManager в LexiconKeyHelper.
 * Проверяем формат ключа + что делегаты LexiconManager:: дают тот же результат
 * (обратная совместимость не нарушена).
 */
class LexiconKeyHelperTest extends TestCase
{
    public function testGetLexiconKeyTopLevelNoIdx(): void
    {
        $this->assertSame('hero_title', LexiconKeyHelper::getLexiconKey([
            'prefix' => 'hero', 'fieldName' => 'title',
        ]));
    }

    public function testGetLexiconKeyWithParentAndIdx(): void
    {
        $this->assertSame('hero_cards_title_2', LexiconKeyHelper::getLexiconKey([
            'prefix' => 'hero', 'parentFieldName' => 'cards', 'fieldName' => 'title', 'idx' => 2,
        ]));
    }

    public function testGetLexiconKeyIdxZeroHasNoSuffix(): void
    {
        $this->assertSame('hero_cards_title', LexiconKeyHelper::getLexiconKey([
            'prefix' => 'hero', 'parentFieldName' => 'cards', 'fieldName' => 'title', 'idx' => 0,
        ]));
    }

    /** @dataProvider appendCases */
    public function testAppendLexiconParent(string $parent, string $field, int $idx, string $expected): void
    {
        $this->assertSame($expected, LexiconKeyHelper::appendLexiconParent($parent, $field, $idx));
    }

    public function appendCases(): array
    {
        return [
            'empty parent idx0'  => ['', 'cards', 0, 'cards'],
            'empty parent idx2'  => ['', 'cards', 2, 'cards_2'],
            'with parent idx0'   => ['hero', 'cards', 0, 'hero_cards'],
            'with parent idx3'   => ['hero', 'cards', 3, 'hero_cards_3'],
        ];
    }

    public function testGetLexiconKeyForPathEmptyPrefixOrField(): void
    {
        $this->assertSame('', LexiconKeyHelper::getLexiconKeyForPath('', [['field' => 'cards']], 'title'));
        $this->assertSame('', LexiconKeyHelper::getLexiconKeyForPath('hero', [['field' => 'cards']], ''));
    }

    public function testGetLexiconKeyForPathTopLevel(): void
    {
        $this->assertSame('hero_title', LexiconKeyHelper::getLexiconKeyForPath('hero', [], 'title'));
    }

    public function testGetLexiconKeyForPathSingleSegment(): void
    {
        // path [{cards, idx:1}] → parent='cards_1', leafIdx=1 → hero_cards_1_title_1
        $this->assertSame('hero_cards_1_title_1', LexiconKeyHelper::getLexiconKeyForPath(
            'hero', [['field' => 'cards', 'idx' => 1]], 'title'
        ));
    }

    public function testGetLexiconKeyForPathNested(): void
    {
        // [{cards,0},{items,2}] → parent='cards_items_2', leafIdx=2 → hero_cards_items_2_name_2
        $this->assertSame('hero_cards_items_2_name_2', LexiconKeyHelper::getLexiconKeyForPath(
            'hero', [['field' => 'cards', 'idx' => 0], ['field' => 'items', 'idx' => 2]], 'name'
        ));
    }

    /** Делегаты LexiconManager:: дают тот же результат (совместимость). */
    public function testLexiconManagerDelegatesMatch(): void
    {
        $opts = ['prefix' => 'p', 'parentFieldName' => 'a', 'fieldName' => 'b', 'idx' => 5];
        $this->assertSame(LexiconKeyHelper::getLexiconKey($opts), LexiconManager::getLexiconKey($opts));
        $this->assertSame(
            LexiconKeyHelper::appendLexiconParent('a', 'b', 2),
            LexiconManager::appendLexiconParent('a', 'b', 2)
        );
        $path = [['field' => 'cards', 'idx' => 1]];
        $this->assertSame(
            LexiconKeyHelper::getLexiconKeyForPath('hero', $path, 'title'),
            LexiconManager::getLexiconKeyForPath('hero', $path, 'title')
        );
    }
}
