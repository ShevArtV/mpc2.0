<?php

namespace MpcServices\Handlers;

/**
 * Аудит правок из АДМИНКИ (форма ресурса) в общий лог изменений mpcVE
 * (`{prefix}mpcve_changelog`). Закрывает половинчатость лога: редактор логирует
 * свои field/save сам, а правки в migx-конфигураторе/форме ресурса логирует это.
 *
 * Поток: `OnBeforeDocFormSave` → capture() снимает СТАРЫЕ значения (конфиг +
 * трекаемые rfield/tv) в static; `OnDocFormSave` (в начале, ДО re-cut handleFile)
 * → logChanges() диффит со свежими и пишет записи.
 *
 * Что трекаем: все скалярные поля mpc_config (config = mpc-контент) + rfield/tv
 * из манифеста {@see TrackedFields}. Списки/записи (массивы) — логируем коротко
 * (revertable=0). УРОВЕНЬ (resource/type/global) и source='admin' кладём в address
 * (для корректного отката и фильтра модалки) — без изменения схемы таблицы.
 */
class AdminAudit
{
    /** Структурные/служебные ключи секции — не трекаем как контент. */
    private const SKIP = [
        'MIGX_id', 'MIGX_formname', 'section_name', 'id', 'position', 'is_static',
        'hide_section', 'file_name', 'lexicon_prefix', 'css_file_path',
        'inline_styles', 'class_names', 'copy_from_origin', 'copy_all', 'sourceFrom',
    ];

    private \modX $modx;
    private string $table;
    private string $configTv;
    private static array $before = [];

    public function __construct(\modX $modx)
    {
        $this->modx = $modx;
        $this->table = $modx->getOption('table_prefix', null, 'modx_') . 'mpcve_changelog';
        $this->configTv = (string)$modx->getOption('mpc_common_config_name', null, 'mpc_config');
    }

    /**
     * Снимок СТАРЫХ значений ДО сохранения (в OnBeforeDocFormSave). Читаем прямым
     * SQL из БД: на OnBefore нативные поля ресурса уже содержат НОВЫЕ значения
     * формы (fromArray), а TV ещё старые — поэтому object-state ненадёжен. Прямой
     * запрос даёт истинное состояние до коммита.
     */
    public function capture(\modResource $resource): void
    {
        $rid = (int)$resource->get('id');
        if ($rid <= 0) {
            return; // новый ресурс — старого состояния нет
        }
        $p = $this->modx->getOption('table_prefix', null, 'modx_');

        $row = [];
        try {
            $st = $this->modx->prepare("SELECT * FROM {$p}site_content WHERE id = ?");
            $st->execute([$rid]);
            $row = $st->fetch(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            $row = [];
        }

        $tvVals = [];
        try {
            $st = $this->modx->prepare(
                "SELECT t.name AS n, v.value AS v FROM {$p}site_tmplvar_contentvalues v
                 JOIN {$p}site_tmplvars t ON t.id = v.tmplvarid WHERE v.contentid = ?"
            );
            $st->execute([$rid]);
            foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
                $tvVals[$r['n']] = $r['v'];
            }
        } catch (\Throwable $e) {
        }

        $snap = ['config' => json_decode((string)($tvVals[$this->configTv] ?? ''), true) ?: [], 'rfield' => [], 'tv' => []];
        foreach ((new TrackedFields($this->modx))->all() as $tr) {
            if ($tr['type'] === 'rfield') {
                $snap['rfield'][$tr['field']] = (string)($row[$tr['field']] ?? '');
            } elseif ($tr['type'] === 'tv') {
                $snap['tv'][$tr['field']] = (string)($tvVals[$tr['field']] ?? '');
            }
        }
        self::$before[$rid] = $snap;
    }

