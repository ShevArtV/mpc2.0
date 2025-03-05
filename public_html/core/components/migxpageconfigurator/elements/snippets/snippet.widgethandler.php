<?php
/**
 * @var modX $modx
 * @var array $scriptProperties
 * @var \SendIt $SendIt
 * @var string $method
 */
$corePath = $modx->getOption('core_path', '', MODX_CORE_PATH);
require_once $corePath . 'components/migxpageconfigurator/services/vendor/autoload.php';

$result = '';
$method = $scriptProperties['method'];
$className = $scriptProperties['className'];
unset($scriptProperties['className'], $scriptProperties['method']);
if ($className && class_exists($className)) {
    $class = new $className($modx, $scriptProperties);
    if (method_exists($class, $method)) {
        $result = $class->$method();
    }
}
if ($SendIt) {
    if ($result['success']) {
        return $SendIt->success($result['message'] ?? '', $result['data'] ?? []);
    } else {
        return $SendIt->error($result['message'] ?? '', $result['data'] ?? []);
    }
}
return $result;