<?php

namespace MpcServices\Cli;

/**
 * Загрузка проектного манифеста (PHP-файл, возвращающий массив) и резолвинг его
 * пути. Доверенный источник (исполняемый файл проекта). Валидирует, что файл
 * существует и вернул массив; даёт понятную ошибку иначе.
 *
 * Резолвинг (resolve/resolvePath) позволяет не передавать путь целиком:
 *   mpc settings apply           → {base}/settings.php   (дефолт по имени группы)
 *   mpc settings apply prod      → {base}/prod.php        (профиль/окружение)
 *   mpc settings apply /abs.php  → как есть               (совместимость)
 * где {base} — mpc_manifests_path (env MPC_MANIFESTS_PATH > системная настройка
 * > дефолт). Относительный путь резолвится от папки core/ MODX.
 */
class ManifestLoader
{
    /** Дефолтная база (относительно core/): папка console/manifests/ компонента. */
    private const DEFAULT_BASE = 'components/migxpageconfigurator/console/manifests/';

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

    /** Полный путь манифеста для группы с учётом базы из настроек/env. */
    public static function resolve(\modX $modx, string $group, string $arg): string
    {
        return self::resolvePath(self::baseDir($modx), $group, $arg);
    }

    /**
     * База манифестов: env MPC_MANIFESTS_PATH > системная настройка
     * mpc_manifests_path > дефолт. Относительный путь резолвится от папки core/.
     */
    public static function baseDir(\modX $modx): string
    {
        $coreRoot = rtrim((string)$modx->getOption('core_path'), '/\\') . '/';

        $base = getenv('MPC_MANIFESTS_PATH');
        if ($base === false || $base === '') {
            $base = (string)$modx->getOption('mpc_manifests_path', null, '');
        }
        if ($base === '') {
            $base = self::DEFAULT_BASE;
        }
        if (!self::isAbsolute($base)) {
            $base = $coreRoot . $base;
        }
        return rtrim($base, '/\\') . '/';
    }

    /**
     * PURE: собрать путь манифеста из базы, имени группы и аргумента.
     * Тестируется без $modx.
     *
     * @param string $base база (с завершающим слэшем)
     * @param string $group имя группы (дефолтное имя файла)
     * @param string $arg позиционный аргумент: пусто | имя/профиль | путь к файлу
     */
    public static function resolvePath(string $base, string $group, string $arg): string
    {
        $arg = trim($arg);

        // Явный путь к существующему файлу (абсолют или относительно cwd) —
        // отдаём как есть: обратная совместимость со старыми вызовами apply <файл>.
        if ($arg !== '' && (strpbrk($arg, '/\\') !== false || self::isAbsolute($arg)) && is_file($arg)) {
            return $arg;
        }

        $name = $arg !== '' ? $arg : $group;
        // Имя/профиль резолвится относительно базы — запрещаем '..', чтобы оно
        // не вылезло за пределы каталога манифестов. (Явный путь к существующему
        // файлу выше — by design доверенный режим, CLI запускает разработчик.)
        if (strpos($name, '..') !== false) {
            throw new \RuntimeException('Недопустимое имя манифеста (..): ' . $name);
        }
        if (!preg_match('/\.php$/i', $name)) {
            $name .= '.php';
        }
        // Если в имени уже есть путь к существующему файлу — не префиксуем базой.
        if (self::isAbsolute($name) || is_file($name)) {
            return $name;
        }
        return rtrim($base, '/\\') . '/' . $name;
    }

    private static function isAbsolute(string $path): bool
    {
        return $path !== '' && ($path[0] === '/' || $path[0] === '\\'
            || (bool)preg_match('#^[A-Za-z]:[\\\\/]#', $path));
    }
}
