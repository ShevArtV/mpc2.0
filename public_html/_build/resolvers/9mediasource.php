<?php
/**
 * Resolver: создаёт выделенный filesystem-источник «mpcMedia» для медиа mpc
 * и проставляет настройку mpc_media_source (если она пустая).
 *
 * Источник: base = веб-рут ('/'), allowedFileTypes включает картинки/видео/аудио.
 * Внутри него медиа кладутся в mpc_media_path + images/videos/audios.
 * Структура properties берётся от источника по умолчанию (полный набор) и
 * переопределяется в нужных ключах. На uninstall источник НЕ удаляем —
 * там могут лежать загруженные файлы.
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
        $name = 'mpcMedia';
        $source = $modx->getObject('sources.modMediaSource', ['name' => $name]);
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
            // basePath/baseUrl пустые + relative=true → база = веб-рут, URL = /<path>.
            // (baseUrl='/' давал бы двойной слэш //path — битый protocol-relative URL.)
            $overrides = [
                'basePath'         => '',
                'basePathRelative' => true,
                'baseUrl'          => '',
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
            // Базовая папка медиа.
            $base = (string)$modx->getOption('mpc_media_path', null, 'assets/components/migxpageconfigurator/media/');
            if ($base !== '') {
                $abs = MODX_BASE_PATH . trim($base, '/');
                if (!is_dir($abs)) {
                    @mkdir($abs, 0755, true);
                }
            }
            $modx->getCacheManager()->refresh(['system_settings' => []]);
        }
        break;

    case xPDOTransport::ACTION_UNINSTALL:
        // Источник и настройку не трогаем — там могут быть загруженные медиа.
        break;
}

return true;
