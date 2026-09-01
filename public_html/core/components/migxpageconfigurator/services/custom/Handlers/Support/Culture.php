<?php

namespace MpcServices\Handlers\Support;

/**
 * Единая точка выбора языка ЗАПИСИ (culture) — сегмента пути к файлу лексикона
 * ({lexiconPath}/{culture}/{identifier}.inc.php) и языка правки из редактора.
 *
 * Правило одно: ПИШЕМ ТУДА, ОТКУДА ЧИТАЕТ ВИТРИНА. Витрина рендерит по
 * cultureKey текущего контекста; cookie mpc_lang попадает в cultureKey только
 * когда mpc сам её применяет — при mpc_set_language_on_request=1 через
 * {@see \MpcServices\Mpc::setLanguageSettings()}. Поэтому:
 *
 *  - mpc_set_language_on_request=0 → cookie игнорируется, culture = cultureKey;
 *  - =1 → cookie учитывается, но лишь если её значение входит в
 *    mpc_available_languages ТЕКУЩЕГО контекста (та же проверка, что и в
 *    setLanguageSettings), иначе — cultureKey.
 *
 * Зачем (инцидент sleepandglow, 01.09.2026): FieldWriter брал cookie mpc_lang
 * без всяких проверок. Правка секции на чешском поддомене (контекст с
 * cultureKey=cs, mpc_available_languages=cs) уходила в lexicon/en/ — потому что
 * в браузере жила cookie mpc_lang=en с другого контекста. Витрина cs изменений
 * не показывала, а английский текст оказывался затёрт чешским.
 *
 * culture идёт в путь как компонент каталога, поэтому значение всегда
 * пропускается через basename() — cookie под контролем клиента (traversal),
 * при этом нестандартные форматы (ru-RU, zh_CN, custom) не ломаются.
 */
final class Culture
{
    /** Язык записи для текущего запроса. */
    public static function resolve(\modX $modx): string
    {
        $fallback = self::sanitize((string)$modx->getOption('cultureKey', null, 'en'), 'en');

        if (!(bool)self::contextSetting($modx, 'mpc_set_language_on_request', true)) {
            return $fallback;
        }

        $cookieName = (string)(self::contextSetting($modx, 'mpc_lang_cookie_name', '') ?: 'mpc_lang');
        $cookie     = isset($_COOKIE[$cookieName]) ? trim((string)$_COOKIE[$cookieName]) : '';
        if ($cookie === '') {
            return $fallback;
        }

        $available = array_values(array_filter(array_map(
            'trim',
            explode(',', (string)self::contextSetting($modx, 'mpc_available_languages', ''))
        ), 'strlen'));
        // Список не задан — доверять cookie нечему: setLanguageSettings в этом
        // случае тоже перезаписывает её языком по умолчанию.
        if ($available === [] || !in_array($cookie, $available, true)) {
            return $fallback;
        }

        return self::sanitize($cookie, $fallback);
    }

    /** Язык по умолчанию с учётом контекстного переопределения. */
    public static function defaultLanguage(\modX $modx, string $fallback = 'ru'): string
    {
        $value = self::sanitize((string)self::contextSetting($modx, 'mpc_default_language', $fallback), '');
        return $value !== '' ? $value : $fallback;
    }

    /**
     * Значение настройки с контекстным переопределением: глобальное — база,
     * контекстное перекрывает, только если реально задано (пустая выборка не
     * должна затирать глобалку). Та же семантика, что у
     * Mpc::getContextSettingValue(), но доступная хэндлерам.
     */
    public static function contextSetting(\modX $modx, string $key, $default = null)
    {
        $value = $modx->getOption($key, null, $default);

        $context    = isset($modx->context) ? $modx->context : null;
        $contextKey = (is_object($context) && method_exists($context, 'get')) ? (string)$context->get('key') : '';
        if ($contextKey === '') {
            return $value;
        }

        try {
            $q = $modx->newQuery('modContextSetting');
            $q->select('value');
            $q->where(['key' => $key, 'context_key' => $contextKey]);
            $q->prepare();
            $stmt = isset($q->stmt) ? $q->stmt : null;
            if ($stmt && $stmt->execute()) {
                $ctxValue = $stmt->fetchColumn();
                if ($ctxValue !== false) {
                    $value = $ctxValue;
                }
            }
        } catch (\Throwable $e) {
            // БД недоступна (юнит-тесты со стабами modX) — остаётся глобальное.
        }

        return $value;
    }

    /** Обрезает traversal, не ломая формат culture (ru-RU, zh_CN, custom). */
    private static function sanitize(string $culture, string $fallback): string
    {
        $culture = basename(trim($culture));
        if ($culture === '' || $culture === '.' || $culture === '..') {
            return $fallback;
        }
        return $culture;
    }
}
