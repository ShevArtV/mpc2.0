<?php
/**
 * Resolver: remove legacy lexicon import/export dashboard widgets.
 * Runs on both install and upgrade to clean up widgets that no longer exist.
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
        $widgetNames = ['mpcExportLexicons', 'mpcImportLexicons'];

        foreach ($widgetNames as $name) {
            /** @var modDashboardWidget $widget */
            $widget = $modx->getObject('modDashboardWidget', ['name' => $name]);
            if (!$widget) {
                continue;
            }

            $widgetId = $widget->get('id');

            // Remove all dashboard placements for this widget
            $placements = $modx->getCollection('modDashboardWidgetPlacement', ['widget' => $widgetId]);
            foreach ($placements as $placement) {
                $placement->remove();
            }

            $widget->remove();
            $modx->log(modX::LOG_LEVEL_INFO, 'Removed dashboard widget: ' . $name);
        }
        break;

    case xPDOTransport::ACTION_UNINSTALL:
        break;
}

return true;
