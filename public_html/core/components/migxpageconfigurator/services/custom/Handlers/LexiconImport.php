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
 * PURE/STATIC — без modX и файлового I/O, юнит-тестируемо.
 */
class LexiconImport
{
    /** Заголовки колонки ключа (lowercase). */
    private const KEY_HEADERS = ['lexicon_key', 'key', 'ключ'];
    /** Заголовки справочной колонки контекста (lowercase) — игнорируются. */
    private const CONTEXT_HEADERS = ['контекст', 'context'];

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
     * загружаемого файла. Совпало с существующим rid → он; «Static» → static-файл;
     * «Resource»/нераспознанное → null (ручной ремап в превью). PURE.
     *
     * @param string[] $existingRids существующие rid (basenames без .inc.php)
     */
    public static function resolveTarget(string $sheetName, array $existingRids, string $staticFile): ?string
    {
        $ridSet = array_flip($existingRids);
        if (isset($ridSet[$sheetName])) {
            return $sheetName;
        }
        if ($sheetName === 'Static') {
            return $staticFile;
        }
        return null; // 'Resource' и любые неузнанные — ручной выбор
    }
}
