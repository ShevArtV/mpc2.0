<?php
/**
 * Resolver: создаёт выделенный filesystem-источник «mpcMedia» для медиа mpc
 * и проставляет настройку mpc_media_source (если она пустая).
 *
 * Источник ЗАСКОУПЛЕН на папку медиа (basePath/baseUrl = assets/components/
 * migxpageconfigurator/media/) — файл-менеджер mpcVE видит только медиа, а не
 * корень сайта (раньше basePath='' открывал весь сайт, включая core/ и конфиги).
 * Путь захардкожен здесь и живёт ТОЛЬКО в basePath источника; прежняя настройка
 * mpc_media_path удалена (в рантайме была пустой и лишь вводила в заблуждение) —
 * резолвер сносит её на install/upgrade.
 * Внутри источника медиа кладутся в images/videos/audios. Структура properties
 * берётся от источника по умолчанию (полный набор) и переопределяется в нужных
 * ключах. На uninstall источник НЕ удаляем — там могут лежать загруженные файлы.
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
        // Папка медиа mpc — захардкожена (настройка mpc_media_path удалена как
        // вводящая в заблуждение: реальный якорь базового пути — basePath источника
        // mpcMedia). На неё скоупится источник; внутри — подпапки типов
        // (mpc_download_paths). Вид 'path/'.
        $mediaDir = 'assets/components/migxpageconfigurator/media/';

        $name = 'mpcMedia';
        // Ищем существующий источник, чтобы НЕ плодить дубль на апгрейде: сначала по
        // настройке mpc_media_source (если заполнена и объект с таким id есть — юзер
        // мог переименовать источник, тогда поиск по name промахнулся бы), затем
        // фолбэк по имени 'mpcMedia'. Создаём новый только если не нашли никак.
        $source = null;
        $configuredId = (int)$modx->getOption('mpc_media_source', null, 0);
        if ($configuredId) {
            $source = $modx->getObject('sources.modMediaSource', $configuredId);
        }
        if (!$source) {
            $source = $modx->getObject('sources.modMediaSource', ['name' => $name]);
        }
        if (!$source) {
            /** @var modMediaSource $source */
            $source = $modx->newObject('sources.modFileMediaSource');
            $source->fromArray([
                'name'        => $name,
                'description'  => 'mpc: единый источник медиа (images/videos/audios)',
            ]);
            $source->set('class_key', 'sources.modFileMediaSource');

            // Полная структура свойств — от источника по умолчанию.
            $props = [];
            if ($def = $modx->getObject('sources.modMediaSource', (int)$modx->getOption('default_media_source', null, 1))) {
                $props = $def->getProperties();
            }
            // Источник заскоуплен на папку медиа (basePath/baseUrl = $mediaDir,
            // relative) → файл-менеджер видит только её, не корень сайта.
            $overrides = [
                'basePath'         => $mediaDir,
                'basePathRelative' => true,
                'baseUrl'          => $mediaDir,
                'baseUrlRelative'  => true,
                'allowedFileTypes' => 'jpg,jpeg,png,gif,webp,svg,avif,mp4,webm,ogv,mov,m4v,mp3,ogg,wav,m4a',
            ];
            foreach ($overrides as $k => $v) {
                if (isset($props[$k]) && is_array($props[$k])) {
                    $props[$k]['value'] = $v;
                } else {
                    $props[$k] = ['name' => $k, 'value' => $v];
                }
            }
            $source->setProperties($props);

            if ($source->save()) {
                $modx->log(modX::LOG_LEVEL_INFO, "Created media source '{$name}' (id " . $source->get('id') . ')');
            } else {
                $modx->log(modX::LOG_LEVEL_ERROR, "Failed to create media source '{$name}'");
            }
        }

        if ($source) {
            $setting = $modx->getObject('modSystemSetting', 'mpc_media_source');
            if ($setting && (string)$setting->get('value') === '') {
                $setting->set('value', $source->get('id'));
                $setting->save();
                $modx->log(modX::LOG_LEVEL_INFO, 'Set mpc_media_source = ' . $source->get('id'));
            }
            // Гарантируем папку медиа на диске.
            $abs = MODX_BASE_PATH . trim($mediaDir, '/');
            if (!is_dir($abs)) {
                @mkdir($abs, 0755, true);
            }
            $modx->getCacheManager()->refresh(['system_settings' => []]);
        }

        // Сносим legacy-настройку mpc_media_path: путь медиа теперь живёт ТОЛЬКО в
        // basePath источника mpcMedia, отдельная настройка лишь вводила в
        // заблуждение (в рантайме всё равно была пустой).
        if ($legacy = $modx->getObject('modSystemSetting', 'mpc_media_path')) {
            $legacy->remove();
            $modx->getCacheManager()->refresh(['system_settings' => []]);
            $modx->log(modX::LOG_LEVEL_INFO, 'Removed legacy setting mpc_media_path');
        }
        break;

    case xPDOTransport::ACTION_UNINSTALL:
        // Источник и настройку не трогаем — там могут быть загруженные медиа.
        break;
}

return true;
