<?php

namespace MpcTests\Unit;

use MpcServices\Handlers\ArbitraryLexicon;
use PHPUnit\Framework\TestCase;

/**
 * Разбор/валидация маркера data-mpc-lexicon="topic:key".
 */
class ArbitraryLexiconTest extends TestCase
{
    public function testParseTopicAndKey(): void
    {
        $this->assertSame(['topic' => 'blocks', 'key' => 'promo_title'], ArbitraryLexicon::parse('blocks:promo_title'));
    }

    public function testParseKeyOnly(): void
    {
        $this->assertSame(['topic' => '', 'key' => 'promo_title'], ArbitraryLexicon::parse('promo_title'));
    }

    public function testParseTrimsWhitespace(): void
    {
        $this->assertSame(['topic' => 'blocks', 'key' => 'promo'], ArbitraryLexicon::parse('  blocks : promo '));
    }

    public function testParseEmptyReturnsNull(): void
    {
        $this->assertNull(ArbitraryLexicon::parse(''));
        $this->assertNull(ArbitraryLexicon::parse('   '));
    }

    public function testParseInvalidKeyReturnsNull(): void
    {
        // кавычка в ключе сломала бы плейсхолдер `##'key'|lexicon}`
        $this->assertNull(ArbitraryLexicon::parse("blocks:pro'mo"));
        $this->assertNull(ArbitraryLexicon::parse('blocks:pro mo'));
        $this->assertNull(ArbitraryLexicon::parse('blocks:'));
    }

    public function testParseInvalidTopicDroppedKeyKept(): void
    {
        // топик с traversal/слэшем отбрасывается → топик пуст, ключ остаётся
        $this->assertSame(['topic' => '', 'key' => 'promo'], ArbitraryLexicon::parse('../etc:promo'));
        $this->assertSame(['topic' => '', 'key' => 'promo'], ArbitraryLexicon::parse('a/b:promo'));
    }

    public function testValidKey(): void
    {
        $this->assertTrue(ArbitraryLexicon::validKey('promo_title'));
        $this->assertTrue(ArbitraryLexicon::validKey('promo.title-1'));
        $this->assertFalse(ArbitraryLexicon::validKey(''));
        $this->assertFalse(ArbitraryLexicon::validKey("a'b"));
        $this->assertFalse(ArbitraryLexicon::validKey('a:b'));
    }

    public function testValidTopic(): void
    {
        $this->assertTrue(ArbitraryLexicon::validTopic('blocks'));
        $this->assertTrue(ArbitraryLexicon::validTopic('page-types_1'));
        $this->assertFalse(ArbitraryLexicon::validTopic(''));
        $this->assertFalse(ArbitraryLexicon::validTopic('a/b'));
        $this->assertFalse(ArbitraryLexicon::validTopic('..'));
    }

    public function testDefaultTopicFirstValid(): void
    {
        $this->assertSame('blocks', ArbitraryLexicon::defaultTopic(['blocks', 'promo']));
        // невалидный первый пропускается
        $this->assertSame('promo', ArbitraryLexicon::defaultTopic(['a/b', 'promo']));
        $this->assertSame('', ArbitraryLexicon::defaultTopic([]));
        $this->assertSame('', ArbitraryLexicon::defaultTopic(['']));
    }
}
