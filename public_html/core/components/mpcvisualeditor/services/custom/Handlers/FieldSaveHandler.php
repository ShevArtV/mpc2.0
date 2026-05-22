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

        $value = $request['value'] ?? '';

        return $this->mpcve->writeField($address, $value);
    }
}
