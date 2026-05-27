<?php
/**
 * Resolver: гарантирует привязку плагина MigxPageConfigurator ко ВСЕМ нужным
 * системным событиям.
 *
 * Зачем: MODX при UPGRADE обновляет КОД плагина, но НЕ до-создаёт привязки
 * modPluginEvent для НОВЫХ событий, если плагин уже существует (в build.php
 * PluginEvents пакуются с PRESERVE_KEYS=true, а pluginid на этапе сборки = 0).
 * Из-за этого, например, OnMODXInit (регистрация модели mpcType/
 * mpcTypeCollection — без неё новые типы ресурсов «не видны» в дереве) не
 * появлялся после обновления пакета. Здесь добираем недостающие привязки
 * идемпотентно — существующие не трогаем.
 *
 * Список событий держать в синхроне с _build/elements/plugins.php.
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
        $requiredEvents = [
            'OnCacheUpdate',
            'OnContextSave',
            'OnDocFormDelete',
            'OnDocFormSave',
            'OnGetFormParams',
            'OnHandleRequest',
            'OnLoadWebDocument',
            'OnMODXInit',
            'OnResourceUndelete',
            'pdoToolsOnFenomInit',
        ];

        /** @var modPlugin $plugin */
        $plugin = $modx->getObject('modPlugin', ['name' => 'MigxPageConfigurator']);
        if (!$plugin) {
            break;
        }
        $pluginId = (int)$plugin->get('id');

        foreach ($requiredEvents as $eventName) {
            // Системное событие должно существовать — иначе привязка бессмысленна
            // (pdoToolsOnFenomInit/OnGetFormParams есть только при нужных пакетах).
            if (!$modx->getCount('modEvent', ['name' => $eventName])) {
                continue;
            }
            // Уже привязано — пропускаем (идемпотентность).
            if ($modx->getObject('modPluginEvent', ['pluginid' => $pluginId, 'event' => $eventName])) {
                continue;
            }
            /** @var modPluginEvent $pe */
            $pe = $modx->newObject('modPluginEvent');
            $pe->fromArray([
                'pluginid'    => $pluginId,
                'event'       => $eventName,
                'priority'    => 0,
                'propertyset' => 0,
            ], '', true, true);
            if ($pe->save()) {
                $modx->log(modX::LOG_LEVEL_INFO, "[MPC] Привязал плагин к событию '{$eventName}'");
            }
        }
        break;

    case xPDOTransport::ACTION_UNINSTALL:
        break;
}

return true;
