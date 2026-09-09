<?php

namespace MpcServices\Handlers\Lexicon;

/**
 * Хранилище снимков лексиконов: неизменяемая копия того, что ушло менеджеру в
 * XLSX. Снимок — база трёхстороннего сравнения при импорте ({@see LexiconMerge}):
 * без него импорт не может отличить «менеджер изменил ячейку» от «ячейка просто
 * старая», и старый файл затирает свежие правки на сервере.
 *
 * Формат файла `<base>/<snapshot_id>.json`:
 *   {
 *     "snapshot_id": "snp_…", "format_version": 1,
 *     "exported_at": "2026-09-09T12:00:00+03:00", "created_by": 12,
 *     "scope": {"mode": "exportallinone", "rids": [...], "langs": [...]},
 *     "entries": {"<rid>": {"<lang>": {"<key>": "значение"|null}}}
 *   }
 *
 * `null` в entries — «ключа в этом языке НЕ БЫЛО» (осознанно отличается от
 * пустой строки: карточка требует различать отсутствие, пустоту и очистку).
 *
 * Хранилище живёт под `core/` (вне публичной выдачи) и дополнительно закрыто
 * `index.php` + `.htaccess` на случай нестандартного webroot. Идентификатор
 * снимка авторизацией НЕ является: права проверяет процессор, а область
 * (ресурсы/языки) сверяется по полю scope.
 *
 * PURE file-IO, без modX — юнит-тестируемо.
 */
class SnapshotStore
{
    /** Версия формата снимка. Растёт при несовместимом изменении структуры. */
    public const FORMAT_VERSION = 1;

    /** Срок жизни снимка по умолчанию, суток. */
    public const DEFAULT_TTL_DAYS = 30;

    /** Идентификатор снимка: только он допускается в имени файла. */
    private const ID_PATTERN = '/^snp_[a-f0-9]{24}$/';

    private string $basePath;

    public function __construct(string $basePath)
    {
        $this->basePath = rtrim($basePath, '/') . '/';
    }

    /** Каталог снимков (создаётся при первой записи). */
    public function basePath(): string
    {
        return $this->basePath;
    }

