<?php

namespace MpcServices\Handlers;

/**
 * Чистая логика импорта лексиконов из XLSX: разбор заголовков ПО ИМЕНАМ (не по
 * позиции), нормализация листа в структуру key→lang→value, детект целевого
 * ресурса по ИМЕНИ ВКЛАДКИ (не по имени файла), подсчёт diff для превью.
 *
 * Формат all-in-one: колонки `Контекст | lexicon_key | <язык> …`, вкладка-на-
 * ресурс (имя вкладки = identifier лексикон-файла). Колонка «Контекст» —
 * справочная, на импорт НЕ влияет. Старый формат (lexicon_key первой колонкой,
 * листы Resource/Static) тоже разбирается — ключевая колонка ищется по имени.
 *
 * Excel держит в имени листа не больше 31 символа, а rid (alias ресурса) бывает
 * длиннее и с запрещёнными символами. Поэтому имя вкладки считает ОДНА функция
 * sheetNameFor() (обе стороны — экспорт и импорт), а полное соответствие
 * «вкладка → rid» дублируется в скрытом листе-манифесте `__mpc`.
 *
 * PURE/STATIC — без modX и файлового I/O, юнит-тестируемо.
 */
class LexiconImport
{
    /** Заголовки колонки ключа (lowercase). */
    private const KEY_HEADERS = ['lexicon_key', 'key', 'ключ'];
    /** Заголовки справочной колонки контекста (lowercase) — игнорируются. */
    private const CONTEXT_HEADERS = ['контекст', 'context'];

    /** Имя служебного (скрытого) листа-манифеста «вкладка → файл лексикона». */
    public const MANIFEST_SHEET = '__mpc';
    /** Жёсткий лимит Excel на длину имени листа. */
    private const SHEET_NAME_LIMIT = 31;
    /** Длина hex-хвоста sha1 в укороченном имени листа. */
    private const SHEET_HASH_LEN = 7;
    /** Запрещённые Excel символы в имени листа. */
    private const SHEET_FORBIDDEN = '#[:\\\\/?*\[\]]+#';

    /**
     * Имя вкладки для файла лексикона — ЧИСТАЯ функция от rid (одно правило на
     * экспорт и импорт). Помещается в 31 символ Excel и не теряет однозначность:
     * если rid влезает как есть — берём его; иначе префикс + `~` + sha1-хвост,
     * поэтому алиасы с общими первыми 31 символами дают РАЗНЫЕ вкладки.
     */
    public static function sheetNameFor(string $rid): string
    {
        $clean = self::sanitizeSheetName($rid);
        if ($clean === $rid && $rid !== '' && mb_strlen($rid, 'UTF-8') <= self::SHEET_NAME_LIMIT) {
            return $rid;
        }
        if ($clean === '') {
            $clean = 'sheet';
        }
        $prefixLen = self::SHEET_NAME_LIMIT - 1 - self::SHEET_HASH_LEN;
        return mb_substr($clean, 0, $prefixLen, 'UTF-8')
            . '~' . substr(sha1($rid), 0, self::SHEET_HASH_LEN);
    }

    /** Замена запрещённых Excel символов; БЕЗ обрезки по длине. */
    private static function sanitizeSheetName(string $rid): string
    {
        return trim((string)preg_replace(self::SHEET_FORBIDDEN, '_', $rid));
    }

    /**
     * Разбор листа-манифеста (`__mpc`, колонки `sheet | rid`) в карту
     * «имя вкладки (lowercase) → rid». Не манифест / нет колонок → []. PURE.
     */
    public static function parseManifest(array $headers, array $rows): array
    {
        $sheetIdx = null;
        $ridIdx   = null;
        foreach ($headers as $i => $h) {
            $hl = mb_strtolower(trim((string)$h), 'UTF-8');
            if ($hl === 'sheet' && $sheetIdx === null) {
                $sheetIdx = (int)$i;
            } elseif ($hl === 'rid' && $ridIdx === null) {
                $ridIdx = (int)$i;
            }
        }
        if ($sheetIdx === null || $ridIdx === null) {
            return [];
        }

        $map = [];
        foreach ($rows as $r) {
            $sheet = trim((string)($r[$sheetIdx] ?? ''));
            $rid   = trim((string)($r[$ridIdx] ?? ''));
            if ($sheet === '' || $rid === '') {
                continue;
            }
            $map[mb_strtolower($sheet, 'UTF-8')] = $rid;
        }
        return $map;
    }

    /**
     * Разбор строки заголовков → позиция колонки ключа и колонки языков.
     * @return array{keyIdx:?int, langCols:array<int,string>} langCols: idx=>langCode
     */
    public static function parseHeaders(array $headers): array
    {
        $keyIdx   = null;
        $langCols = [];
        foreach ($headers as $i => $h) {
            $h  = (string)$h;
            $hl = mb_strtolower(trim($h), 'UTF-8');
            if ($hl === '') {
                continue;
            }
            if (in_array($hl, self::KEY_HEADERS, true)) {
                if ($keyIdx === null) {
                    $keyIdx = (int)$i;
                }
                continue;
            }
            if (in_array($hl, self::CONTEXT_HEADERS, true)) {
                continue;
            }
            $langCols[(int)$i] = trim($h);
        }
        return ['keyIdx' => $keyIdx, 'langCols' => $langCols];
    }

