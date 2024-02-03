<?php
define('MODX_API_MODE', true);
if (!defined('MODX_CORE_PATH')) {
    define('MODX_CORE_PATH', dirname(__FILE__, 4) . '/');
}
if (!defined('MODX_CONFIG_KEY')) {
    define('MODX_CONFIG_KEY', 'config');
}
require_once( MODX_CORE_PATH . 'model/modx/modx.class.php');
require_once MODX_CORE_PATH . 'components/migxpageconfigurator/services/mpcide.class.php';
$ctx = $argv[1] ?? 'web';
$modx = new modX();
$modx->initialize($ctx);
$modx->getService('error', 'error.modError');
$modx->getRequest();
$modx->setLogLevel(modX::LOG_LEVEL_ERROR);
$modx->setLogTarget('FILE');
$modx->error->message = null;

$fileName = $argv[2];
$updContent = $argv[3];

$mpcIde = new MpcIde($modx);
$mpcIde->process($fileName, $updContent);

// пример команды для консоли
// php -d display_errors -d error_reporting=E_ALL public_html/core/components/migxpageconfigurator/console/new_mgr_tpl.php web landing.tpl 1
// /usr/local/php/php-7.4/bin/php -d display_errors -d error_reporting=E_ALL /home/host1860015/art-sites.ru/htdocs/customfactory/core/components/migxpageconfigurator/console/new_mgr_tpl.php web landing.tpl 1
