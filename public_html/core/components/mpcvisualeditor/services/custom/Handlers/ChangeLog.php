<?php

namespace MpcVEServices\Handlers;

/**
 * Журнал изменений (кто / когда / что) визуального редактора. Хранение —
 * отдельная таблица `{prefix}mpcve_changelog` (raw PDO, без xPDO-схемы). Запись
 * после каждой успешной правки; просмотр и откат — LogHandler.
 *
 * Поля: resource_id, user_id, username, action (field|row|section), section,
 * field, address (JSON адреса для отката), old_value/new_value, revertable
 * (можно ли откатить = есть старое значение и это field), reverted, created_on.
 */
class ChangeLog
{
    private \modX $modx;
    private string $table;

    public function __construct(\modX $modx)
    {
        $this->modx = $modx;
        $this->table = $modx->getOption('table_prefix', null, 'modx_') . 'mpcve_changelog';
    }

    public function add(array $e): void
    {
        $sql = "INSERT INTO {$this->table}
            (resource_id, user_id, username, action, section, field, address, old_value, new_value, revertable, reverted, created_on)
            VALUES (?,?,?,?,?,?,?,?,?,?,0,?)";
        try {
            $stmt = $this->modx->prepare($sql);
            $stmt->execute([
                (int)($e['resource_id'] ?? 0),
                (int)($e['user_id'] ?? 0),
                (string)($e['username'] ?? ''),
                (string)($e['action'] ?? ''),
                (string)($e['section'] ?? ''),
                (string)($e['field'] ?? ''),
                (string)($e['address'] ?? ''),
                isset($e['old_value']) ? (string)$e['old_value'] : null,
                isset($e['new_value']) ? (string)$e['new_value'] : null,
                (int)($e['revertable'] ?? 0),
                time(),
            ]);
        } catch (\Throwable $ex) {
            // лог не должен ломать запись — глотаем (таблицы может не быть до миграции).
            (new \MpcVEServices\Logger($this->modx))->warning('changelog: ' . $ex->getMessage(), [], 'changelog');
        }
    }

    /** Последние записи по ресурсу (DESC). */
    public function listFor(int $resourceId, int $limit = 100): array
    {
        try {
            $limit = max(1, min(500, $limit));
            $stmt = $this->modx->prepare("SELECT * FROM {$this->table} WHERE resource_id = ? ORDER BY id DESC LIMIT {$limit}");
            $stmt->execute([$resourceId]);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $ex) {
            return [];
        }
    }

    public function get(int $id): ?array
    {
        try {
            $stmt = $this->modx->prepare("SELECT * FROM {$this->table} WHERE id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (\Throwable $ex) {
            return null;
        }
    }

    public function markReverted(int $id): void
    {
        try {
            $stmt = $this->modx->prepare("UPDATE {$this->table} SET reverted = 1 WHERE id = ?");
            $stmt->execute([$id]);
        } catch (\Throwable $ex) {
            // no-op
        }
    }
}
