<?php

namespace MpcVEServices\Handlers;

use MpcVEServices\Mpcve;

/**
 * Экшен row/op: структурная операция над строками поля-списка (add|delete|move).
 * Принимает address (JSON или массив) + op + idx|fromIdx,toIdx, делегирует в
 * Mpcve::rowOp → MpcServices\Handlers\FieldWriter::writeRowOp.
 */
class RowOpHandler extends BaseHandler
{


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

        // anti-IDOR: право save на конкретный ресурс, не только глобальный mpcve_edit.
        if (!(new PermissionChecker($this->modx))->canEditResource((int)$address['resourceId'])) {
            return ['success' => false, 'message' => $this->modx->lexicon('mpcve_err_permission'), 'data' => []];
        }

        $res = $this->mpcve->rowOp($address);

        if (!empty($res['success'])) {
            // Откат структурной операции = восстановить поле-список к снимку ДО
            // (FieldWriter вернул его в data.oldRows). Доступен только для top-level
            // списков (без path) — там есть снимок; вложенные → revertable=0.
            $oldRows   = $res['data']['oldRows'] ?? null;
            $canRevert = empty($address['path']) && $oldRows !== null;
            (new ChangeLog($this->modx))->add([
                'resource_id' => (int)($address['resourceId'] ?? 0),
                'user_id'     => (int)($this->modx->user ? $this->modx->user->get('id') : 0),
                'username'    => (string)($this->modx->user ? $this->modx->user->get('username') : ''),
                'action'      => 'row',
                'section'     => (string)($address['section'] ?? ''),
                'field'       => (string)($address['parentField'] ?? ''),
                // address нужен откату (section/parentField/level/resourceId).
                'address'     => json_encode($address, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                // снимок поля ДО операции — что восстановит revert.
                'old_value'   => $canRevert ? json_encode($oldRows, JSON_UNESCAPED_UNICODE) : null,
                'new_value'   => 'строка: ' . (string)($address['op'] ?? ''),
                'revertable'  => $canRevert ? 1 : 0,
            ]);
        }

        return $res;
    }
}
