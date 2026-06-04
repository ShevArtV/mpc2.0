<?php

namespace MpcServices\Handlers;

/**
 * Реестр трекаемых полей (манифест для лога изменений). Глобальный союз со всех
 * шаблонов, но строки тегированы template_id → на нарезке заменяем строки этого
 * шаблона (replaceForTemplate), снаружи читаем DISTINCT-союз. Источник правды —
 * data-mpc-* маркеры шаблона, собранные грабером при нарезке (SectionProcessor).
 *
 * Назначение: (1) membership-фильтр для админ-лога (какие поля трекать при
 * сохранении ресурса — особенно rfield/tv, которых нет в mpc_config);
 * (2) источник выпадаек «секция → поля» для модалки истории.
 *
 * Уровень (resource/type/global) тут НЕ хранится — он определяется при записи
 * лога по сохраняемому ресурсу и кладётся в саму запись (для корректного отката).
 *
 * Хранение — отдельная таблица `{prefix}mpc_tracked_fields` (raw PDO, без xPDO-
 * схемы). Таблица создаётся лениво (ensureTable).
 */
class TrackedFields
{
    private \modX $modx;
    private string $table;
    private static bool $ensured = false;

    public function __construct(\modX $modx)
    {
        $this->modx = $modx;
        $this->table = $modx->getOption('table_prefix', null, 'modx_') . 'mpc_tracked_fields';
    }

    private function ensureTable(): void
    {
        if (self::$ensured) {
            return;
        }
        try {
            $this->modx->exec(
                "CREATE TABLE IF NOT EXISTS {$this->table} (
                    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    template_id INT NOT NULL DEFAULT 0,
                    type VARCHAR(16) NOT NULL DEFAULT '',
                    section VARCHAR(191) NOT NULL DEFAULT '',
                    lexicon_prefix VARCHAR(191) NOT NULL DEFAULT '',
                    field VARCHAR(191) NOT NULL DEFAULT '',
                    kind VARCHAR(32) NOT NULL DEFAULT '',
                    KEY template_id (template_id),
                    KEY type_field (type, field)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
            self::$ensured = true;
        } catch (\Throwable $ex) {
            $this->modx->log(\modX::LOG_LEVEL_WARN, '[mpc tracked_fields] ensureTable: ' . $ex->getMessage());
        }
    }

    /**
     * Перезаписать строки манифеста для шаблона (delete-by-template + insert).
     * $rows: [['type','section','lexicon_prefix','field','kind'], ...].
     */
    public function replaceForTemplate(int $templateId, array $rows): void
    {
        if ($templateId <= 0) {
            return;
        }
        $this->ensureTable();
        try {
            $del = $this->modx->prepare("DELETE FROM {$this->table} WHERE template_id = ?");
            $del->execute([$templateId]);

            if (!$rows) {
                return;
            }
            $ins = $this->modx->prepare(
                "INSERT INTO {$this->table} (template_id, type, section, lexicon_prefix, field, kind) VALUES (?,?,?,?,?,?)"
            );
            foreach ($rows as $r) {
                $ins->execute([
                    $templateId,
                    (string)($r['type'] ?? ''),
                    (string)($r['section'] ?? ''),
                    (string)($r['lexicon_prefix'] ?? ''),
                    (string)($r['field'] ?? ''),
                    (string)($r['kind'] ?? ''),
                ]);
            }
        } catch (\Throwable $ex) {
            $this->modx->log(\modX::LOG_LEVEL_WARN, '[mpc tracked_fields] replaceForTemplate: ' . $ex->getMessage());
        }
    }

    /** Трекается ли поле (глобальный союз). section учитываем только для type=field. */
    public function isTracked(string $type, string $field, string $section = ''): bool
    {
        $this->ensureTable();
        try {
            if ($type === 'field') {
                $stmt = $this->modx->prepare("SELECT 1 FROM {$this->table} WHERE type='field' AND field=? AND section=? LIMIT 1");
                $stmt->execute([$field, $section]);
            } else {
                $stmt = $this->modx->prepare("SELECT 1 FROM {$this->table} WHERE type=? AND field=? LIMIT 1");
                $stmt->execute([$type, $field]);
            }
            return (bool)$stmt->fetchColumn();
        } catch (\Throwable $ex) {
            return false;
        }
    }

    /** Весь манифест (DISTINCT-союз) — для модалки/диагностики. */
    public function all(): array
    {
        $this->ensureTable();
        try {
            $stmt = $this->modx->query("SELECT DISTINCT type, section, lexicon_prefix, field, kind FROM {$this->table} ORDER BY type, section, field");
            return $stmt ? ($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []) : [];
        } catch (\Throwable $ex) {
            return [];
        }
    }
}
