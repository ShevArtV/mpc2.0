<?php
/**
 * Фронт-коннектор mpcVisualEditor.
 * Бутстрапит MODX (web-контекст) и делегирует запрос в MpcVEServices\Connector.
 *
 * Сессию НЕ стартуем вручную: session_handler_class=modSessionHandler (БД), и
 * MODX регистрирует свой хендлер в initialize()->_initSession() ПЕРЕД startSession.
 * Ручной session_start() до этого запускал файловую сессию (пустую) → юзер аноним.
 * initialize('web') поднимает ту же БД-сессию, что и страница → mgr-юзер виден.
 */

require_once dirname(dirname(dirname(__DIR__))) . '/config.core.php';
require_once MODX_CORE_PATH . 'model/modx/modx.class.php';

$modx = new modX();
$modx->initialize('web');
$modx->getService('error', 'error.modError');
$modx->getRequest();
// Топик лексикона компонента — чтобы сообщения хендлеров (mpcve_*) резолвились
// в текст, а не отдавались сырыми ключами в JSON-ответе.
$modx->getService('lexicon', 'modLexicon');
$modx->lexicon->load('mpcvisualeditor:default');

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
