<?php
/**
 * Resolver: создаёт таблицы модели пакета (mpc_type для класса mpcTypeData).
 *
 * Нужно и на INSTALL, и на UPGRADE: при обновлении с версии, где модели mpcType
 * ещё не было, таблицы mpc_type на сайте нет, а больше её пакет нигде не создаёт
 * (build.php только генерит классы из схемы, контейнер не создаёт). Без этого
 * mpc_type на проде не появлялась. createObjectContainer идемпотентен — если
 * таблица уже есть, существующие данные не трогает.
 *
 * mpcType / mpcTypeCollection наследуют modResource (своих таблиц нет) — нужен
 * только контейнер для mpcTypeData.
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
        $modx->addPackage('migxpageconfigurator', MODX_CORE_PATH . 'components/migxpageconfigurator/model/');
        $manager = $modx->getManager();
        $manager->createObjectContainer('mpcTypeData');
        $modx->log(modX::LOG_LEVEL_INFO, '[MPC] Контейнер mpcTypeData (таблица mpc_type) создан/проверен');
        break;

    case xPDOTransport::ACTION_UNINSTALL:
        break;
}

return true;
