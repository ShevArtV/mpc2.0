<?php

/**
 * Дефолтный список поля-имён, которые НЕ переводятся через лексиконы.
 * Здесь только generic-исключения, общие для любого mpc-проекта: служебные
 * поля MIGX/секций + конфиг-поля верстальщика.
 *
 * Project-specific исключения держите в **отдельном** файле и указывайте
 * путь к нему через системную настройку `mpc_exclude_lexicons_filename`.
 * Так дефолт не будет перетираться при апгрейде пакета.
 *
 * Поддерживаются точные имена и glob-паттерны (`*`, `?`). С 2.3.12-rc
 * проверяются: fieldName, fullPath (parentFieldName_fieldName) и lex-key.
 * Для row-агностических исключений — glob (`cards_*_title_*`).
 */

$excludeLexiconFields = [
    'MIGX_id',
    'MIGX_formname',
    'id',
    'section_name',
    'file_name',
    'is_static',
    'limit',
    'resources',
    'inline_styles',
    'class_names',
    'css_file_path',
];
