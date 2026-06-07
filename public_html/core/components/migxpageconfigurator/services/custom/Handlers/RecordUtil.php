<?php

namespace MpcServices\Handlers;

/**
 * Чистые утилиты для migx-записей (массив строк-объектов `[{...}]`). Вынесено из
 * FieldWriter, чтобы общими decode/проверкой пользовались и FieldWriter, и
 * MediaLexiconMerger без дублирования/делегатов.
 *
 * @author Arthur Shevchenko (https://t.me/ShevArtV)
 */
class RecordUtil
{
    /** Значение — migx-запись (непустой массив, первый элемент — массив)? */
    public static function isRecordValue($v): bool
    {
        $d = is_string($v) ? json_decode($v, true) : $v;
        if (!is_array($d) || $d === []) {
            return false;
        }
        return is_array(reset($d));
    }

    /** JSON-строка (или массив) записи → массив строк; не-массив → []. */
    public static function decodeRecord($v): array
    {
        $d = is_string($v) ? json_decode($v, true) : $v;
        return is_array($d) ? $d : [];
    }
}
