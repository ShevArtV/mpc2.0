<?php

namespace MpcVEServices\Handlers;

use MpcVEServices\Mpcve;

/**
 * Экшены log/list и log/revert: просмотр журнала изменений ресурса и откат
 * правки поля (запись старого значения обратно через field/save). Откат — только
 * для записей action=field с сохранённым старым значением (revertable=1).
 * Структурные операции (row/section) — только аудит, без отката.
 */
class LogHandler
{
    private \modX $modx;
    private Mpcve $mpcve;

    public function __construct(\modX $modx, Mpcve $mpcve)
    {
        $this->modx = $modx;
        $this->mpcve = $mpcve;
    }

    public function list(array $request): array
    {
        $rid = (int)($request['resourceId'] ?? 0);
        if ($rid <= 0 && $this->modx->resource) {
            $rid = (int)$this->modx->resource->get('id');
        }
        if ($rid <= 0) {
            return $this->err('resourceId required');
        }
        // anti-IDOR: не отдаём журнал (old/new значения) чужого ресурса.
        if (!(new PermissionChecker($this->modx))->canViewResource($rid)) {
            return $this->err($this->modx->lexicon('mpcve_err_permission'));
        }
        $rows = (new ChangeLog($this->modx))->listFor($rid, (int)($request['limit'] ?? 100));
        $entries = array_map(function ($r) {
            // source (editor|admin) и level (resource|type|global) лежат в address.
            $addr = json_decode((string)($r['address'] ?? ''), true);
            $addr = is_array($addr) ? $addr : [];
            return [
                'id'         => (int)$r['id'],
                'user'       => (string)$r['username'],
                'action'     => (string)$r['action'],
                'section'    => (string)$r['section'],
                'field'      => (string)$r['field'],
                'old'        => $r['old_value'],
                'new'        => $r['new_value'],
                'source'     => (string)($addr['source'] ?? 'editor'),
                'level'      => (string)($addr['level'] ?? ''),
                'revertable' => ((int)$r['revertable'] === 1 && (int)$r['reverted'] === 0),
                'reverted'   => ((int)$r['reverted'] === 1),
                'ts'         => (int)$r['created_on'],
            ];
        }, $rows);
        return ['success' => true, 'message' => '', 'data' => ['entries' => $entries]];
    }

    public function revert(array $request): array
    {
        $id = (int)($request['id'] ?? 0);
        if ($id <= 0) {
            return $this->err('id required');
        }
        $log = new ChangeLog($this->modx);
        $entry = $log->get($id);
        if (!$entry) {
            return $this->err('запись не найдена');
        }
        if ((int)$entry['revertable'] !== 1 || (int)$entry['reverted'] === 1) {
            return $this->err('эта запись не откатывается');
        }
        if ($entry['action'] !== 'field') {
            return $this->err('откат поддержан только для полей');
        }
        $address = json_decode((string)$entry['address'], true);
        if (!is_array($address)) {
            return $this->err('некорректный адрес записи');
        }
        // anti-IDOR: откат пишет в ресурс из лог-записи — нужно право save на него.
        if (!(new PermissionChecker($this->modx))->canEditResource((int)($address['resourceId'] ?? 0))) {
            return $this->err($this->modx->lexicon('mpcve_err_permission'));
        }
        $old = $entry['old_value'];
        $res = $this->mpcve->writeField($address, $old);
        if (empty($res['success'])) {
            return $res;
        }
        $log->markReverted($id);
        // Сам откат — новая запись (было=new, стало=old; снова откатываемая).
        $log->add([
            'resource_id' => (int)($address['resourceId'] ?? 0),
            'user_id'     => (int)($this->modx->user ? $this->modx->user->get('id') : 0),
            'username'    => (string)($this->modx->user ? $this->modx->user->get('username') : ''),
            'action'      => 'field',
            'section'     => (string)($address['section'] ?? ''),
            'field'       => (string)($address['fieldName'] ?? ''),
            'address'     => (string)$entry['address'],
            'old_value'   => $entry['new_value'],
            'new_value'   => $old,
            'revertable'  => 1,
        ]);
        return ['success' => true, 'message' => 'Откат выполнен', 'data' => []];
    }

    private function err(string $message): array
    {
        return ['success' => false, 'message' => $message, 'data' => []];
    }
}