    /**
     * Сохранить снимок выгруженных записей.
     *
     * @param array $entries rid => lang => key => value|null
     * @param array $scope   ['mode'=>string, 'rids'=>string[], 'langs'=>string[]]
     * @return string snapshot_id
     */
    public function create(array $entries, array $scope = [], ?int $userId = null): string
    {
        $id = 'snp_' . bin2hex(random_bytes(12));
        $payload = [
            'snapshot_id'    => $id,
            'format_version' => self::FORMAT_VERSION,
            'exported_at'    => date('c'),
            'created_by'     => $userId,
            'scope'          => [
                'mode'  => (string)($scope['mode'] ?? ''),
                'rids'  => array_values(array_map('strval', (array)($scope['rids'] ?? []))),
                'langs' => array_values(array_map('strval', (array)($scope['langs'] ?? []))),
            ],
            'entries'        => $entries,
        ];

        $this->ensureDir();
        $path = $this->basePath . $id . '.json';
        // JSON_PARTIAL_OUTPUT_ON_ERROR не ставим: снимок с дырами хуже, чем его
        // отсутствие — импорт по неполной базе даст ложные «конфликтов нет».
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('snapshot encode failed: ' . json_last_error_msg());
        }
        if (file_put_contents($path, $json, LOCK_EX) === false) {
            throw new \RuntimeException('snapshot write failed: ' . $path);
        }
        return $id;
    }

    /** Снимок по идентификатору. null — нет такого, битый или чужой формат. */
    public function load(string $id): ?array
    {
        if (!preg_match(self::ID_PATTERN, $id)) {
            return null;
        }
        $path = $this->basePath . $id . '.json';
        if (!is_file($path)) {
            return null;
        }
        $data = json_decode((string)file_get_contents($path), true);
        if (!is_array($data) || !isset($data['entries']) || !is_array($data['entries'])) {
            return null;
        }
        if ((int)($data['format_version'] ?? 0) !== self::FORMAT_VERSION) {
            return null; // чужая/будущая версия формата — слепой импорт запрещён
        }
        return $data;
    }

    /** Есть ли пригодный снимок (без разбора содержимого вызывающим). */
    public function exists(string $id): bool
    {
        return $this->load($id) !== null;
    }

    /**
     * Причина отказа для непригодного снимка: 'unknown' (нет файла/битый),
     * 'format' (не та версия формата), '' — снимок в порядке.
     */
    public function reject(string $id): string
    {
        if (!preg_match(self::ID_PATTERN, $id) || !is_file($this->basePath . $id . '.json')) {
            return 'unknown';
        }
        $data = json_decode((string)file_get_contents($this->basePath . $id . '.json'), true);
        if (!is_array($data) || !isset($data['entries'])) {
            return 'unknown';
        }
        return (int)($data['format_version'] ?? 0) === self::FORMAT_VERSION ? '' : 'format';
    }

    /** Удалить снимок. */
    public function delete(string $id): void
    {
        if (preg_match(self::ID_PATTERN, $id) && is_file($this->basePath . $id . '.json')) {
            unlink($this->basePath . $id . '.json');
        }
    }

    /**
     * Удалить снимки старше TTL. Зовётся при создании нового снимка, чтобы
     * каталог не рос вечно (тот же приём, что в ExportStreamer).
     *
     * @return int сколько файлов удалено
     */
    public function sweep(int $ttlDays = self::DEFAULT_TTL_DAYS): int
    {
        if (!is_dir($this->basePath)) {
            return 0;
        }
        $deadline = time() - max(1, $ttlDays) * 86400;
        $n = 0;
        foreach (glob($this->basePath . 'snp_*.json') ?: [] as $f) {
            if (@filemtime($f) < $deadline && @unlink($f)) {
                $n++;
            }
        }
        foreach (glob($this->basePath . 'backup-*', GLOB_ONLYDIR) ?: [] as $d) {
            if (@filemtime($d) < $deadline) {
                $this->rmdirRecursive($d);
                $n++;
            }
        }
        return $n;
    }

    /**
     * Бэкап содержимого лексиконов перед разрушающей операцией (чистка мёртвых
     * ключей). Кладётся в `<base>/backup-<tag>-<дата>/<lang>/<rid>.json` и
     * подчищается тем же sweep по возрасту.
     *
     * @param array $entries rid => lang => key => value
     * @return string путь к каталогу бэкапа
     */
    public function backup(string $tag, array $entries): string
    {
        $tag = preg_replace('/[^a-z0-9_-]+/i', '-', $tag) ?: 'prune';
        $dir = $this->basePath . 'backup-' . $tag . '-' . date('Ymd-His') . '-' . bin2hex(random_bytes(6)) . '/';
        $this->ensureDir();
        foreach ($entries as $rid => $byLang) {
            foreach ((array)$byLang as $lang => $kv) {
                $target = $dir . basename((string)$lang) . '/';
                if (!is_dir($target) && !mkdir($target, 0755, true) && !is_dir($target)) {
                    throw new \RuntimeException('Не создан каталог бэкапа: ' . $target);
                }
                if (file_put_contents(
                    $target . basename((string)$rid) . '.json',
                    (string)json_encode($kv, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
                    LOCK_EX
                ) === false) {
                    throw new \RuntimeException('Не записан бэкап лексикона: ' . $target . $rid);
                }
            }
        }
        return $dir;
    }

    /** Создать каталог хранилища и закрыть его от прямой отдачи. */
    private function ensureDir(): void
    {
        if (!is_dir($this->basePath)) {
            if (!mkdir($this->basePath, 0755, true) && !is_dir($this->basePath)) {
                throw new \RuntimeException('Не создан каталог снимков: ' . $this->basePath);
            }
        }
        // Снимки содержат тексты витрины целиком; каталог лежит под core/, но
        // на нестандартном webroot core бывает доступен — закрываемся сами.
        if (!is_file($this->basePath . 'index.php')) {
            if (file_put_contents($this->basePath . 'index.php', "<?php\n// silence is golden\n") === false) {
                throw new \RuntimeException('Не закрыт каталог снимков');
            }
        }
        if (!is_file($this->basePath . '.htaccess')) {
            if (file_put_contents($this->basePath . '.htaccess', "Deny from all\nRequire all denied\n") === false) {
                throw new \RuntimeException('Не закрыт каталог снимков');
            }
        }
    }

    private function rmdirRecursive(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $p = $dir . '/' . $item;
            is_dir($p) ? $this->rmdirRecursive($p) : @unlink($p);
        }
        @rmdir($dir);
    }
}