    /**
     * Нормализация листа (заголовки + строки) в структуру.
     * @return array{langs:string[], data:array<string,array<string,string>>}
     */
    public static function sheetToData(array $headers, array $rows): array
    {
        $h = self::parseHeaders($headers);
        if ($h['keyIdx'] === null) {
            return ['langs' => [], 'data' => []];
        }
        $keyIdx   = $h['keyIdx'];
        $langCols = $h['langCols'];

        $data = [];
        foreach ($rows as $r) {
            $key = trim((string)($r[$keyIdx] ?? ''));
            if ($key === '') {
                continue;
            }
            foreach ($langCols as $idx => $lang) {
                $data[$key][$lang] = (string)($r[$idx] ?? '');
            }
        }
        return ['langs' => array_values($langCols), 'data' => $data];
    }

    /**
     * Подсчёт diff входных данных против существующих лексиконов.
     * Ключ «новый» — если его нет НИ в одном существующем языке; «изменённый» —
     * есть, но хотя бы одно непустое входное значение отличается. PURE.
     *
     * @param array $data           key=>lang=>value (входные)
     * @param array $existingByLang lang=>(key=>value) (текущие файлы)
     * @return array{keys:int, new:int, changed:int}
     */
    public static function computeDiff(array $data, array $existingByLang): array
    {
        $new = 0;
        $changed = 0;
        foreach ($data as $key => $langVals) {
            $existsAnywhere = false;
            $isChanged      = false;
            foreach ($langVals as $lang => $val) {
                $ex = $existingByLang[$lang][$key] ?? null;
                if ($ex !== null && $ex !== '') {
                    $existsAnywhere = true;
                    if (trim((string)$val) !== '' && (string)$ex !== (string)$val) {
                        $isChanged = true;
                    }
                }
            }
            if (!$existsAnywhere) {
                $new++;
            } elseif ($isChanged) {
                $changed++;
            }
        }
        return ['keys' => count($data), 'new' => $new, 'changed' => $changed];
    }

    /**
     * Целевой ресурс (rid лексикон-файла) по имени ВКЛАДКИ — БЕЗ опоры на имя
     * загружаемого файла. Порядок: манифест (точный источник истины) → точное
     * совпадение с rid → «Static» → имя, посчитанное тем же sheetNameFor() →
     * старая выгрузка (простая обрезка до 31). Ничего не подошло → null
     * («Resource» и любые неузнанные идут в ручной ремап в превью). PURE.
     *
     * @param string[] $existingRids существующие rid (basenames без .inc.php)
     * @param ?string  $manifestRid  rid из листа-манифеста для этой вкладки
     */
    public static function resolveTarget(
        string $sheetName,
        array $existingRids,
        string $staticFile,
        ?string $manifestRid = null
    ): ?string {
        $ridSet = array_flip($existingRids);

        // Манифест уважаем только если файл лексикона на месте: ресурс могли
        // удалить/переименовать после экспорта — тогда пусть решает человек.
        if ($manifestRid !== null && $manifestRid !== '' && isset($ridSet[$manifestRid])) {
            return $manifestRid;
        }
        if (isset($ridSet[$sheetName])) {
            return $sheetName;
        }
        if ($sheetName === 'Static') {
            return $staticFile;
        }

        // Длинный/«грязный» rid: вкладка названа по sheetNameFor() — считаем то
        // же самое для кандидатов (Excel сравнивает имена без учёта регистра).
        $needle = mb_strtolower($sheetName, 'UTF-8');
        foreach ($existingRids as $rid) {
            if (mb_strtolower(self::sheetNameFor((string)$rid), 'UTF-8') === $needle) {
                return (string)$rid;
            }
        }

        return self::resolveLegacyTruncated($needle, $existingRids);
    }

    /**
     * Выгрузки до появления хеш-суффикса: имя вкладки = очищенный rid, тупо
     * обрезанный до 31 символа. Восстанавливаем по префиксу и ТОЛЬКО если
     * кандидат один — иначе (общий префикс у двух алиасов) это гадание.
     */
    private static function resolveLegacyTruncated(string $needleLower, array $existingRids): ?string
    {
        if (mb_strlen($needleLower, 'UTF-8') !== self::SHEET_NAME_LIMIT) {
            return null; // обрезка проявлялась только на пределе длины
        }
        $hits = [];
        foreach ($existingRids as $rid) {
            $clean = mb_strtolower(self::sanitizeSheetName((string)$rid), 'UTF-8');
            if (mb_substr($clean, 0, self::SHEET_NAME_LIMIT, 'UTF-8') === $needleLower) {
                $hits[] = (string)$rid;
            }
        }
        return count($hits) === 1 ? $hits[0] : null;
    }
}
