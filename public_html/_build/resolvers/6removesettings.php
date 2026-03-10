<?php
/**
 * Resolver: remove deprecated system settings that are no longer used in v2.
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
        $deprecated = [
            'mpc_image_extensions',
            'mpc_service_info_tv_name',
        ];

        foreach ($deprecated as $key) {
            $setting = $modx->getObject('modSystemSetting', ['key' => $key]);
            if ($setting) {
                $setting->remove();
                $modx->log(modX::LOG_LEVEL_INFO, "Removed deprecated setting: $key");
            }
        }
        break;

    case xPDOTransport::ACTION_UNINSTALL:
        break;
}

return true;
