<?php
define('MODX_API_MODE', true);
if (!defined('MODX_CORE_PATH')) {
    define('MODX_CORE_PATH', dirname(__FILE__, 4) . '/');
}
if (!defined('MODX_CONFIG_KEY')) {
    define('MODX_CONFIG_KEY', 'config');
}
require_once( MODX_CORE_PATH . 'model/modx/modx.class.php');
require_once MODX_CORE_PATH . 'components/migxpageconfigurator/model/migxpageconfigurator/migxpageconfigurator.class.php';
$ctx = $argv[1] ?? 'web';
$modx = new modX();
$modx->initialize($ctx);
$modx->getService('error', 'error.modError');
$modx->getRequest();
$modx->setLogLevel(modX::LOG_LEVEL_ERROR);
$modx->setLogTarget('FILE');
$modx->error->message = null;

$file_names = $argv[2];
$upd_content = $argv[3];

$mpc = new MigxPageConfigurator($modx);

$mpc->manageTemplates($file_names, $upd_content);

// пример команды для консоли
// php -d display_errors -d error_reporting=E_ALL public_html/core/components/migxpageconfigurator/console/mgr_tpl.php web index.tpl 1
