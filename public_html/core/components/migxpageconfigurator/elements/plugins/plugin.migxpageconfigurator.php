<?php
/**
 * Общий плагин для всех системных событий.
 */

/**
 * @author Arthur Shevchenko (https://t.me/ShevArtV)
 * @var \modX $modx
 * @var array $scriptProperties
 */

$basePath = $modx->getOption('base_path', null, $_SERVER['DOCUMENT_ROOT'] . '/');
if(!file_exists($basePath . 'core/components/migxpageconfigurator/services/vendor/autoload.php')){
    return true;
}
require_once $basePath . 'core/components/migxpageconfigurator/services/vendor/autoload.php';
$className = 'MpcServices\\Plugins\\' . $modx->event->name;
if (class_exists($className)) {
    $pluginHandler = new $className($modx, $scriptProperties);
    $pluginHandler->run();
}