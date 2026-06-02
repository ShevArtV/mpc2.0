<?php

namespace MpcVEServices\Handlers;

use MpcVEServices\Mpcve;

/**
 * Экшен row/op: структурная операция над строками поля-списка (add|delete|move).
 * Принимает address (JSON или массив) + op + idx|fromIdx,toIdx, делегирует в
 * Mpcve::rowOp → MpcServices\Handlers\FieldWriter::writeRowOp.
 */
class RowOpHandler
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
        if (!is_array($address) || empty($address['op']) || empty($address['section']) || empty($address['parentField'])) {
            return ['success' => false, 'message' => 'op, section и parentField обязательны', 'data' => []];
        }

        if (empty($address['resourceId'])) {
            $address['resourceId'] = (int)($request['resourceId'] ?? 0);
        }

        return $this->mpcve->rowOp($address);
    }
}
