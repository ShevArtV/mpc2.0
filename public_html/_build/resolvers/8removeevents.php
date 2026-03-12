<?php
/**
 * Resolver: remove OnManagerPageInit event binding from MigxPageConfigurator plugin.
 * Runs on upgrade to clean up the event that is no longer needed.
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
        $eventsToRemove = ['OnManagerPageInit'];

        /** @var modPlugin $plugin */
        $plugin = $modx->getObject('modPlugin', ['name' => 'MigxPageConfigurator']);
        if (!$plugin) {
            break;
        }

        foreach ($eventsToRemove as $eventName) {
            /** @var modPluginEvent $event */
            $event = $modx->getObject('modPluginEvent', [
                'pluginid' => $plugin->get('id'),
                'event'    => $eventName,
            ]);
            if ($event) {
                $event->remove();
                $modx->log(modX::LOG_LEVEL_INFO, "Removed '{$eventName}' event from MigxPageConfigurator plugin");
            }
        }
        break;

    case xPDOTransport::ACTION_UNINSTALL:
        break;
}

return true;
