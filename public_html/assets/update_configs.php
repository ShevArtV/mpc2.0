<?php
define('MODX_API_MODE', true);
require_once dirname(dirname(__FILE__)) . '/index.php';

$modx->getService('error', 'error.modError');
$modx->getRequest();
$modx->setLogLevel(modX::LOG_LEVEL_ERROR);
$modx->setLogTarget('FILE');
$modx->error->message = null;

$modx->addPackage('migx', MODX_CORE_PATH . 'components/migx/model/');
$path = MODX_CORE_PATH . 'components/migxpageconfigurator/elements/configs/migx_configs.json';
$q = $modx->newQuery('migxConfig');
$q->select($modx->getSelectColumns('migxConfig', 'migxConfig'));
$q->where([
    'name:LIKE' => 'mpc_%'
]);
$q->prepare();
$q->stmt->execute();
$output = $q->stmt->fetchAll(\PDO::FETCH_ASSOC);
print_r($output);
file_put_contents($path, json_encode($output, JSON_UNESCAPED_UNICODE));
echo 'Update configs complete';



// пример команды для консоли
// php -d display_errors -d error_reporting=E_ALL public_html/assets/update_configs.php
