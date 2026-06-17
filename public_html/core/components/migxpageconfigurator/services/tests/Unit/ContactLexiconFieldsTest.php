<?php

namespace MpcTests\Unit;

use MpcServices\Handlers\Base;
use PHPUnit\Framework\TestCase;

/**
 * Парсер настройки mpc_contact_lexicon_fields (тип→поля) и единая точка решения
 * о переводе под-поля контакта (грабер + каттер). Кейс: переводить значение
 * только у адреса, не у соцсетей/телефона.
 */
class ContactLexiconFieldsTest extends TestCase
{
    public function testDefaultSingleFieldGoesToWildcard(): void
    {
        $this->assertSame(['*' => ['caption']], Base::parseContactLexiconFields('caption'));
    }

    public function testTypeScopedAndWildcardMix(): void
    {
        $map = Base::parseContactLexiconFields('caption, address:value, address:fvalue');
        $this->assertSame([
            '*'       => ['caption'],
            'address' => ['value', 'fvalue'],
        ], $map);
    }

    public function testWhitespaceAndEmptyEntriesIgnored(): void
    {
        $this->assertSame(
            ['*' => ['caption'], 'address' => ['value']],
            Base::parseContactLexiconFields('  caption , , address : value ,  ')
        );
    }

    public function testEmptyStringYieldsEmptyMap(): void
    {
        $this->assertSame([], Base::parseContactLexiconFields(''));
    }

    public function testAddressValueTranslatableButNotSocialOrPhone(): void
    {
        $map = Base::parseContactLexiconFields('caption, address:value');

        // value переводится только у адреса
        $this->assertTrue(Base::isContactFieldTranslatable($map, 'address', 'value'));
        $this->assertFalse(Base::isContactFieldTranslatable($map, 'social', 'value'));
        $this->assertFalse(Base::isContactFieldTranslatable($map, 'phone', 'value'));

        // caption переводится у всех типов (правило '*')
        $this->assertTrue(Base::isContactFieldTranslatable($map, 'social', 'caption'));
        $this->assertTrue(Base::isContactFieldTranslatable($map, 'phone', 'caption'));
        $this->assertTrue(Base::isContactFieldTranslatable($map, 'address', 'caption'));

        // прочие поля не переводятся
        $this->assertFalse(Base::isContactFieldTranslatable($map, 'address', 'attributes'));
    }

    public function testBackwardCompatAllFieldsForAllTypes(): void
    {
        $map = Base::parseContactLexiconFields('caption,value,fvalue,attributes');
        foreach (['caption', 'value', 'fvalue', 'attributes'] as $field) {
            $this->assertTrue(Base::isContactFieldTranslatable($map, 'phone', $field));
            $this->assertTrue(Base::isContactFieldTranslatable($map, 'address', $field));
        }
    }

    public function testEmptyMapTranslatesNothing(): void
    {
        $this->assertFalse(Base::isContactFieldTranslatable([], 'address', 'value'));
    }
}
