<?php

namespace MpcServices\Handlers\Grabber;

/**
 * Принадлежность лексикон-ключа секции по её префиксу.
 *
 * Два правила, оба обязательны:
 *  1. Граница ключа. Ключ принадлежит префиксу, только если равен ему или
 *     начинается с `prefix_`. Голое `strpos($key, $prefix) === 0` считало своим
 *     `info_weighted_title` для секции `info`.
 *  2. Побеждает самый длинный известный префикс. Одной границы мало: ключ
 *     `features_aula_title` начинается с `features_`, но принадлежит секции
 *     `features_aula`. Реестр известных префиксов решает спор в пользу длинного.
 *
 * Реестр задаётся владельцем: у нарезки это вёрстка плюс манифест
 * (`LexiconManager::ensurePrefixRegistry`), у плагина сохранения — конфиги типа
 * страницы и страницы статичных блоков. Логика сравнения при этом одна.
 *
 * @author Arthur Shevchenko (https://t.me/ShevArtV)
 */
class LexiconPrefixMatcher
{
    /** prefix => true */
    private array $knownPrefixes = [];

    /**
     * @param array $knownPrefixes плоский список префиксов секций
     */
    public function __construct(array $knownPrefixes = [])
    {
        foreach ($knownPrefixes as $prefix) {
            $prefix = trim((string)$prefix);
            if ($prefix !== '') {
                $this->knownPrefixes[$prefix] = true;
            }
        }
    }

    /** Реестр известных префиксов (диагностика и тесты). */
    public function getKnownPrefixes(): array
    {
        return array_keys($this->knownPrefixes);
    }

    /**
     * Принадлежит ли ключ секции с префиксом `$prefix`.
     */
    public function owns(string $key, string $prefix): bool
    {
        if ($prefix === '' || $key === '') {
            return false;
        }
        if ($key === $prefix) {
            return true;
        }
        $needle = $prefix . '_';
        if (strpos($key, $needle) !== 0) {
            return false;
        }
        foreach ($this->knownPrefixes as $known => $_) {
            if (strlen($known) <= strlen($prefix)) {
                continue;
            }
            if (strpos($known, $needle) !== 0) {
                continue; // не вложен в наш префикс — к этому ключу отношения не имеет
            }
            if ($key === $known || strpos($key, $known . '_') === 0) {
                return false; // ключ принадлежит более длинному префиксу
            }
        }
        return true;
    }

    /**
     * Отобрать из массива `key => value` записи, принадлежащие префиксу.
     */
    public function filter(array $lexicons, string $prefix): array
    {
        $output = [];
        foreach ($lexicons as $key => $value) {
            if ($this->owns((string)$key, $prefix)) {
                $output[$key] = $value;
            }
        }
        return $output;
    }
}
