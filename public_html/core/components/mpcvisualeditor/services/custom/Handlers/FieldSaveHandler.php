<?php

namespace MpcVEServices\Handlers;

use MpcVEServices\Mpcve;

/**
 * Экшен field/save: сохранение значения одного поля по адресу.
 * Принимает address (JSON или массив) + value, делегирует в Mpcve::writeField
 * (→ MpcServices\Handlers\FieldWriter). Реализованы типы rfield/tv (M4);
 * type=field (mpc_config) вернёт «not implemented» до M2.
 */
class FieldSaveHandler
{
    private \modX $modx;
    private Mpcve $mpcve;

    public function __construct(\modX $modx, Mpcve $mpcve)
    {
        $this->modx = $modx;
        $this->mpcve = $mpcve;
    }

    public function handle(array $request): array
    {
        $address = $request['address'] ?? null;
        if (is_string($address)) {
            $address = json_decode($address, true);
        }
        if (!is_array($address) || empty($address['type']) || empty($address['fieldName'])) {
            return ['success' => false, 'message' => $this->modx->lexicon('mpcve_err_address'), 'data' => []];
        }

        // resourceId: из адреса либо из текущего ресурса коннектора
        if (empty($address['resourceId'])) {
            $address['resourceId'] = (int)($request['resourceId'] ?? 0);
        }

        // anti-IDOR: глобального mpcve_edit мало — проверяем право save на сам ресурс.
        if (!(new PermissionChecker($this->modx))->canEditResource((int)$address['resourceId'])) {
            return ['success' => false, 'message' => $this->modx->lexicon('mpcve_err_permission'), 'data' => []];
        }

        $value = $request['value'] ?? '';
        // Старое значение редактор присылает сам (`old`) — есть → запись откатываемая.
        $old = array_key_exists('old', $request) ? $request['old'] : null;

        $res = $this->mpcve->writeField($address, $value);

        if (!empty($res['success'])) {
            (new ChangeLog($this->modx))->add([
                'resource_id' => (int)($address['resourceId'] ?? 0),
                'user_id'     => (int)($this->modx->user ? $this->modx->user->get('id') : 0),
                'username'    => (string)($this->modx->user ? $this->modx->user->get('username') : ''),
                'action'      => 'field',
                'section'     => (string)($address['section'] ?? ''),
                'field'       => (string)($address['fieldName'] ?? ''),
                'address'     => json_encode($address, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'old_value'   => $old === null ? null : (is_scalar($old) ? (string)$old : json_encode($old)),
                'new_value'   => is_scalar($value) ? (string)$value : json_encode($value),
                'revertable'  => ($old !== null) ? 1 : 0,
            ]);
        }

        return $res;
    }
}
