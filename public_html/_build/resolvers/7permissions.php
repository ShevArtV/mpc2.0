<?php
/**
 * Resolver: register mpc_view permission in the Administrator policy.
 * On install/upgrade — adds the permission if missing.
 * On uninstall — removes it.
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

switch ($options[xPDOTransport::PACKAGE_ACTION]) {
    case xPDOTransport::ACTION_INSTALL:
    case xPDOTransport::ACTION_UPGRADE:
        /** @var modAccessPolicy $policy */
        $policy = $modx->getObject('modAccessPolicy', ['name' => $policyName]);
        if (!$policy) {
            $modx->log(modX::LOG_LEVEL_WARN, "Policy '{$policyName}' not found — skipping mpc_view permission");
            break;
        }

        $data = $policy->get('data');
        $permissions = $data ? json_decode($data, true) : [];

        if (!isset($permissions[$permissionName])) {
            $permissions[$permissionName] = true;
            $policy->set('data', json_encode($permissions));
            $policy->save();
            $modx->log(modX::LOG_LEVEL_INFO, "Added '{$permissionName}' permission to '{$policyName}' policy");
        } else {
            $modx->log(modX::LOG_LEVEL_INFO, "Permission '{$permissionName}' already exists in '{$policyName}' policy");
        }
        break;

    case xPDOTransport::ACTION_UNINSTALL:
        /** @var modAccessPolicy $policy */
        $policy = $modx->getObject('modAccessPolicy', ['name' => $policyName]);
        if (!$policy) {
            break;
        }

        $data = $policy->get('data');
        $permissions = $data ? json_decode($data, true) : [];

        if (isset($permissions[$permissionName])) {
            unset($permissions[$permissionName]);
            $policy->set('data', json_encode($permissions));
            $policy->save();
            $modx->log(modX::LOG_LEVEL_INFO, "Removed '{$permissionName}' permission from '{$policyName}' policy");
        }
        break;
}

return true;
