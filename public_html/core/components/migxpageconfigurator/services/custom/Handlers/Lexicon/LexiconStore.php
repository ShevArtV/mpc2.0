<?php

namespace MpcServices\Handlers\Lexicon;

/**
 * Единственная точка чтения и записи файлов лексикона для всех писателей:
 * импорт XLSX, правка ключа в CMP, визуальный редактор, релизный CLI.
 *
 * Зачем один класс вместо трёх копий read-modify-write: между «посчитали план»
 * и «записали» в файл мог написать кто-то другой. Здесь весь цикл — чтение,
 * повторная сверка ожидаемого значения и запись — идёт под ОДНОЙ файловой
 * блокировкой (`<base>/.mpc-lexicon.lock`), а сама запись атомарна
 * (temp + rename), поэтому читатель никогда не видит полуфайл.
 *
 * Сверка expected обязательна: операция с несовпавшим `current` не пишется, а
 * возвращается со статусом `stale`. Тихой перезаписи чужой правки быть не может
 * ни при каком стечении обстоятельств.
 *
 * PURE file-IO, без modX.
 */
class LexiconStore
{
    /** Имя lock-файла. Точка-префикс прячет его от сканов `*.inc.php`. */
    private const LOCK_FILE = '.mpc-lexicon.lock';

    /** Файлы лексикона, которые не принадлежат ресурсам и не трогаются. */
    private const SYSTEM_RIDS = ['default', 'properties', 'setting'];

    private string $basePath;
    /** @var callable|null fn(string $value): string */
    private $sanitizer;
    /** @var resource|null */
    private $lockHandle = null;
    private int $lockDepth = 0;

    public function __construct(string $lexiconBasePath, ?callable $sanitizer = null)
    {
        $this->basePath = rtrim($lexiconBasePath, '/') . '/';
        $this->sanitizer = $sanitizer;
    }

    /** Путь к файлу лексикона. Компоненты пути чистятся от traversal. */
    public function path(string $lang, string $rid): string
    {
        return $this->basePath . basename($lang) . '/' . basename($rid) . '.inc.php';
    }

    /** Содержимое файла: key => value. Нет файла — пустой массив. */
    public function read(string $lang, string $rid): array
    {
        $p = $this->path($lang, $rid);
        if (!is_file($p)) {
            return [];
        }
        $_lang = [];
        include $p;
        return is_array($_lang) ? $_lang : [];
    }

    /**
     * Значения по нескольким языкам и файлам разом: lang => rid => key => value.
     * Используется для сборки снимка при экспорте и `current` при импорте.
     */
    public function readMany(array $langs, array $rids): array
    {
        $out = [];
        foreach ($langs as $lang) {
            foreach ($rids as $rid) {
                $out[(string)$lang][(string)$rid] = $this->read((string)$lang, (string)$rid);
            }
        }
        return $out;
    }

    /** Существующие файлы лексикона языка (rid без расширения), без служебных. */
    public function existingRids(string $lang): array
    {
        $out = [];
        foreach (glob($this->basePath . basename($lang) . '/*.inc.php') ?: [] as $f) {
            $rid = basename($f, '.inc.php');
            if (!in_array($rid, self::SYSTEM_RIDS, true)) {
                $out[] = $rid;
            }
        }
        return $out;
    }

    /** Языки (каталоги) хранилища. */
    public function languages(): array
    {
        $out = [];
        foreach (glob($this->basePath . '*', GLOB_ONLYDIR) ?: [] as $d) {
            $name = basename($d);
            if (preg_match('/^[a-z]{2,8}(-[a-z]{2,8})?$/i', $name)) {
                $out[] = $name;
            }
        }
        return $out;
    }

    /**
     * Выполнить работу под общей блокировкой писателей. Вложенные вызовы
     * переиспользуют уже взятую блокировку (иначе apply внутри withLock
     * подвесил бы сам себя).
     *
     * @param callable $fn fn(LexiconStore $store): mixed
     * @return mixed результат $fn
     */
    public function withLock(callable $fn)
    {
        $this->acquire();
        try {
            return $fn($this);
        } finally {
            $this->release();
        }
    }

