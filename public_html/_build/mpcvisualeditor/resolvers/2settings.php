<?php
/**
 * Resolver: добавляет НОВЫЕ системные настройки при апгрейде существующей
 * установки. В config.inc.php update.settings=false (чтобы не затирать
 * пользовательские значения), поэтому новые ключи на upgrade не доезжают через
 * vehicle elements/settings.php — досоздаём их здесь, только если отсутствуют.
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
        // ключ => [value, xtype, area] — добавляется, только если ключа ещё нет
        $newSettings = [
            'mpcve_allowed_attrs' => [
                'value' => 'class,id,title,dir,lang,role,style,href,target,rel,name,download,'
                    . 'src,alt,width,height,srcset,sizes,loading,decoding,type,media,start,'
                    . 'colspan,rowspan,scope,datetime',
                'xtype' => 'textfield',
                'area'  => 'editor',
            ],
        ];
        foreach ($newSettings as $key => $def) {
            if ($modx->getObject('modSystemSetting', $key)) {
                continue; // не трогаем существующее значение
            }
            /** @var modSystemSetting $setting */
            $setting = $modx->newObject('modSystemSetting');
            $setting->fromArray([
                'key'       => $key,
                'value'     => $def['value'],
                'xtype'     => $def['xtype'],
                'namespace' => 'mpcvisualeditor',
                'area'      => $def['area'],
            ], '', true);
            $setting->save();
            $modx->log(modX::LOG_LEVEL_INFO, "[mpcVE] Добавлена настройка {$key}");
        }
        break;

    case xPDOTransport::ACTION_UNINSTALL:
        // ключи пакета удалит штатный settings-vehicle; ничего не делаем.
        break;
}

return true;
