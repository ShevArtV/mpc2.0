<?php
/**
 * Resolver: регистрирует permissions `mpcve_edit` и `mpcve_edit_global`.
 * Install/upgrade — добавляет недостающие. Uninstall — удаляет.
 * mpcve_edit_global — право на правку ГЛОБАЛЬНЫХ данных (настройки data-mpc-info,
 * контакты) из фронт-редактора.
 *
 * Два шага, и первый важнее второго:
 *   1) регистрация обоих прав как `modAccessPermission` в шаблоне политики —
 *      админка MODX при сохранении политики пишет только права, известные её
 *      шаблону, и незнакомые ключи молча вычищает. Без шага 1 права живут ровно
 *      до первого сохранения политики через админку;
 *   2) выдача прав политике `Administrator`.
 *
 * Поле `modAccessPolicy.data` объявлено с phptype=json: `get('data')` отдаёт уже
 * МАССИВ, `set('data', $array)` кодирует сам. Прогонять его через json_decode()
 * нельзя — вернётся null, и политика сохранится с одними лишь правами пакета,
 * потеряв все остальные.
 *
 * @var xPDOTransport $transport
 * @var array $options
 * @var modX $modx
 */

if (!$transport->xpdo) {
    return true;
}

$modx =& $transport->xpdo;
$permissionNames = array(
    'mpcve_edit' => 'mpcve_edit',
    'mpcve_edit_global' => 'mpcve_edit_global',
);
$policyName = 'Administrator';
$templateName = 'AdministratorTemplate';

/** @var modAccessPolicy $policy */
$policy = $modx->getObject('modAccessPolicy', array('name' => $policyName));
if (!$policy) {
    $modx->log(modX::LOG_LEVEL_WARN, "Policy '{$policyName}' not found — skipping mpcve permissions");
    return true;
}

/* Шаблон берём у самой политики: на сайтах с переименованным шаблоном поиск по
   имени промахнётся, а связь политика→шаблон верна всегда. */
$template = $policy->getOne('Template');
if (!$template) {
    $template = $modx->getObject('modAccessPolicyTemplate', array('name' => $templateName));
}
$templateId = $template ? (int)$template->get('id') : 0;

$action = $options[xPDOTransport::PACKAGE_ACTION];

/* Шаг 1: права в шаблоне политик. */
if ($templateId) {
    foreach ($permissionNames as $name => $description) {
        /** @var modAccessPermission $permission */
        $permission = $modx->getObject('modAccessPermission', array(
            'template' => $templateId,
            'name' => $name,
        ));

        if ($action === xPDOTransport::ACTION_INSTALL || $action === xPDOTransport::ACTION_UPGRADE) {
            if ($permission) {
                continue;
            }
            $permission = $modx->newObject('modAccessPermission');
            $permission->fromArray(array(
                'template' => $templateId,
                'name' => $name,
                /* Коробочные права держат здесь ключ лексикона; у пакетных (mpc_view)
                   описанием служит само имя. Идём тем же путём, что и mpc. */
                'description' => $description,
                'value' => 1,
            ));
            if ($permission->save()) {
                $modx->log(modX::LOG_LEVEL_INFO, "Registered '{$name}' permission in policy template #{$templateId}");
            } else {
                $modx->log(modX::LOG_LEVEL_WARN, "Failed to register '{$name}' permission in policy template #{$templateId}");
            }
        } elseif ($action === xPDOTransport::ACTION_UNINSTALL && $permission) {
            if ($permission->remove()) {
                $modx->log(modX::LOG_LEVEL_INFO, "Removed '{$name}' permission from policy template #{$templateId}");
            }
        }
    }
} else {
    $modx->log(modX::LOG_LEVEL_WARN, "Policy template for '{$policyName}' not found — mpcve permissions may be wiped on the next policy save");
}

/* Шаг 2: права в самой политике. */
$data = $policy->get('data');
$permissions = is_array($data) ? $data : json_decode((string)$data, true);
if (!is_array($permissions)) {
    $permissions = array();
}
$changed = false;

switch ($action) {
    case xPDOTransport::ACTION_INSTALL:
    case xPDOTransport::ACTION_UPGRADE:
        foreach (array_keys($permissionNames) as $name) {
            if (!isset($permissions[$name])) {
                $permissions[$name] = true;
                $changed = true;
                $modx->log(modX::LOG_LEVEL_INFO, "Added '{$name}' permission to '{$policyName}' policy");
            }
        }
        break;

    case xPDOTransport::ACTION_UNINSTALL:
        foreach (array_keys($permissionNames) as $name) {
            if (isset($permissions[$name])) {
                unset($permissions[$name]);
                $changed = true;
                $modx->log(modX::LOG_LEVEL_INFO, "Removed '{$name}' permission from '{$policyName}' policy");
            }
        }
        break;
}

if ($changed) {
    $policy->set('data', $permissions);
    $policy->save();
}

return true;
