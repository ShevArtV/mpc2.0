<?php

use MpcServices\Processors\MigxConfig;

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
$processor = new MigxConfig($modx);
$method = $argv[2] ?? '';

switch($method){
    case 'sync':
        // Применяет сид elements/configs/migx_configs.json к БД через
        // MigxConfigMerger (как резолвер 2migxconfigs при апгрейде): новые поля
        // из сида добавляются, кастомные из БД сохраняются. Для применения правок
        // сида на dev/сервере без переустановки пакета.
        $file = dirname(__DIR__) . '/elements/configs/migx_configs.json';
        $configs = is_file($file) ? json_decode(file_get_contents($file), true) : [];
        if (!$configs) {
            echo "no configs in seed\n";
            break;
        }
        $modx->addPackage('migx', dirname(__DIR__, 2) . '/migx/model/');
        $merger = class_exists('MpcServices\\Helpers\\MigxConfigMerger')
            ? new \MpcServices\Helpers\MigxConfigMerger()
            : null;
        $synced = 0;
        foreach ($configs as $config) {
            $existing = $modx->getObject('migxConfig', ['name' => $config['name']]);
            $row = $config;
            unset($row['id']);
            if ($existing && $merger !== null) {
                $row = $merger->merge($row, $existing->toArray());
            }
            if (!$existing) {
                $existing = $modx->newObject('migxConfig');
            }
            $existing->fromArray($row, '', true);
            if ($existing->save()) {
                $synced++;
            }
        }
        echo "synced {$synced} configs\n";
        break;
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
// php -d display_errors -d error_reporting=E_ALL public_html/core/components/migxpageconfigurator/console/mgr_configs.php web copy
