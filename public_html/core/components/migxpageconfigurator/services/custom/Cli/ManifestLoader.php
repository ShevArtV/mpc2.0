<?php

namespace MpcServices\Cli;

/**
 * Загрузка проектного манифеста (PHP-файл, возвращающий массив). Доверенный
 * источник (исполняемый файл проекта). Валидирует, что файл существует и вернул
 * массив; даёт понятную ошибку иначе.
 */
class ManifestLoader
{
    /**
     * @throws \RuntimeException если файла нет / вернул не массив
     */
    public static function load(string $path): array
    {
        if ($path === '' || !is_file($path)) {
            throw new \RuntimeException('Манифест не найден: ' . $path);
        }
        $data = require $path;
        if (!is_array($data)) {
            throw new \RuntimeException('Манифест должен возвращать массив (return [...]): ' . $path);
        }
        return $data;
    }
}
