<?php

use CustomServices\Processors\MigxConfig;

define('MODX_API_MODE', true);
if (!defined('MODX_CORE_PATH')) {
    define('MODX_CORE_PATH', str_replace('/', '\\', dirname(__FILE__, 4)) . '/');
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
$processor = new MigxConfig($modx);
$method = $argv[2];

switch($method){
    case 'copy':
        $names = [
            'mpc_list_triple_videos' => 'mpc_list_triple_pictures',
            'mpc_list_double_videos' => 'mpc_list_double_pictures',
            'mpc_list_simple_videos' => 'mpc_list_simple_pictures',

            'mpc_list_triple_video' => 'mpc_list_triple_picture',
            'mpc_list_double_video' => 'mpc_list_double_picture',
            'mpc_list_simple_video' => 'mpc_list_simple_picture',

            'mpc_list_triple_audios' => 'mpc_list_triple_pictures',
            'mpc_list_double_audios' => 'mpc_list_double_pictures',
            'mpc_list_simple_audios' => 'mpc_list_simple_pictures',

            'mpc_list_triple_audio' => 'mpc_list_triple_picture',
            'mpc_list_double_audio' => 'mpc_list_double_picture',
            'mpc_list_simple_audio' => 'mpc_list_simple_picture',

        ];
        foreach($names as $newName => $donorName){
            $processor->copy($donorName, $newName);
        }
        break;
}


// пример команды для консоли
// php -d display_errors -d error_reporting=E_ALL public_html/core/components/migxpageconfigurator/console/manage_configs.php web copy
