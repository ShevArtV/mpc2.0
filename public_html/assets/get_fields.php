<?php

define('MODX_API_MODE', true);
require_once dirname(dirname(__FILE__)) . '/index.php';

$modx->getService('error', 'error.modError');
$modx->getRequest();
$modx->setLogLevel(modX::LOG_LEVEL_ERROR);
$modx->setLogTarget('FILE');
$modx->error->message = null;

$modx->addPackage('migx', MODX_CORE_PATH . 'components/migx/model/');

function getFields($configName)
{
    global $modx;
    $config = $modx->getObject('migxConfig', ['name' => $configName]);
    $formtabs = json_decode($config->get('formtabs'), true);
    $fields = [];
    foreach ($formtabs as $t) {
        foreach ($t['fields'] as $f) {
            $fields[$f['field']] = $f['caption'];
        }
    }
    return $fields;
}

$q = $modx->newQuery('migxConfig');
$q->select('name');
$q->where([
    'name:LIKE' => 'mpc_%'
]);
$q->prepare();
$q->stmt->execute();
$output = $q->stmt->fetchAll(\PDO::FETCH_COLUMN);
$configFields = [];
$fields = [];
foreach ($output as $configName) {
    $configFields[$configName] = getFields($configName);
}
foreach ($configFields['mpc_base'] as $key => $caption) {
    $k = 'mpc_' . $key;
    if (isset($configFields[$k])) {
        $fields[$key][] = $caption;
        $fields[$key][] = $configFields[$k];
    }else{
        $fields[$key] = $caption;
    }
}
$modx->log(1, print_r($fields, 1));
$path = MODX_CORE_PATH . 'components/migxpageconfigurator/examples/fields.json';
file_put_contents($path, json_encode($fields, JSON_UNESCAPED_UNICODE));
echo 'Fields getted';



// пример команды для консоли
// php -d display_errors -d error_reporting=E_ALL public_html/assets/get_fields.php
