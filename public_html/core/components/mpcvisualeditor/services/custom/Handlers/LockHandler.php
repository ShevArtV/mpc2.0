<?php

namespace MpcVEServices\Handlers;

use MpcVEServices\Mpcve;

/**
 * Экшены lock/acquire и lock/release: блокировка ресурса на время фронт-
 * редактирования (чтобы двое не правили один ресурс одновременно).
 *
 * Лок — запись в кэше MODX (партиция mpcve_lock) с TTL = mpcve_lock_ttl сек.
 * TTL и есть idle-таймаут: фронт периодически refresh'ит лок (heartbeat), пока
 * идёт активность; перестал (бездействие/закрытие вкладки/краш) → запись
 * протухает → лок свободен. Явный release удаляет запись сразу.
 */
class LockHandler
{
    private const PARTITION = 'mpcve_lock';

    private \modX $modx;

    public function __construct(\modX $modx, Mpcve $mpcve)
    {
        $this->modx = $modx;
    }

    public function acquire(array $request): array
    {
        $rid = $this->rid($request);
        if ($rid <= 0) {
            return $this->err('resourceId required');
        }
        $me = (int)$this->modx->user->get('id');
        $myName = (string)$this->modx->user->get('username');
        $ttl = $this->ttl();

        $cur = $this->modx->cacheManager->get($this->key($rid), $this->opts());
        if (is_array($cur) && (int)($cur['userId'] ?? 0) !== $me) {
            // занято другим — лок НЕ обновляем.
            return $this->ok([
                'locked' => true, 'mine' => false,
                'by' => (string)($cur['username'] ?? 'другой пользователь'), 'ttl' => $ttl,
            ]);
        }
        // свободно или моё — берём/продлеваем.
        $this->modx->cacheManager->set(
            $this->key($rid),
            ['userId' => $me, 'username' => $myName, 'ts' => time()],
            $ttl,
            $this->opts()
        );
        return $this->ok(['locked' => true, 'mine' => true, 'by' => $myName, 'ttl' => $ttl]);
    }

    public function release(array $request): array
    {
        $rid = $this->rid($request);
        if ($rid <= 0) {
            return $this->ok(['released' => false]);
        }
        $me = (int)$this->modx->user->get('id');
        $cur = $this->modx->cacheManager->get($this->key($rid), $this->opts());
        if (is_array($cur) && (int)($cur['userId'] ?? 0) === $me) {
            $this->modx->cacheManager->delete($this->key($rid), $this->opts());
        }
        return $this->ok(['released' => true]);
    }

    private function rid(array $request): int
    {
        $rid = (int)($request['resourceId'] ?? 0);
        if ($rid <= 0 && $this->modx->resource) {
            $rid = (int)$this->modx->resource->get('id');
        }
        return $rid;
    }

    private function key(int $rid): string
    {
        return 'res_' . $rid;
    }

    private function opts(): array
    {
        return [\xPDO::OPT_CACHE_KEY => self::PARTITION];
    }

    private function ttl(): int
    {
        $ttl = (int)$this->modx->getOption('mpcve_lock_ttl', null, 600);
        return $ttl > 0 ? $ttl : 600;
    }

    private function ok(array $data): array
    {
        return ['success' => true, 'message' => '', 'data' => $data];
    }

    private function err(string $message): array
    {
        return ['success' => false, 'message' => $message, 'data' => []];
    }
}
