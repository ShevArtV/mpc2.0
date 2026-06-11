<?php
/**
 * Resolver: регистрирует permissions `mpcve_edit` и `mpcve_edit_global` в политике
 * Administrator. Install/upgrade — добавляет недостающие. Uninstall — удаляет.
 * mpcve_edit_global — право на правку ГЛОБАЛЬНЫХ данных (настройки data-mpc-info,
 * контакты) из фронт-редактора.
 *
 * @var xPDOTransport $transport
 * @var array $options
 * @var modX $modx
 */

if (!$transport->xpdo) {
    return true;
}

$modx =& $transport->xpdo;
$permissionNames = ['mpcve_edit', 'mpcve_edit_global'];
$policyName = 'Administrator';

/** @var modAccessPolicy $policy */
$policy = $modx->getObject('modAccessPolicy', ['name' => $policyName]);
if (!$policy) {
    $modx->log(modX::LOG_LEVEL_WARN, "Policy '{$policyName}' not found — skipping mpcve permissions");
    return true;
}
$data = $policy->get('data');
$permissions = $data ? json_decode($data, true) : [];
$changed = false;

switch ($options[xPDOTransport::PACKAGE_ACTION]) {
    case xPDOTransport::ACTION_INSTALL:
    case xPDOTransport::ACTION_UPGRADE:
        foreach ($permissionNames as $name) {
            if (!isset($permissions[$name])) {
                $permissions[$name] = true;
                $changed = true;
                $modx->log(modX::LOG_LEVEL_INFO, "Added '{$name}' permission to '{$policyName}' policy");
            }
        }
        break;

    case xPDOTransport::ACTION_UNINSTALL:
        foreach ($permissionNames as $name) {
            if (isset($permissions[$name])) {
                unset($permissions[$name]);
                $changed = true;
                $modx->log(modX::LOG_LEVEL_INFO, "Removed '{$name}' permission from '{$policyName}' policy");
            }
        }
        break;
}

if ($changed) {
    $policy->set('data', json_encode($permissions));
    $policy->save();
}

return true;
