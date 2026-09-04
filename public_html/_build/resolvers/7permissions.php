<?php
/**
 * Resolver: регистрирует права пакета в шаблоне политик и в политике Administrator.
 * На install/upgrade — добавляет недостающие, на uninstall — убирает.
 *
 * Два шага, и первый важнее второго:
 *   1) регистрация права как `modAccessPermission` в шаблоне политики — админка
 *      MODX при сохранении политики пишет только права, известные её шаблону,
 *      и незнакомые ключи молча вычищает. Без шага 1 право живёт ровно до
 *      первого сохранения политики через админку;
 *   2) выдача права политике `Administrator`.
 *
 * Кастомные политики (например «Content Managers» на конкретном сайте) резолвер
 * НЕ трогает: пакет не знает про политики чужих сайтов. Их наполняет проект
 * своей миграцией.
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

/* Права пакета. Градация с 2.5.68-rc: mpc_view — только смотреть словарь и
   выгружать его, mpc_lexicon_manage — писать в него (правка ключа и импорт
   файла, то есть замена текстов витрины). */
$permissionNames = [
    'mpc_view' => 'Просмотр словаря переводов (MPC)',
    'mpc_lexicon_manage' => 'Изменение словаря переводов: правка ключей и импорт (MPC)',
];
$policyName = 'Administrator';
$templateName = 'AdministratorTemplate';

/** @var modAccessPolicy $policy */
$policy = $modx->getObject('modAccessPolicy', ['name' => $policyName]);
if (!$policy) {
    $modx->log(modX::LOG_LEVEL_WARN, "Policy '{$policyName}' not found — skipping mpc permissions");

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

/* Шаг 1: права в шаблоне политик. */
if ($templateId) {
    foreach ($permissionNames as $permissionName => $description) {
        /** @var modAccessPermission $permission */
        $permission = $modx->getObject('modAccessPermission', [
            'template' => $templateId,
            'name' => $permissionName,
        ]);

        if ($action === xPDOTransport::ACTION_INSTALL || $action === xPDOTransport::ACTION_UPGRADE) {
            if ($permission) {
                continue;
            }
            $permission = $modx->newObject('modAccessPermission');
            $permission->fromArray([
                'template' => $templateId,
                'name' => $permissionName,
                /* Коробочные права держат здесь ключ лексикона; у пакетного
                   описанием служит человекочитаемая строка. */
                'description' => $description,
                'value' => 1,
            ]);
            if ($permission->save()) {
                $modx->log(modX::LOG_LEVEL_INFO, "Registered '{$permissionName}' permission in policy template #{$templateId}");
            } else {
                $modx->log(modX::LOG_LEVEL_WARN, "Failed to register '{$permissionName}' permission in policy template #{$templateId}");
            }
        } elseif ($action === xPDOTransport::ACTION_UNINSTALL && $permission) {
            if ($permission->remove()) {
                $modx->log(modX::LOG_LEVEL_INFO, "Removed '{$permissionName}' permission from policy template #{$templateId}");
            }
        }
    }
} else {
    $modx->log(modX::LOG_LEVEL_WARN, "Policy template for '{$policyName}' not found — mpc permissions may be wiped on the next policy save");
}

/* Шаг 2: права в самой политике. */
$data = $policy->get('data');
$permissions = is_array($data) ? $data : json_decode((string)$data, true);
if (!is_array($permissions)) {
    $permissions = [];
}
$changed = false;

foreach (array_keys($permissionNames) as $permissionName) {
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
}

if ($changed) {
    $policy->set('data', $permissions);
    $policy->save();
}

return true;
