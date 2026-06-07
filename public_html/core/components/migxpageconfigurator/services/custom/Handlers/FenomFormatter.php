<?php

namespace MpcServices\Handlers;

/**
 * Чистые (PURE/STATIC) строковые трансформации Fenom-разметки при рендере секций.
 * Вынесено из Render (God-класс): `##`→`{` с защитой data-mpc-* атрибутов и
 * простановка кавычек у «голых» значений параметров `snippet:[...]` (eager-резолв
 * `##` отдаёт литералы без кавычек → Fenom бы споткнулся). Сюда же скобочный/
 * строковый парсер (matchBracket/skipString/readValueEnd). Никакого состояния/IO.
 *
 * @author Arthur Shevchenko (https://t.me/ShevArtV)
 */
class FenomFormatter
{
    /** `##`→`{` для фронт-парсера pdoTools, НЕ трогая `{` внутри data-mpc-* атрибутов. */
    public static function convertStaticHashToBrace(string $html): string
    {
        $protected = [];
        $html = preg_replace_callback(
            '/\sdata-mpc-[a-z0-9-]*="[^"]*"/i',
            static function ($m) use (&$protected) {
                $token = "\x02MPCATTR" . count($protected) . "\x03";
                $protected[$token] = str_replace('{', '&#123;', $m[0]);
                return $token;
            },
            $html
        );
        $html = str_replace('##', '{', $html);
        return $protected ? strtr($html, $protected) : $html;
    }

    /** В каждом `snippet:[...]` проставляет кавычки «голым» значениям параметров. */
    public static function quoteSnippetParamValues(string $html): string
    {
        $marker = 'snippet:';
        $len    = strlen($html);
        $result = '';
        $offset = 0;

        while (($pos = strpos($html, $marker, $offset)) !== false) {
            // пропускаем пробелы между 'snippet:' и '['
            $b = $pos + strlen($marker);
            while ($b < $len && ctype_space($html[$b])) {
                $b++;
            }
            if ($b >= $len || $html[$b] !== '[') {
                // не вызов с массивом параметров — копируем как есть, идём дальше
                $result .= substr($html, $offset, $b - $offset);
                $offset = $b;
                continue;
            }
            $end = self::matchBracket($html, $b);
            if ($end === -1) {
                break; // нет парной ] — оставляем хвост нетронутым
            }
            $result .= substr($html, $offset, $b - $offset)
                . self::quoteBareValues(substr($html, $b, $end - $b + 1));
            $offset = $end + 1;
        }

        return $result . substr($html, $offset);
    }

    /** Простановка кавычек значениям внутри `[...]` (учитывает строки/вложенность). */
    public static function quoteBareValues(string $s): string
    {
        $len = strlen($s);
        $out = '';
        $i   = 0;

        while ($i < $len) {
            $ch = $s[$i];

            // строковый литерал — копируем целиком
            if ($ch === "'" || $ch === '"') {
                $j = self::skipString($s, $i);
                $out .= substr($s, $i, $j - $i + 1);
                $i = $j + 1;
                continue;
            }

            // оператор '=>' — за ним идёт значение
            if ($ch === '=' && $i + 1 < $len && $s[$i + 1] === '>') {
                $out .= '=>';
                $i += 2;
                while ($i < $len && ctype_space($s[$i])) {
                    $out .= $s[$i];
                    $i++;
                }
                if ($i >= $len) {
                    break;
                }
                // читаем ПОЛНОЕ значение с учётом вложенных () [] {} и строк
                $end   = self::readValueEnd($s, $i);
                $raw   = substr($s, $i, $end - $i);
                $core  = rtrim($raw);
                $trail = substr($raw, strlen($core));
                $out  .= self::normalizeValue($core) . $trail;
                $i = $end;
                continue;
            }

            $out .= $ch;
            $i++;
        }

        return $out;
    }

    /** Индекс конца значения параметра (учёт вложенных () [] {} и строк, разделитель — `,`). */
    public static function readValueEnd(string $s, int $i): int
    {
        $len    = strlen($s);
        $square = 0;
        $round  = 0;
        $curly  = 0;
        while ($i < $len) {
            $ch = $s[$i];
            if ($ch === "'" || $ch === '"') {
                $i = self::skipString($s, $i) + 1;
                continue;
            }
            if ($ch === '[') {
                $square++;
            } elseif ($ch === ']') {
                if ($square === 0) {
                    break;
                }
                $square--;
            } elseif ($ch === '(') {
                $round++;
            } elseif ($ch === ')') {
                if ($round > 0) {
                    $round--;
                }
            } elseif ($ch === '{') {
                $curly++;
            } elseif ($ch === '}') {
                if ($curly > 0) {
                    $curly--;
                }
            } elseif ($ch === ',' && $square === 0 && $round === 0 && $curly === 0) {
                break;
            }
            $i++;
        }
        return $i;
    }

    /** «Голое» значение → в кавычки; выражения/литералы/числа/массивы — как есть. */
    public static function normalizeValue(string $v): string
    {
        if ($v === '') {
            return $v;
        }
        if ($v[0] === '[') {
            return self::quoteBareValues($v);
        }
        if (in_array($v[0], ["'", '"', '$', '{', '(', '@'], true)) {
            return $v;
        }
        if (is_numeric($v) || $v === 'true' || $v === 'false' || $v === 'null') {
            return $v;
        }
        if (preg_match('/^[\p{L}\p{N}_.\/\-]+$/u', $v)) {
            return "'" . $v . "'";
        }
        return $v; // что-то с операторами/пробелами — это выражение, не квотим
    }

    /** Индекс парной `]` для `[` на позиции $start; -1 — нет пары. */
    public static function matchBracket(string $s, int $start): int
    {
        $len   = strlen($s);
        $depth = 0;
        for ($i = $start; $i < $len; $i++) {
            $ch = $s[$i];
            if ($ch === "'" || $ch === '"') {
                $i = self::skipString($s, $i);
                continue;
            }
            if ($ch === '[') {
                $depth++;
            } elseif ($ch === ']') {
                if (--$depth === 0) {
                    return $i;
                }
            }
        }
        return -1;
    }

    /** Индекс закрывающей кавычки строки, начинающейся на $start (учёт \-escape). */
    public static function skipString(string $s, int $start): int
    {
        $q   = $s[$start];
        $len = strlen($s);
        for ($i = $start + 1; $i < $len; $i++) {
            if ($s[$i] === '\\' && $i + 1 < $len) {
                $i++;
                continue;
            }
            if ($s[$i] === $q) {
                return $i;
            }
        }
        return $len - 1;
    }
}
