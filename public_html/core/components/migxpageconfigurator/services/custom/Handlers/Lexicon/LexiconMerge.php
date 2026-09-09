<?php

namespace MpcServices\Handlers\Lexicon;

/**
 * Трёхстороннее сравнение лексиконов: снимок (что ушло в Excel) × книга (что
 * менеджер принёс) × текущее состояние файлов на сервере.
 *
 * Раньше импорт был двусторонним — «что в книге, то и записать», — и старый
 * файл откатывал ячейки, которых менеджер не касался. Здесь ячейка, не
 * изменённая относительно снимка, не пишется вовсе, а расхождение сервера и
 * книги показывается конфликтом вместо тихой перезаписи.
 *
 * Адрес записи — язык + полное имя файла лексикона (rid) + ключ. Одинаковые
 * имена ключей в разных файлах независимы, префиксы страниц к ключам не
 * добавляются.
 *
 * Различаются три разных «пусто»:
 *  - ключа нет в книге вовсе  → сервер не трогаем (отсутствие ≠ удаление);
 *  - пустая ячейка            → «не трогать» (менеджеру нечего было сказать);
 *  - литерал [[clear]]        → явная очистка значения.
 *
 * PURE — ни modX, ни файлового I/O.
 */
class LexiconMerge
{
    /** Литерал явной очистки значения в ячейке Excel. */
    public const CLEAR_LITERAL = '[[clear]]';

    /* Действия плана. */
    public const WRITE    = 'write';    // применить значение книги
    public const CLEAR    = 'clear';    // удалить ключ (явная очистка)
    public const NOOP     = 'noop';     // ничего делать не нужно
    public const SKIP     = 'skip';     // пустая ячейка — не трогаем
    public const CONFLICT = 'conflict'; // сервер и книга разошлись независимо

    /** Ячейка означает явную очистку значения. */
    public static function isClear(string $value): bool
    {
        return mb_strtolower(trim($value), 'UTF-8') === self::CLEAR_LITERAL;
    }

    /**
     * План применения книги.
     *
     * @param array $base    lang => rid => key => value|null (снимок экспорта)
     * @param array $desired lang => rid => key => value      (книга менеджера)
     * @param array $current lang => rid => key => value|null (файлы сейчас)
     * @return array<int,array{lang:string,rid:string,key:string,action:string,base:?string,current:?string,desired:?string,reason:string}>
     */
    public static function plan(array $base, array $desired, array $current): array
    {
        $ops = [];
        foreach ($desired as $lang => $byRid) {
            foreach ((array)$byRid as $rid => $kv) {
                foreach ((array)$kv as $key => $value) {
                    $ops[] = self::planEntry(
                        (string)$lang,
                        (string)$rid,
                        (string)$key,
                        self::pick($base, $lang, $rid, $key),
                        self::pick($current, $lang, $rid, $key),
                        (string)$value
                    );
                }
            }
        }
        return $ops;
    }

    /**
     * Решение по одной записи. Вынесено отдельно: тем же правилом пользуется
     * повторная сверка перед записью (значения могли уехать, пока менеджер
     * разбирал конфликты).
     *
     * @param ?string $base    значение на момент экспорта; null — ключа не было
     * @param ?string $current значение сейчас; null — ключа нет
     * @param string  $desired ячейка книги
     */
    public static function planEntry(
        string $lang,
        string $rid,
        string $key,
        ?string $base,
        ?string $current,
        string $desired
    ): array {
        $op = [
            'lang'    => $lang,
            'rid'     => $rid,
            'key'     => $key,
            'base'    => $base,
            'current' => $current,
            'desired' => $desired,
        ];

        // Пустая ячейка ничего не значит: менеджер её просто не заполнял.
        // Удаление из отсутствия значения НЕ выводится.
        if (trim($desired) === '') {
            return $op + ['action' => self::SKIP, 'reason' => 'blank-cell'];
        }

        if (self::isClear($desired)) {
            $op['desired'] = null; // явная очистка: желаемое состояние — «ключа нет»
            if ($current === null) {
                return $op + ['action' => self::NOOP, 'reason' => 'already-absent'];
            }
            if ($current === $base) {
                return $op + ['action' => self::CLEAR, 'reason' => 'explicit-clear'];
            }
            return $op + ['action' => self::CONFLICT, 'reason' => 'clear-vs-server-change'];
        }

        // Ячейка не менялась относительно выгрузки — писать нечего, даже если на
        // сервере уже другое значение (это правка менеджера, а не наша).
        if ($base !== null && $desired === $base) {
            return $op + ['action' => self::NOOP, 'reason' => 'unchanged-in-excel'];
        }

        if ($current === $desired) {
            return $op + ['action' => self::NOOP, 'reason' => 'already-applied'];
        }

        if ($base === null) {
            // Ключа не было в выгрузке: либо менеджер завёл новый, либо ключ
            // появился на сервере после экспорта (нарезка, другой импорт).
            return $current === null
                ? $op + ['action' => self::WRITE, 'reason' => 'new-key']
                : $op + ['action' => self::CONFLICT, 'reason' => 'added-on-server'];
        }

        if ($current === $base) {
            return $op + ['action' => self::WRITE, 'reason' => 'apply-change'];
        }

        return $op + ['action' => self::CONFLICT, 'reason' => 'both-changed'];
    }

