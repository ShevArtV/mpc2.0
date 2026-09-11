<?php

namespace MpcTests\Unit\Grabber;

use MpcServices\Handlers\Grabber\LexiconPrefixMatcher;
use PHPUnit\Framework\TestCase;

/**
 * Отбор ключей по префиксу секции: граница ключа плюс «побеждает самый длинный
 * известный префикс» (#2609-151).
 */
class LexiconPrefixMatcherTest extends TestCase
{
    /** Ключи реального page-types.inc.php sleepandglow. */
    private const PREFIXES = [
        'features',
        'features_aula',
        'features_omnia',
        'difference',
        'difference_ventilated',
        'info',
        'info_weighted',
    ];

    public function testKeyEqualToPrefixBelongsToIt(): void
    {
        $m = new LexiconPrefixMatcher(self::PREFIXES);
        $this->assertTrue($m->owns('features', 'features'));
    }

    public function testPrefixWithoutBoundaryIsForeign(): void
    {
        $m = new LexiconPrefixMatcher(self::PREFIXES);
        // `featureset_title` начинается с `features`, но не с `features_`
        $this->assertFalse($m->owns('featureset_title', 'features'));
    }

    public function testOwnKeyBelongsToPrefix(): void
    {
        $m = new LexiconPrefixMatcher(self::PREFIXES);
        $this->assertTrue($m->owns('features_title', 'features'));
        $this->assertTrue($m->owns('features_list_double_img_1_title_1', 'features'));
    }

    public function testLongerKnownPrefixWins(): void
    {
        $m = new LexiconPrefixMatcher(self::PREFIXES);
        $this->assertFalse($m->owns('features_aula_title', 'features'));
        $this->assertTrue($m->owns('features_aula_title', 'features_aula'));

        $this->assertFalse($m->owns('difference_ventilated_subtitle', 'difference'));
        $this->assertTrue($m->owns('difference_ventilated_subtitle', 'difference_ventilated'));

        // ключ, равный более длинному префиксу, тоже не наш
        $this->assertFalse($m->owns('info_weighted', 'info'));
        $this->assertTrue($m->owns('info_weighted', 'info_weighted'));
    }

    public function testUnknownSiblingStaysWithShortPrefix(): void
    {
        // `features_hyaluron` в реестре нет — ключ остаётся за `features`.
        // Это осознанно: без записи в конфиге владельца ключа определить нечем.
        $m = new LexiconPrefixMatcher(self::PREFIXES);
        $this->assertTrue($m->owns('features_hyaluron_title', 'features'));
    }

    public function testEmptyRegistryKeepsBoundaryRule(): void
    {
        $m = new LexiconPrefixMatcher([]);
        $this->assertTrue($m->owns('info_weighted_title', 'info'));
        $this->assertFalse($m->owns('infoweighted_title', 'info'));
    }

    public function testEmptyPrefixOwnsNothing(): void
    {
        $m = new LexiconPrefixMatcher(self::PREFIXES);
        $this->assertFalse($m->owns('features_title', ''));
        $this->assertFalse($m->owns('', 'features'));
    }

    public function testFilterKeepsOnlyOwnKeys(): void
    {
        $m = new LexiconPrefixMatcher(self::PREFIXES);
        $lexicons = [
            'features_title'       => 'Features',
            'features_aula_title'  => 'Aula features',
            'features_omnia_title' => 'Omnia features',
            'difference_title'     => 'Difference',
        ];
        $this->assertSame(['features_title' => 'Features'], $m->filter($lexicons, 'features'));
        $this->assertSame(['features_aula_title' => 'Aula features'], $m->filter($lexicons, 'features_aula'));
    }

    public function testRegistryIsTrimmedAndDeduplicated(): void
    {
        $m = new LexiconPrefixMatcher([' features ', 'features', '', 'features_aula']);
        $this->assertSame(['features', 'features_aula'], $m->getKnownPrefixes());
    }
}
