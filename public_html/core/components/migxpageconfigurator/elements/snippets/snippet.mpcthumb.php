<?php

/**
 * Обёртка над pThumb с проверкой существования файла.
 * Если файл не найден — возвращает пустую строку вместо ошибки в лог.
 *
 * @author Arthur Shevchenko (https://t.me/ShevArtV)
 * @var \modX $modx
 * @var array $scriptProperties
 */
$input = $modx->getOption('input', $scriptProperties, '');

if (!$input) {
    return '';
}

$filePath = MODX_BASE_PATH . ltrim($input, '/');
if (!file_exists($filePath)) {
    return $input;
}

return $modx->runSnippet('pThumb', $scriptProperties);
