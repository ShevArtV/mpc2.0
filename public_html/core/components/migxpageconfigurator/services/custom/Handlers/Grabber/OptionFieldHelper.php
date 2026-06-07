<?php

namespace MpcServices\Handlers\Grabber;

/**
 * Чистые (PURE/STATIC) утилиты для listbox/option-полей: парсинг и классификация
 * возможных значений (data-mpc-values / TV elements), нормализация ключа опции и
 * выбранного значения, признаки option/multi-option TV-типов. Вынесено из
 * LexiconManager (God-класс) — единый источник правды для каттера, грабера и
 * редактора, без состояния/IO.
 *
 * @author Arthur Shevchenko (https://t.me/ShevArtV)
 */
class OptionFieldHelper
{
    /**
     * Парсит data-mpc-values listbox-поля. Keyed-формат "Caption==key||..." (есть
     * хотя бы один '==') → [['caption'=>..,'key'=>..],..]. Неключевой список
     * ("A||B"), пустое или динамика ("@SELECT ...") → null.
     */
    public static function parseListboxOptions(?string $raw): ?array
    {
        $raw = trim((string)$raw);
        if ($raw === '' || $raw[0] === '@' || strpos($raw, '==') === false) {
            return null;
        }
        $opts = [];
        foreach (explode('||', $raw) as $pair) {
            $p = explode('==', $pair, 2);
            $opts[] = [
                'caption' => trim($p[0]),
                'key'     => isset($p[1]) ? trim($p[1]) : trim($p[0]),
            ];
        }
        return $opts;
    }

    /**
     * Нормализация значения опции в ключ-безопасную форму: транслит кириллицы →
     * латиница, lowercase, пробелы → '_', удаление тегов и всех символов кроме
     * [a-z0-9_]. Используется ВЕЗДЕ, где фигурирует value опции, чтобы ключи совпадали.
     */
    public static function normalizeOptionKey(string $value): string
    {
        $v = strip_tags($value);
        if (function_exists('transliterator_transliterate')) {
            $t = transliterator_transliterate('Any-Latin; Latin-ASCII', $v);
            if (is_string($t)) {
                $v = $t;
            }
        }
        $v = mb_strtolower(trim($v), 'UTF-8');
        $v = preg_replace('/\s+/u', '_', $v);
        $v = preg_replace('/[^a-z0-9_]+/u', '', (string)$v);
        return (string)$v;
    }

    /**
     * Классификация формата значений (data-mpc-values / TV elements):
     *   - 'keyed'   "Caption==value||…"  → value нормализуется, lexValue = caption;
     *   - 'list'    "Value1||Value2"     → value нормализуется, lexValue = Value (как есть);
     *   - 'dynamic' "@SELECT …" / пусто  → лексикон не пишем.
     * Возвращает ['mode'=>…, 'options'=>[['caption','value'(norm),'lexValue'],…]].
     */
    public static function classifyListboxOptions(?string $raw): array
    {
        $raw = trim((string)$raw);
        if ($raw === '' || $raw[0] === '@') {
            return ['mode' => 'dynamic', 'options' => []];
        }
        $opts = [];
        if (strpos($raw, '==') !== false) {
            foreach (explode('||', $raw) as $pair) {
                $p = explode('==', $pair, 2);
                $caption = trim($p[0]);
                $rawVal  = isset($p[1]) ? trim($p[1]) : trim($p[0]);
                $opts[] = [
                    'caption'  => $caption,
                    'value'    => self::normalizeOptionKey($rawVal),
                    'lexValue' => $caption,
                ];
            }
            return ['mode' => 'keyed', 'options' => $opts];
        }
        foreach (explode('||', $raw) as $val) {
            $val = trim($val);
            if ($val === '') {
                continue;
            }
            $opts[] = [
                'caption'  => $val,
                'value'    => self::normalizeOptionKey($val),
                'lexValue' => $val, // оригинал как есть, без трансформаций
            ];
        }
        return ['mode' => 'list', 'options' => $opts];
    }

    /** Выбранное значение поля → нормализованная форма (совпадает с ключами опций). */
    public static function normalizeListboxValue(string $value, bool $multiple): string
    {
        if ($multiple) {
            $tokens = preg_split('/\s*(?:,|\|\|)\s*/', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            return implode('||', array_map([self::class, 'normalizeOptionKey'], $tokens));
        }
        return self::normalizeOptionKey($value);
    }

    /**
     * Нормализованные опции в migx-формат — ВСЕГДА keyed "Caption==norm(value)".
     * Едино для секций (inputOptionValues / data-mpc-values) и TV (elements):
     * капшен сохраняется (виден в админке, источник лексикона), value нормализован.
     * dynamic (@SELECT) — как есть.
     */
    public static function normalizeInputOptionValues(string $raw): string
    {
        $parsed = self::classifyListboxOptions($raw);
        if ($parsed['mode'] === 'dynamic') {
            return $raw;
        }
        return implode('||', array_map(static function (array $o): string {
            return $o['caption'] . '==' . $o['value'];
        }, $parsed['options']));
    }

    /** TV-тип со списком возможных значений (опции → лексиконим капшены). */
    public static function isOptionTvType(string $tvType): bool
    {
        return in_array(strtolower(trim($tvType)), ['listbox', 'listbox-multiple', 'option', 'checkbox'], true);
    }

    /**
     * ftype с МНОЖЕСТВЕННЫМ выбором: значение — набор ключей опций через "||"
     * (listbox-multiple, checkbox). Единый источник правды для каттера и грабера.
     */
    public static function isMultiOptionFtype(string $ftype): bool
    {
        return $ftype === 'listbox-multiple' || $ftype === 'checkbox';
    }
}