    /**
     * Пересчёт решения по конфликту, выбранному менеджером. `resolution`:
     *  - 'mine'   — применить значение из книги;
     *  - 'server' — оставить серверное.
     * Значение current берётся свежим на момент применения: если сервер
     * изменился ПОСЛЕ показа конфликта, решение снова становится конфликтом,
     * а не тихой перезаписью.
     */
    public static function resolve(array $op, string $resolution, ?string $freshCurrent): array
    {
        // Что менеджер ВИДЕЛ как серверное значение, когда принимал решение.
        // Считываем ДО перезаписи current, иначе проверка устаревания ниже
        // сравнивала бы свежее значение само с собой.
        $seen = array_key_exists('seenCurrent', $op) ? $op['seenCurrent'] : ($op['current'] ?? null);
        $seen = $seen === null ? null : (string)$seen;
        $op['current'] = $freshCurrent;
        if ($resolution === 'server') {
            return ['action' => self::NOOP, 'reason' => 'kept-server'] + $op;
        }
        if ($resolution !== 'mine') {
            return ['action' => self::CONFLICT, 'reason' => 'unresolved'] + $op;
        }
        // Показывали одно, а на сервере уже другое — решение устарело.
        if ($freshCurrent !== $seen) {
            return ['action' => self::CONFLICT, 'reason' => 'stale-resolution'] + $op;
        }
        $desired = $op['desired'];
        if ($desired === null) {
            return $freshCurrent === null
                ? ['action' => self::NOOP, 'reason' => 'already-absent'] + $op
                : ['action' => self::CLEAR, 'reason' => 'resolved-clear'] + $op;
        }
        return $freshCurrent === $desired
            ? ['action' => self::NOOP, 'reason' => 'already-applied'] + $op
            : ['action' => self::WRITE, 'reason' => 'resolved-mine'] + $op;
    }

    /** Счётчики по действиям — для превью и отчёта CLI. */
    public static function summary(array $ops): array
    {
        $out = [
            self::WRITE => 0, self::CLEAR => 0, self::NOOP => 0,
            self::SKIP => 0, self::CONFLICT => 0, 'total' => count($ops),
        ];
        foreach ($ops as $op) {
            $a = (string)($op['action'] ?? '');
            if (isset($out[$a])) {
                $out[$a]++;
            }
        }
        return $out;
    }

    /** Только записи, требующие решения менеджера. */
    public static function conflicts(array $ops): array
    {
        return array_values(array_filter(
            $ops,
            static fn(array $op): bool => ($op['action'] ?? '') === self::CONFLICT
        ));
    }

    /** Значение из трёхуровневой карты; null — записи нет. */
    private static function pick(array $map, string $lang, string $rid, string $key): ?string
    {
        if (!isset($map[$lang][$rid]) || !array_key_exists($key, (array)$map[$lang][$rid])) {
            return null;
        }
        $v = $map[$lang][$rid][$key];
        return $v === null ? null : (string)$v;
    }
}
