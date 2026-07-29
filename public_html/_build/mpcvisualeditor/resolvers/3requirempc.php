<?php
/**
 * Resolver: проверка версии migxpageconfigurator.
 *
 * С 1.0.20 редактор считает имена файлов и папок ЕДИНОЙ точкой mpc
 * (MpcServices\Handlers\Support\FileName, появилась в 2.5.56) — там же живёт
 * событие mpcOnSanitizeFileName. Со старым mpc классы просто нет кому загрузить,
 * и загрузка файла в редакторе упала бы с fatal вместо понятного сообщения.
 *
 * Установку НЕ блокируем: пакеты нередко ставят парой, и порядок в менеджере
 * пакетов не гарантирован — обновление mpc следом само чинит ситуацию. Задача
 * резолвера — чтобы причина была в логе установки, а не искалась по трассировке.
 *
 * @var xPDOTransport $transport
 * @var array $options
 * @var modX $modx
 */

if (!$transport->xpdo) {
    return true;
}

$modx =& $transport->xpdo;

switch ($options[xPDOTransport::PACKAGE_ACTION]) {
    case xPDOTransport::ACTION_INSTALL:
    case xPDOTransport::ACTION_UPGRADE:
        $required = MODX_CORE_PATH . 'components/migxpageconfigurator/services/custom/Handlers/Support/FileName.php';
        if (!is_file($required)) {
            $modx->log(
                modX::LOG_LEVEL_ERROR,
                '[mpcVisualEditor] Требуется migxpageconfigurator 2.5.56 или новее: не найден '
                . 'MpcServices\Handlers\Support\FileName. До обновления mpc загрузка файлов в '
                . 'редакторе работать не будет.'
            );
        }
        break;
}

return true;
