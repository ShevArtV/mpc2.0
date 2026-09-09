<?php

namespace MpcServices\Handlers\Lexicon;

/**
 * Реестр кандидатов на удаление — ключей, которые нарезка перестала встречать
 * в вёрстке.
 *
 * До этого нарезка вычищала такие ключи молча и вместе с переводами: 01.09.2026
 * так был потерян набор ключей на живом сервере. Теперь запись только
 * ОТМЕЧАЕТСЯ здесь, а удаление — отдельное явное действие (кнопка «Мёртвые
 * ключи» в CMP или `mpc.php lexicon prune`), с бэкапом и dry-run.
 *
 * В реестр попадают только ключи с префиксом нарезаемой секции: ручные ключи и
 * общие файлы (common и подобные) нарезка не отслеживает и трогать не должна.
 *
 * Хранилище: `<lexiconBase>/<lang>/.orphan/<rid>.json`
 *   {"<key>": {"value": "…", "seen_at": "2026-09-09T12:00:00+03:00", "prefix": "hero"}}
 *
 * Точка-префикс и `.json` прячут файлы от сканов лексиконов (`*.inc.php`),
 * ровно как у реестра непереведённых.
 *
 * PURE file-IO, без modX.
 */
class OrphanRegistry
{
    private string $basePath;

    public function __construct(string $lexiconBasePath)
    {
        $this->basePath = rtrim($lexiconBasePath, '/') . '/';
    }

    private function path(string $lang, string $rid): string
    {
        return $this->basePath . basename($lang) . '/.orphan/' . basename($rid) . '.json';
    }

    /** Кандидаты языка/ресурса: key => ['value'=>…, 'seen_at'=>…, 'prefix'=>…]. */
    public function load(string $lang, string $rid): array
    {
        $p = $this->path($lang, $rid);
        if (!is_file($p)) {
            return [];
        }
        $data = json_decode((string)file_get_contents($p), true);
        return is_array($data) ? $data : [];
    }

    /** Перезаписать реестр (пустой — файл удаляется). */
    public function save(string $lang, string $rid, array $entries): void
    {
        $p = $this->path($lang, $rid);
        if (empty($entries)) {
            if (is_file($p)) {
                unlink($p);
            }
            return;
        }
        $dir = dirname($p);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents(
            $p,
            (string)json_encode($entries, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            LOCK_EX
        );
    }

    /**
     * Отметить ключи как кандидатов. Дата первого обнаружения сохраняется:
     * по ней видно, сколько ключ уже «мёртвый», и её же смотрит чистка.
     *
     * @param array $keyValues key => value (значение на момент обнаружения)
     */
    public function record(string $lang, string $rid, array $keyValues, string $prefix = ''): void
    {
        if (empty($keyValues)) {
            return;
        }
        $entries = $this->load($lang, $rid);
        $now = date('c');
        foreach ($keyValues as $key => $value) {
            $key = (string)$key;
            if (isset($entries[$key])) {
                $entries[$key]['value'] = (string)$value;
                continue; // seen_at не сдвигаем: важен возраст, а не последний проход
            }
            $entries[$key] = ['value' => (string)$value, 'seen_at' => $now, 'prefix' => $prefix];
        }
        $this->save($lang, $rid, $entries);
    }

    /**
     * Снять ключи с учёта — поле вернулось в вёрстку либо ключ уже удалён.
     * Зовётся и нарезкой (ключ снова встретился), и чисткой (ключ удалён).
     */
    public function forget(string $lang, string $rid, array $keys): void
    {
        if (empty($keys)) {
            return;
        }
        $entries = $this->load($lang, $rid);
        if (empty($entries)) {
            return;
        }
        foreach ($keys as $k) {
            unset($entries[(string)$k]);
        }
        $this->save($lang, $rid, $entries);
    }

    /**
     * Все кандидаты языка: rid => (key => запись). Для списка в CMP и для CLI.
     * `$olderThanDays > 0` оставляет только достаточно старые записи — чтобы
     * не предлагать к удалению то, что нарезали минуту назад.
     */
    public function all(string $lang, int $olderThanDays = 0): array
    {
        $dir = $this->basePath . basename($lang) . '/.orphan/';
        if (!is_dir($dir)) {
            return [];
        }
        $deadline = $olderThanDays > 0 ? time() - $olderThanDays * 86400 : null;

        $out = [];
        foreach (glob($dir . '*.json') ?: [] as $f) {
            $rid = basename($f, '.json');
            $entries = $this->load($lang, $rid);
            if ($deadline !== null) {
                $entries = array_filter($entries, static function ($e) use ($deadline) {
                    $ts = strtotime((string)($e['seen_at'] ?? ''));
                    return $ts !== false && $ts <= $deadline;
                });
            }
            if (!empty($entries)) {
                $out[$rid] = $entries;
            }
        }
        return $out;
    }
}