    /**
     * Применить план {@see LexiconMerge}. Внутри блокировки значения читаются
     * заново и сверяются с `current` из плана; несовпадение — `stale`, запись
     * не делается.
     *
     * @param array $ops  операции плана (write/clear/noop/skip/conflict)
     * @param array $opts ['backup' => SnapshotStore|null, 'tag' => string]
     * @return array{applied:int,cleared:int,stale:array,failed:array,backup:string,touched:array}
     */
    public function apply(array $ops, array $opts = []): array
    {
        return $this->withLock(function () use ($ops, $opts): array {
            $result = [
                'applied' => 0, 'cleared' => 0,
                'stale' => [], 'failed' => [], 'backup' => '', 'touched' => [],
                // Реально записанные операции: по ним вызывающий обновляет
                // сопутствующие реестры (непереведённое, кандидаты на удаление).
                'appliedOps' => [],
            ];

            // Группируем по файлу: один файл переписывается один раз.
            $byFile = [];
            foreach ($ops as $op) {
                $action = (string)($op['action'] ?? '');
                if ($action !== LexiconMerge::WRITE && $action !== LexiconMerge::CLEAR) {
                    continue;
                }
                $byFile[(string)$op['lang']][(string)$op['rid']][] = $op;
            }
            if (empty($byFile)) {
                return $result;
            }

            // Бэкап затронутых файлов ДО записи: единственный способ вернуть
            // словарь, если применение оборвётся на середине набора.
            $store = $opts['backup'] ?? null;
            if ($store instanceof SnapshotStore) {
                $entries = [];
                foreach ($byFile as $lang => $byRid) {
                    foreach ($byRid as $rid => $_) {
                        $entries[$rid][$lang] = $this->read((string)$lang, (string)$rid);
                    }
                }
                $result['backup'] = $store->backup((string)($opts['tag'] ?? 'apply'), $entries);
            }

            foreach ($byFile as $lang => $byRid) {
                foreach ($byRid as $rid => $fileOps) {
                    $kv = $this->read((string)$lang, (string)$rid);
                    $dirty = false;
                    $fileAppliedOps = [];

                    foreach ($fileOps as $op) {
                        $key      = (string)$op['key'];
                        $expected = array_key_exists('current', $op) ? $op['current'] : null;
                        $actual   = array_key_exists($key, $kv) ? (string)$kv[$key] : null;

                        if ($actual !== ($expected === null ? null : (string)$expected)) {
                            // Кто-то записал между планированием и применением.
                            $result['stale'][] = $op + ['actual' => $actual];
                            continue;
                        }

                        if ((string)$op['action'] === LexiconMerge::CLEAR) {
                            unset($kv[$key]);
                        } else {
                            $kv[$key] = $this->sanitize((string)$op['desired']);
                        }
                        $fileAppliedOps[] = $op;
                        $dirty = true;
                    }

                    if (!$dirty) {
                        continue;
                    }
                    if ($this->write((string)$lang, (string)$rid, $kv)) {
                        $result['touched'][] = $lang . '/' . $rid;
                        foreach ($fileAppliedOps as $appliedOp) {
                            if ((string)$appliedOp['action'] === LexiconMerge::CLEAR) {
                                $result['cleared']++;
                            } else {
                                $result['applied']++;
                            }
                            $result['appliedOps'][] = $appliedOp;
                        }
                    } else {
                        $result['failed'][] = $lang . '/' . $rid;
                    }
                }
            }

            return $result;
        });
    }

    /**
     * Записать файл целиком. Атомарно: temp в том же каталоге + rename, чтобы
     * читатель (в том числе MODX, который include-ит файл) не увидел обрезанное
     * содержимое. Пустой набор — файл удаляется.
     */
    public function write(string $lang, string $rid, array $kv): bool
    {
        $path = $this->path($lang, $rid);
        $dir  = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return false;
        }

        if (empty($kv)) {
            return is_file($path) ? unlink($path) : true;
        }

        ksort($kv);
        $content = '<?php' . PHP_EOL;
        foreach ($kv as $k => $v) {
            // var_export ключа И значения — синтаксически корректный PHP-литерал
            // при любых кавычках и бэкслешах, плюс защита от инъекции через ключ.
            $content .= '$_lang[' . var_export((string)$k, true) . '] = '
                . var_export((string)$v, true) . ';' . PHP_EOL;
        }

        $tmp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (file_put_contents($tmp, $content, LOCK_EX) === false) {
            @unlink($tmp);
            return false;
        }
        if (!rename($tmp, $path)) {
            @unlink($tmp);
            return false;
        }
        @chmod($path, 0664);
        return true;
    }

    /** Значения ключей файла в виде lang=>rid=>kv для набора операций плана. */
    public function currentFor(array $ops): array
    {
        $out = [];
        foreach ($ops as $op) {
            $lang = (string)($op['lang'] ?? '');
            $rid  = (string)($op['rid'] ?? '');
            if ($lang === '' || $rid === '' || isset($out[$lang][$rid])) {
                continue;
            }
            $out[$lang][$rid] = $this->read($lang, $rid);
        }
        return $out;
    }

    private function sanitize(string $value): string
    {
        return $this->sanitizer === null ? $value : (string)call_user_func($this->sanitizer, $value);
    }

    private function acquire(): void
    {
        if ($this->lockDepth > 0) {
            $this->lockDepth++;
            return;
        }
        if (!is_dir($this->basePath) && !mkdir($this->basePath, 0755, true) && !is_dir($this->basePath)) {
            throw new \RuntimeException('Не создан каталог блокировки лексиконов: ' . $this->basePath);
        }
        $h = fopen($this->basePath . self::LOCK_FILE, 'c');
        if ($h === false) {
            throw new \RuntimeException('Не открыт lock-файл лексиконов');
        }
        if (!flock($h, LOCK_EX)) {
            fclose($h);
            throw new \RuntimeException('Не взята блокировка лексиконов');
        }
        $this->lockHandle = $h;
        $this->lockDepth = 1;
    }

    private function release(): void
    {
        if (--$this->lockDepth > 0) {
            return;
        }
        $this->lockDepth = 0;
        if (is_resource($this->lockHandle)) {
            flock($this->lockHandle, LOCK_UN);
            fclose($this->lockHandle);
        }
        $this->lockHandle = null;
    }
}
