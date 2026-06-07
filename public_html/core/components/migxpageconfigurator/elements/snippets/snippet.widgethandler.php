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
$method = (string)($scriptProperties['method'] ?? '');
$className = (string)($scriptProperties['className'] ?? '');
unset($scriptProperties['className'], $scriptProperties['method']);
// whitelist: только классы пакета (MpcServices\…) и публичные не-_ методы —
// иначе className/method из параметров дали бы инстанс произвольного класса.
if ($className !== '' && strpos($className, 'MpcServices\\') === 0 && class_exists($className)) {
    $class = new $className($modx, $scriptProperties);
    if ($method !== '' && $method[0] !== '_' && method_exists($class, $method)) {
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