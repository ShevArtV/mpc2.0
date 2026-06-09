<?php
/**
 * Применяет значения setup-options модалки (см. _build/setup.options.php) к
 * системным настройкам. ТОЛЬКО при свежей установке — на upgrade настройки
 * пользователя не перетираем. Имя с 'A' → scandir ставит резолвер после
 * цифровых (выполняется последним, когда настройки-vehicle уже установлены).
 *
 * @var xPDOTransport $transport
 * @var array $options
 * @var modX $modx
 */
if ($transport->xpdo) {
    $modx =& $transport->xpdo;

    if (($options[xPDOTransport::PACKAGE_ACTION] ?? null) === xPDOTransport::ACTION_INSTALL) {
        $keys = [
            'mpc_use_lexicons',
            'mpc_lexicons_namespace',
            'mpc_lexicon_path',
            'mpc_exclude_lexicons_filename',
            'mpc_available_languages',
            'mpc_default_language',
            'mpc_lazyload_enabled',
            'mpc_expand_enabled',
            'mpc_path_to_presets',
            'mpc_manifests_path',
        ];
        foreach ($keys as $key) {
            if (!array_key_exists($key, $options)) {
                continue;
            }
            $setting = $modx->getObject('modSystemSetting', $key);
            if (!$setting) {
                continue;
            }
            $setting->set('value', $options[$key]);
            $setting->save();
        }
        if ($cm = $modx->getCacheManager()) {
            $cm->refresh(['system_settings' => []]);
        }
    }
}

return true;
