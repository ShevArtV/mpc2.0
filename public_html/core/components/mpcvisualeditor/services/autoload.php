<?php
/**
 * Автозагрузчик mpcVisualEditor.
 *
 * 1) Переиспользует composer-autoload mpc (DiDom + фасад MpcServices\Mpc) —
 *    жёсткая зависимость пакета.
 * 2) Регистрирует PSR-4 для MpcVEServices\ → services/custom/.
 */

$componentsDir = dirname(dirname(dirname(__FILE__))); // .../core/components
$mpcAutoload   = $componentsDir . '/migxpageconfigurator/services/vendor/autoload.php';
if (is_file($mpcAutoload)) {
    require_once $mpcAutoload;
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'MpcVEServices\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/custom/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});
