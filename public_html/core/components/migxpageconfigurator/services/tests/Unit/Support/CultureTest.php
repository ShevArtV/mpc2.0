<?php

namespace MpcTests\Unit\Support;

use MpcServices\Handlers\Support\Culture;
use MpcTests\Stubs\ModxStub;
use PHPUnit\Framework\TestCase;

/**
 * Язык записи = язык, из которого рендерит витрина.
 *
 * Регрессия sleepandglow (01.09.2026): cookie mpc_lang=en, оставшаяся от другого
 * поддомена, уводила правку чешской страницы в lexicon/en/.
 */
class CultureTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_COOKIE['mpc_lang'], $_COOKIE['site_lang']);
    }

    private function modx(array $config): ModxStub
    {
        return new ModxStub(null, $config);
    }

    public function testCookieIgnoredWhenLanguageNotSetOnRequest(): void
    {
        $_COOKIE['mpc_lang'] = 'en';
        $modx = $this->modx([
            'cultureKey'                   => 'cs',
            'mpc_set_language_on_request'  => 0,
            'mpc_available_languages'      => 'cs,en',
        ]);

        $this->assertSame('cs', Culture::resolve($modx));
    }

    public function testCookieUsedWhenAllowed(): void
    {
        $_COOKIE['mpc_lang'] = 'de';
        $modx = $this->modx([
            'cultureKey'                  => 'fr',
            'mpc_set_language_on_request' => 1,
            'mpc_available_languages'     => 'fr,de,it',
        ]);

        $this->assertSame('de', Culture::resolve($modx));
    }

    public function testCookieOutsideAvailableFallsBackToCultureKey(): void
    {
        $_COOKIE['mpc_lang'] = 'en';
        $modx = $this->modx([
            'cultureKey'                  => 'cs',
            'mpc_set_language_on_request' => 1,
            'mpc_available_languages'     => 'cs',
        ]);

        $this->assertSame('cs', Culture::resolve($modx));
    }

    public function testEmptyAvailableListFallsBackToCultureKey(): void
    {
        $_COOKIE['mpc_lang'] = 'en';
        $modx = $this->modx([
            'cultureKey'                  => 'cs',
            'mpc_set_language_on_request' => 1,
            'mpc_available_languages'     => '',
        ]);

        $this->assertSame('cs', Culture::resolve($modx));
    }

    public function testCustomCookieNameIsRespected(): void
    {
        $_COOKIE['site_lang'] = 'it';
        $modx = $this->modx([
            'cultureKey'                  => 'fr',
            'mpc_set_language_on_request' => 1,
            'mpc_lang_cookie_name'        => 'site_lang',
            'mpc_available_languages'     => 'fr,de,it',
        ]);

        $this->assertSame('it', Culture::resolve($modx));
    }

    public function testTraversalInCookieIsStripped(): void
    {
        $_COOKIE['mpc_lang'] = '../../etc';
        $modx = $this->modx([
            'cultureKey'                  => 'cs',
            'mpc_set_language_on_request' => 1,
            'mpc_available_languages'     => 'cs,../../etc',
        ]);

        $this->assertSame('etc', Culture::resolve($modx));
    }

    public function testNoCookieGivesCultureKey(): void
    {
        $modx = $this->modx([
            'cultureKey'                  => 'ja',
            'mpc_set_language_on_request' => 1,
            'mpc_available_languages'     => 'ja,en',
        ]);

        $this->assertSame('ja', Culture::resolve($modx));
    }

    public function testDefaultLanguageFallsBackWhenSettingEmpty(): void
    {
        $modx = $this->modx(['mpc_default_language' => '']);

        $this->assertSame('ru', Culture::defaultLanguage($modx));
        $this->assertSame('en', Culture::defaultLanguage($modx, 'en'));
    }

    public function testDefaultLanguageFromSetting(): void
    {
        $modx = $this->modx(['mpc_default_language' => 'cs']);

        $this->assertSame('cs', Culture::defaultLanguage($modx));
    }
}
