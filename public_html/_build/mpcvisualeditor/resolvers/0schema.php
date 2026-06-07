<?php
/**
 * Resolver: создаёт таблицу модели mpcVE (mpcve_changelog для mpcveChangeLog).
 *
 * Нужно и на INSTALL, и на UPGRADE: до появления модели таблица создавалась
 * лениво в рантайме (Handlers/ChangeLog, raw PDO). createObjectContainer
 * идемпотентен — существующую таблицу с данными не трогает.
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
        $modx->addPackage('mpcvisualeditor', MODX_CORE_PATH . 'components/mpcvisualeditor/model/');
        $manager = $modx->getManager();
        $manager->createObjectContainer('mpcveChangeLog');
        $modx->log(modX::LOG_LEVEL_INFO, '[mpcVE] Контейнер mpcveChangeLog (таблица mpcve_changelog) создан/проверен');
        break;

    case xPDOTransport::ACTION_UNINSTALL:
        break;
}

return true;
