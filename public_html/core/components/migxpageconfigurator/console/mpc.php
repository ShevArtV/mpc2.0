<?php
/**
 * mpc CLI — точка входа. Бутстрап MODX в API-режиме + sudo-пользователь
 * (нужен core-процессорам ресурсов), затем диспетчер MpcServices\Cli\Cli.
 *
 * Запуск (на сервере подходящей версией PHP):
 *   php core/components/migxpageconfigurator/console/mpc.php <группа> apply <файл> [флаги]
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "mpc.php запускается только из CLI\n");
    exit(1);
}

define('MODX_API_MODE', true);
if (!defined('MODX_CORE_PATH')) {
    define('MODX_CORE_PATH', dirname(__FILE__, 4) . '/');
}
require_once MODX_CORE_PATH . 'model/modx/modx.class.php';
require_once MODX_CORE_PATH . 'components/migxpageconfigurator/services/vendor/autoload.php';

$modx = new modX();
$modx->initialize('mgr');
$modx->getService('error', 'error.modError');
$modx->setLogLevel(modX::LOG_LEVEL_ERROR);
$modx->setLogTarget('ECHO');

// sudo-пользователь: core-процессоры (resource/create|update) проверяют права.
$user = $modx->getObject('modUser', ['sudo' => 1]);
if (!$user) {
    $user = $modx->getObject('modUser', ['active' => 1]);
}
if ($user) {
    $modx->user = $user;
}

$cli = new \MpcServices\Cli\Cli($modx);
exit($cli->run($argv));
