<?php
/**
 * Фронт-коннектор mpcVisualEditor.
 * Бутстрапит MODX (web-контекст) и делегирует запрос в MpcVEServices\Connector.
 *
 * ВАЖНО (проверить на сайте): аутентификация mgr-пользователя на web-контексте
 * для проверки прав. Возможно потребуется initialize('mgr') либо общая сессия
 * между контекстами. Помечено для M7.
 */

@session_start();

require_once dirname(dirname(dirname(__DIR__))) . '/config.core.php';
require_once MODX_CORE_PATH . 'model/modx/modx.class.php';

$modx = new modX();
$modx->initialize('web');
$modx->getService('error', 'error.modError');
$modx->getRequest();

header('Content-Type: application/json; charset=UTF-8');

$corePath = $modx->getOption('core_path', null, MODX_CORE_PATH) . 'components/mpcvisualeditor/';
$autoload = $corePath . 'services/autoload.php';
if (!is_file($autoload)) {
    echo json_encode(['success' => false, 'message' => 'mpcVisualEditor is not installed correctly']);
    exit;
}
require_once $autoload;

$connector = new \MpcVEServices\Connector($modx);
$request = array_merge($_GET, $_POST);
echo json_encode($connector->handle($request), JSON_UNESCAPED_UNICODE);