    /** Дифф vs снимок + запись в лог (в OnDocFormSave, ДО re-cut). */
    public function logChanges(\modResource $resource, int $staticBlocksPageId): void
    {
        $rid = (int)$resource->get('id');
        if (!isset(self::$before[$rid])) {
            return;
        }
        $old = self::$before[$rid];
        unset(self::$before[$rid]);

        $level = $rid === $staticBlocksPageId
            ? 'global'
            : ((int)$resource->get('parent') === $staticBlocksPageId ? 'type' : 'resource');

        $entries = [];
        $this->diffConfig(
            $old['config'],
            json_decode((string)$resource->getTVValue($this->configTv), true) ?: [],
            $level, $rid, $entries
        );

        foreach ((new TrackedFields($this->modx))->all() as $row) {
            if ($row['type'] === 'rfield' && array_key_exists($row['field'], $old['rfield'])) {
                $n = (string)$resource->get($row['field']);
                if ($old['rfield'][$row['field']] !== $n) {
                    $entries[] = $this->entry('rfield', '', $row['field'], $old['rfield'][$row['field']], $n, $level, $rid, true);
                }
            } elseif ($row['type'] === 'tv' && array_key_exists($row['field'], $old['tv'])) {
                $n = (string)$resource->getTVValue($row['field']);
                if ($old['tv'][$row['field']] !== $n) {
                    $entries[] = $this->entry('tv', '', $row['field'], $old['tv'][$row['field']], $n, $level, $rid, true);
                }
            }
        }

        if ($entries) {
            $this->write($entries, $rid);
        }
    }

    /** Дифф конфига по секциям (ключ MIGX_formname|lexicon_prefix). */
    private function diffConfig(array $old, array $new, string $level, int $rid, array &$entries): void
    {
        $oldBy = $this->indexSections($old);
        foreach ($new as $sec) {
            if (!is_array($sec)) {
                continue;
            }
            $key = $this->sectionKey($sec);
            $oldSec = $oldBy[$key] ?? [];
            $section = (string)($sec['MIGX_formname'] ?? $sec['section_name'] ?? '');
            foreach ($sec as $field => $val) {
                if (in_array($field, self::SKIP, true)) {
                    continue;
                }
                $oldVal = $oldSec[$field] ?? null;
                // Массивы/записи (списки) сериализуем для сравнения; revert off.
                $scalar = is_scalar($val) || $val === null;
                $newS = $scalar ? (string)$val : json_encode($val, JSON_UNESCAPED_UNICODE);
                $oldS = is_scalar($oldVal) || $oldVal === null ? (string)$oldVal : json_encode($oldVal, JSON_UNESCAPED_UNICODE);
                if ($oldVal === null && $oldSec === []) {
                    // секции не было в старом конфиге (наследовалась) — это новый
                    // override; логируем как изменение (old пуст).
                }
                if ($oldS !== $newS) {
                    $entries[] = $this->entry('field', $section, $field, $oldS, $newS, $level, $rid, $scalar);
                }
            }
        }
    }

    private function indexSections(array $config): array
    {
        $map = [];
        foreach ($config as $sec) {
            if (is_array($sec)) {
                $map[$this->sectionKey($sec)] = $sec;
            }
        }
        return $map;
    }

    private function sectionKey(array $sec): string
    {
        return (string)($sec['MIGX_formname'] ?? $sec['section_name'] ?? '') . '|' . (string)($sec['lexicon_prefix'] ?? '');
    }

    private function entry(string $type, string $section, string $field, ?string $old, ?string $new, string $level, int $rid, bool $revertable): array
    {
        $address = ['type' => $type, 'fieldName' => $field, 'level' => $level, 'resourceId' => $rid, 'source' => 'admin'];
        if ($type === 'field') {
            $address['section'] = $section;
        }
        return [
            'action'     => $type === 'field' ? 'field' : $type,
            'section'    => $section,
            'field'      => $field,
            'address'    => json_encode($address, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'old'        => $old,
            'new'        => $new,
            'revertable' => $revertable ? 1 : 0,
        ];
    }

    private function write(array $entries, int $rid): void
    {
        $user = $this->modx->user;
        $uid = $user ? (int)$user->get('id') : 0;
        $uname = $user ? (string)$user->get('username') : '';
        $sql = "INSERT INTO {$this->table}
            (resource_id, user_id, username, action, section, field, address, old_value, new_value, revertable, reverted, created_on)
            VALUES (?,?,?,?,?,?,?,?,?,?,0,?)";
        try {
            $stmt = $this->modx->prepare($sql);
            foreach ($entries as $e) {
                $stmt->execute([
                    $rid, $uid, $uname, $e['action'], $e['section'], $e['field'],
                    $e['address'], $e['old'], $e['new'], $e['revertable'], time(),
                ]);
            }
        } catch (\Throwable $ex) {
            $this->modx->log(\modX::LOG_LEVEL_WARN, '[mpc AdminAudit] ' . $ex->getMessage());
        }
    }
}
