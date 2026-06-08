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
            'mpc_copy_config_tv_name',
            'mpc_images_path',
            'mpc_mime_to_ext',                // вынесена в файл → mpc_mime_to_ext_path
            'mpc_resource_lexicon_keys_path', // механизм ретайрнут (автогенерация mpc_resource_*)
            'mpc_path_to_create',             // механизм create/ ретайрнут (resources→manifest, plugins→plugins apply)
            'mpc_thumb_format',               // мёртвая (формат идёт из mpc_common_thumb_params f=)
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
