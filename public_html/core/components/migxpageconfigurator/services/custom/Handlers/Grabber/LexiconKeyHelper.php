<?php

namespace MpcServices\Handlers\Grabber;

/**
 * Чистые (PURE/STATIC) утилиты построения лексикон-ключей — ЕДИНЫЙ источник
 * формата для грабера И редактора, чтобы ключи не разъезжались. Вынесено из
 * LexiconManager (God-класс): здесь нет состояния и I/O, только формат ключа.
 * LexiconManager сохраняет тонкие делегаты для обратной совместимости вызовов
 * LexiconManager::getLexiconKey/appendLexiconParent/getLexiconKeyForPath.
 *
 * @author Arthur Shevchenko (https://t.me/ShevArtV)
 */
class LexiconKeyHelper
{
    /**
     * Формат: {prefix}_{parentFieldName}_{fieldName} либо {prefix}_{fieldName};
     * idx добавляется суффиксом ТОЛЬКО если он не пустой/не 0 (idx=0 → без суффикса).
     */
    public static function getLexiconKey(array $options): string
    {
        $fieldName       = $options['fieldName'] ?? '';
        $idx             = $options['idx'] ?? '';
        $parentFieldName = $options['parentFieldName'] ?? '';
        $prefix          = $options['prefix'] ?? '';

        $lexiconKey = $parentFieldName
            ? "{$prefix}_{$parentFieldName}_$fieldName"
            : "{$prefix}_$fieldName";

        return $idx ? "{$lexiconKey}_$idx" : $lexiconKey;
    }

    /**
     * ЕДИНЫЙ источник КОНСТРУКЦИИ parentFieldName: добавляет один сегмент цепочки.
     * Вложенный список вносит "{field}" (idx 0) либо "{field}_{idx}" (idx > 0).
     * Грабер зовёт инкрементально при рекурсии, редактор — сворачивая путь.
     */
    public static function appendLexiconParent(string $parent, string $field, int $idx): string
    {
        $segment = $idx ? "{$field}_{$idx}" : $field;
        return $parent !== '' ? "{$parent}_{$segment}" : $segment;
    }

    /**
     * Полный лексикон-ключ поля по ПУТИ строк [{field,idx},…] + имя поля. Сворачивает
     * путь через appendLexiconParent (parentFieldName) и форматирует листом через
     * getLexiconKey (idx листа = idx последнего сегмента). Пустой путь → top-level
     * поле ({prefix}_{field}). Та же схема, что грабер строит при нарезке.
     */
    public static function getLexiconKeyForPath(string $prefix, array $path, string $fieldName): string
    {
        if ($prefix === '' || $fieldName === '') {
            return '';
        }
        $parent  = '';
        $leafIdx = 0;
        foreach ($path as $seg) {
            $field = (string)($seg['field'] ?? '');
            if ($field === '') {
                continue;
            }
            $leafIdx = (int)($seg['idx'] ?? 0);
            $parent  = self::appendLexiconParent($parent, $field, $leafIdx);
        }
        return self::getLexiconKey([
            'prefix'          => $prefix,
            'parentFieldName' => $parent,
            'fieldName'       => $fieldName,
            'idx'             => $leafIdx,
        ]);
    }
}
