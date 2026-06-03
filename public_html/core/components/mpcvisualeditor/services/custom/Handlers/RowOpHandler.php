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

        $res = $this->mpcve->rowOp($address);

        if (!empty($res['success'])) {
            // Аудит (откат структурных операций не поддержан → revertable=0).
            (new ChangeLog($this->modx))->add([
                'resource_id' => (int)($address['resourceId'] ?? 0),
                'user_id'     => (int)($this->modx->user ? $this->modx->user->get('id') : 0),
                'username'    => (string)($this->modx->user ? $this->modx->user->get('username') : ''),
                'action'      => 'row',
                'section'     => (string)($address['section'] ?? ''),
                'field'       => (string)($address['parentField'] ?? ''),
                'new_value'   => 'строка: ' . (string)($address['op'] ?? ''),
                'revertable'  => 0,
            ]);
        }

        return $res;
    }
}
