<?php

use MpcServices\Mpc;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

define('MODX_API_MODE', true);
if (!defined('MODX_CORE_PATH')) {
    define('MODX_CORE_PATH', dirname(__FILE__, 4) . '/');
}
if (!defined('MODX_CONFIG_KEY')) {
    define('MODX_CONFIG_KEY', 'config');
}
require_once MODX_CORE_PATH . 'model/modx/modx.class.php';
require_once MODX_CORE_PATH . 'components/migxpageconfigurator/services/vendor/autoload.php';
$ctx = $argv[1] ?? 'web';
$modx = new modX();
$modx->initialize($ctx);
$modx->getService('error', 'error.modError');
$modx->getRequest();
$modx->setLogLevel(modX::LOG_LEVEL_ERROR);
$modx->setLogTarget('FILE');
$modx->error->message = null;

$target = $argv[2] ?? '';
$fileName = $target === 'all' ? null : $target;
$updContent = $argv[3] ?? '';

$mpc = new Mpc($modx);
$mpc->process($fileName, $updContent);

// пример команды для консоли
// php -d display_errors -d error_reporting=E_ALL public_html/core/components/migxpageconfigurator/console/mgr_tpl.php web wrapper.tpl 1
// php -d display_errors -d error_reporting=E_ALL public_html/core/components/migxpageconfigurator/console/mgr_tpl.php web examples.tpl 1
