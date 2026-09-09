<?php

namespace MpcServices\Handlers\Lexicon;

/**
 * Сборка релизного манифеста `{expected, desired}` из двух состояний словаря.
 *
 * Манифест до этого писали руками, и ошибка в `expected` не портила словарь, а
 * останавливала выкладку конфликтом: сервер сверяет базу с живым значением и
 * при расхождении не пишет ничего. Здесь `expected` берётся из зафиксированной
 * базы — снимка (см. {@see SnapshotStore}) или копии словарей на момент начала
 * работ, — а `desired` из текущего состояния.
 *
 * Правила адреса `язык → файл → ключ`:
 *   - ключа не было в базе, есть сейчас  → `expected: null`, новый ключ;
 *   - значение изменилось                → `expected` = значение базы;
 *   - значения совпадают                 → адрес не попадает в манифест вовсе;
 *   - ключ пропал                        → только с `withClears`, `desired` = `[[clear]]`.
 *
 * ⚠️ Отсутствие ФАЙЛА в текущем состоянии удалением не считается даже с
 * `withClears`: срез строится по фильтрам, и файл может быть просто не прочитан.
 * Иначе фильтр по одному языку сгенерировал бы очистку всех остальных.
 *
 * Порядок ключей канонизирован (`ksort` на всех трёх уровнях): отпечаток
 * манифеста в журнале доставленных релизов считается по содержимому, и
 * плавающий порядок делал бы один и тот же релиз каждый раз новым.
 *
 * PURE: ни MODX, ни файлов — состояния передаются вызывающим.
 */
final class ReleaseManifestBuilder
{
    /** Значение `desired`, которым словарь очищает ключ. */
    public const CLEAR = '[[clear]]';

    /**
     * @param array $base состояние базы: lang => rid => key => value
     * @param array $head текущее состояние: lang => rid => key => value
     * @param array $opts ['withClears'=>bool, 'langs'=>string[], 'rids'=>string[], 'keys'=>string[]]
     *
     * @return array{manifest: array{expected: array, desired: array}, summary: array{added:int,changed:int,cleared:int,total:int}, addresses: string[]}
     */
    public static function build(array $base, array $head, array $opts = []): array
    {
        $withClears = !empty($opts['withClears']);
        $onlyLangs  = self::asList($opts['langs'] ?? []);
        $onlyRids   = self::asList($opts['rids'] ?? []);
        $onlyKeys   = self::asList($opts['keys'] ?? []);

        $expected  = [];
        $desired   = [];
        $addresses = [];
        $summary   = ['added' => 0, 'changed' => 0, 'cleared' => 0, 'total' => 0];

        foreach (self::names(array_keys($base), array_keys($head), $onlyLangs) as $lang) {
            $baseByRid = (array)($base[$lang] ?? []);
            $headByRid = (array)($head[$lang] ?? []);

            foreach (self::names(array_keys($baseByRid), array_keys($headByRid), $onlyRids) as $rid) {
                $baseKv  = (array)($baseByRid[$rid] ?? []);
                $headKv  = (array)($headByRid[$rid] ?? []);
                $inScope = array_key_exists($rid, $headByRid);

                foreach (self::names(array_keys($baseKv), array_keys($headKv), $onlyKeys) as $key) {
                    // null в снимке — «ключа не было»; это НЕ пустая строка.
                    $hadBase   = array_key_exists($key, $baseKv) && $baseKv[$key] !== null;
                    $baseValue = $hadBase ? (string)$baseKv[$key] : null;

                    if (array_key_exists($key, $headKv)) {
                        $headValue = (string)$headKv[$key];
                        if ($hadBase && $baseValue === $headValue) {
                            continue;
                        }
                        $expected[$lang][$rid][$key] = $baseValue;
                        $desired[$lang][$rid][$key]  = $headValue;
                        $summary[$hadBase ? 'changed' : 'added']++;
                    } elseif ($hadBase && $withClears && $inScope) {
                        $expected[$lang][$rid][$key] = $baseValue;
                        $desired[$lang][$rid][$key]  = self::CLEAR;
                        $summary['cleared']++;
                    } else {
                        continue;
                    }

                    $addresses[] = $lang . '|' . $rid . '|' . $key;
                    $summary['total']++;
                }
            }
        }

        return [
            'manifest'  => ['expected' => $expected, 'desired' => $desired],
            'summary'   => $summary,
            'addresses' => $addresses,
        ];
    }

    /** Читаемый JSON манифеста: его кладут в git и просматривают глазами. */
    public static function encode(array $manifest): string
    {
        $json = json_encode(
            $manifest,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        if ($json === false) {
            throw new \RuntimeException('Манифест не кодируется в JSON: ' . json_last_error_msg());
        }

        return $json . PHP_EOL;
    }

    /**
     * Имена уровня в стабильном порядке: объединение базы и текущего состояния,
     * суженное фильтром. Пустой фильтр не сужает.
     *
     * @param string[] $a
     * @param string[] $b
     * @param string[] $only
     *
     * @return string[]
     */
    private static function names(array $a, array $b, array $only): array
    {
        $names = array_values(array_unique(array_map('strval', array_merge($a, $b))));
        if ($only) {
            $names = array_values(array_intersect($names, $only));
        }
        sort($names, SORT_STRING);

        return $names;
    }

    /**
     * @param mixed $value список или строка «a,b,c»
     *
     * @return string[]
     */
    private static function asList($value): array
    {
        if (is_string($value)) {
            $value = $value === '' ? [] : explode(',', $value);
        }
        $out = [];
        foreach ((array)$value as $item) {
            $item = trim((string)$item);
            if ($item !== '') {
                $out[] = $item;
            }
        }

        return $out;
    }
}
