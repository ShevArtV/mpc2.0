<?php

namespace MpcServices\Handlers;

/**
 * Разбор и валидация маркера произвольного лексиконного ключа
 * `data-mpc-lexicon="topic:key"`.
 *
 * ЕДИНЫЙ источник правды для трёх потребителей: грабера (пишет значение в топик),
 * каттера (ставит плейсхолдер ##'key'|lexicon}) и FieldWriter/резолвера (правка
 * из визуального редактора). Топик опционален: без префикса `topic:` ключ
 * относится к топику по умолчанию (первый в mpc_arbitrary_lexicon_topics), а на
 * чтении-правке — сканируется по всему списку топиков.
 */
final class ArbitraryLexicon
{
    /** Ключ — индекс $_lang и литерал в `##'key'|lexicon}`: без кавычек/слэшей. */
    public static function validKey(string $key): bool
    {
        return $key !== '' && (bool)preg_match('/^[A-Za-z0-9_.\-]+$/', $key);
    }

    /** Топик — имя файла {topic}.inc.php: только безопасные символы (нет traversal). */
    public static function validTopic(string $topic): bool
    {
        return $topic !== '' && (bool)preg_match('/^[A-Za-z0-9_\-]+$/', $topic);
    }

    /**
     * Разбирает значение атрибута `topic:key` (двоеточие — разделитель; в ключе
     * двоеточий нет по validKey). Возвращает ['topic'=>string,'key'=>string] или
     * null, если ключ невалиден. Невалидный/пустой топик → ''.
     *
     * @return array{topic:string,key:string}|null
     */
    public static function parse(string $raw): ?array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        $topic = '';
        $key   = $raw;
        $pos   = strpos($raw, ':');
        if ($pos !== false) {
            $topic = trim(substr($raw, 0, $pos));
            $key   = trim(substr($raw, $pos + 1));
        }
        if (!self::validKey($key)) {
            return null;
        }
        if ($topic !== '' && !self::validTopic($topic)) {
            $topic = '';
        }
        return ['topic' => $topic, 'key' => $key];
    }

    /**
     * Топик по умолчанию для маркера без префикса `topic:` (запись на нарезке) —
     * первый из настроенных. Пусто, если список топиков не задан.
     */
    public static function defaultTopic(array $topics): string
    {
        foreach ($topics as $t) {
            $t = trim((string)$t);
            if (self::validTopic($t)) {
                return $t;
            }
        }
        return '';
    }
}
