<?php
/**
 * Общий плагин mpcVisualEditor для системных событий.
 * Диспатчит событие в класс MpcVEServices\Plugins\<EventName>.
 *
 * @var \modX $modx
 * @var array $scriptProperties
 */

$corePath = $modx->getOption('core_path', null, MODX_CORE_PATH) . 'components/mpcvisualeditor/';
$autoload = $corePath . 'services/autoload.php';
if (!is_file($autoload)) {
    return;
}
require_once $autoload;

$className = 'MpcVEServices\\Plugins\\' . $modx->event->name;
if (class_exists($className)) {
    $handler = new $className($modx, $scriptProperties);
    $handler->run();
}
