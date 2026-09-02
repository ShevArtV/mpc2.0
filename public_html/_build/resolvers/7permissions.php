<?php
/**
 * Resolver: register mpc_view permission in the Administrator policy.
 * On install/upgrade — adds the permission if missing.
 * On uninstall — removes it.
 *
 * Два шага, и первый важнее второго:
 *   1) регистрация права как `modAccessPermission` в шаблоне политики — админка
 *      MODX при сохранении политики пишет только права, известные её шаблону,
 *      и незнакомые ключи молча вычищает. Без шага 1 право живёт ровно до
 *      первого сохранения политики через админку;
 *   2) выдача права политике `Administrator`.
 *
 * Поле `modAccessPolicy.data` объявлено с phptype=json: `get('data')` отдаёт уже
 * МАССИВ, `set('data', $array)` кодирует сам. Прогонять его через json_decode()
 * нельзя — вернётся null, и политика сохранится с одним лишь правом пакета,
 * потеряв все остальные (так до 2.5.64-rc и происходило: на боевых сайтах в
 * политике `Administrator` оставалось `{"mpc_view":true}` вместо 181 права).
 *
 * @var xPDOTransport $transport
 * @var array $options
 * @var modX $modx
 */

if (!$transport->xpdo) {
    return true;
}

$modx =& $transport->xpdo;
$permissionName = 'mpc_view';
$policyName = 'Administrator';
$templateName = 'AdministratorTemplate';

/** @var modAccessPolicy $policy */
$policy = $modx->getObject('modAccessPolicy', ['name' => $policyName]);
if (!$policy) {
    $modx->log(modX::LOG_LEVEL_WARN, "Policy '{$policyName}' not found — skipping {$permissionName} permission");
    return true;
}

/* Шаблон берём у самой политики: на сайтах с переименованным шаблоном поиск по
   имени промахнётся, а связь политика→шаблон верна всегда. */
$template = $policy->getOne('Template');
if (!$template) {
    $template = $modx->getObject('modAccessPolicyTemplate', ['name' => $templateName]);
}
$templateId = $template ? (int)$template->get('id') : 0;

$action = $options[xPDOTransport::PACKAGE_ACTION];

/* Шаг 1: право в шаблоне политик. */
if ($templateId) {
    /** @var modAccessPermission $permission */
    $permission = $modx->getObject('modAccessPermission', [
        'template' => $templateId,
        'name' => $permissionName,
    ]);

    if ($action === xPDOTransport::ACTION_INSTALL || $action === xPDOTransport::ACTION_UPGRADE) {
        if (!$permission) {
            $permission = $modx->newObject('modAccessPermission');
            $permission->fromArray([
                'template' => $templateId,
                'name' => $permissionName,
                /* Коробочные права держат здесь ключ лексикона; у пакетного
                   описанием служит само имя. */
                'description' => $permissionName,
                'value' => 1,
            ]);
            if ($permission->save()) {
                $modx->log(modX::LOG_LEVEL_INFO, "Registered '{$permissionName}' permission in policy template #{$templateId}");
            } else {
                $modx->log(modX::LOG_LEVEL_WARN, "Failed to register '{$permissionName}' permission in policy template #{$templateId}");
            }
        }
    } elseif ($action === xPDOTransport::ACTION_UNINSTALL && $permission) {
        if ($permission->remove()) {
            $modx->log(modX::LOG_LEVEL_INFO, "Removed '{$permissionName}' permission from policy template #{$templateId}");
        }
    }
} else {
    $modx->log(modX::LOG_LEVEL_WARN, "Policy template for '{$policyName}' not found — '{$permissionName}' may be wiped on the next policy save");
}

/* Шаг 2: право в самой политике. */
$data = $policy->get('data');
$permissions = is_array($data) ? $data : json_decode((string)$data, true);
if (!is_array($permissions)) {
    $permissions = [];
}
$changed = false;

switch ($action) {
    case xPDOTransport::ACTION_INSTALL:
    case xPDOTransport::ACTION_UPGRADE:
        if (!isset($permissions[$permissionName])) {
            $permissions[$permissionName] = true;
            $changed = true;
            $modx->log(modX::LOG_LEVEL_INFO, "Added '{$permissionName}' permission to '{$policyName}' policy");
        } else {
            $modx->log(modX::LOG_LEVEL_INFO, "Permission '{$permissionName}' already exists in '{$policyName}' policy");
        }
        break;

    case xPDOTransport::ACTION_UNINSTALL:
        if (isset($permissions[$permissionName])) {
            unset($permissions[$permissionName]);
            $changed = true;
            $modx->log(modX::LOG_LEVEL_INFO, "Removed '{$permissionName}' permission from '{$policyName}' policy");
        }
        break;
}

if ($changed) {
    $policy->set('data', $permissions);
    $policy->save();
}

return true;
