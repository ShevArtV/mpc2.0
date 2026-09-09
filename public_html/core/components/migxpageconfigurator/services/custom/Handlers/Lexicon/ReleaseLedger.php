<?php

namespace MpcServices\Handlers\Lexicon;

/**
 * Журнал применённых релизных манифестов.
 *
 * Зачем: CI перебирает ВСЕ манифесты каталога при каждом деплое, а манифест
 * несёт изменение относительно зафиксированной базы. После второго релиза того
 * же адреса первый манифест уже не сходится с сервером и честно даёт конфликт —
 * доставка встала бы на историческом файле. Журнал отвечает на вопрос «этот
 * манифест уже доставлен?», и повторный деплой пропускает его как noop.
 *
 * Отпечаток считается по СОДЕРЖИМОМУ (expected + desired), а не по имени файла:
 * переименование ничего не меняет, а правка манифеста делает его новым релизом.
 *
 * PURE file-IO, без modX.
 */
class ReleaseLedger
{
    public const FORMAT_VERSION = 1;

    private const FILE = 'release-ledger.json';

    private string $path;

    public function __construct(string $basePath)
    {
        $this->path = rtrim($basePath, '/') . '/' . self::FILE;
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * Отпечаток манифеста: sha256 канонизированных expected/desired. Порядок
     * ключей в JSON роли не играет, поэтому сортируем перед хешированием.
     */
    public static function fingerprint(array $manifest): string
    {
        $payload = [
            'expected' => self::canonical((array)($manifest['expected'] ?? [])),
            'desired'  => self::canonical((array)($manifest['desired'] ?? [])),
        ];

        return hash('sha256', (string)json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** Запись о применении или null, если манифест ещё не доставляли. */
    public function applied(string $fingerprint): ?array
    {
        $all = $this->all();
        $entry = $all[$fingerprint] ?? null;

        return is_array($entry) ? $entry : null;
    }

    /** Все записи журнала: отпечаток => сведения о применении. */
    public function all(): array
    {
        if (!is_file($this->path)) {
            return [];
        }
        $raw = json_decode((string)file_get_contents($this->path), true);

        return is_array($raw['releases'] ?? null) ? $raw['releases'] : [];
    }

    /**
     * Зафиксировать применение. Вызывать ПОСЛЕ успешной записи словарей и под
     * той же блокировкой писателей, что и сама запись.
     */
    public function record(string $fingerprint, array $meta = []): bool
    {
        $all = $this->all();
        $all[$fingerprint] = [
            'manifest'   => (string)($meta['manifest'] ?? ''),
            'applied_at' => date('c'),
            'applied'    => (int)($meta['applied'] ?? 0),
            'cleared'    => (int)($meta['cleared'] ?? 0),
            'touched'    => array_values((array)($meta['touched'] ?? [])),
            'backup'     => (string)($meta['backup'] ?? ''),
        ];

        return $this->save($all);
    }

    /** Забыть запись: нужен, когда релиз откатили и его надо доставить заново. */
    public function forget(string $fingerprint): bool
    {
        $all = $this->all();
        if (!array_key_exists($fingerprint, $all)) {
            return true;
        }
        unset($all[$fingerprint]);

        return $this->save($all);
    }

    private function save(array $releases): bool
    {
        $dir = dirname($this->path);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return false;
        }

        $content = (string)json_encode([
            'format_version' => self::FORMAT_VERSION,
            'updated_at'     => date('c'),
            'releases'       => $releases,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // Тот же приём, что у словарей: temp + rename, чтобы читатель не увидел
        // обрезанный журнал и повторный деплой не принял его за пустой.
        $tmp = $this->path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (file_put_contents($tmp, $content, LOCK_EX) === false) {
            @unlink($tmp);
            return false;
        }
        if (!rename($tmp, $this->path)) {
            @unlink($tmp);
            return false;
        }
        @chmod($this->path, 0664);

        return true;
    }

    /** Рекурсивная сортировка ключей: одинаковое содержимое — одинаковый хеш. */
    private static function canonical(array $data): array
    {
        ksort($data);
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::canonical($value);
            }
        }

        return $data;
    }
}
