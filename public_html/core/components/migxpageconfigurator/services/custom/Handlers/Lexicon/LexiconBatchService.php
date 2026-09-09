<?php

namespace MpcServices\Handlers\Lexicon;

/**
 * Пакетный фасад над словарём: снимок → план → применение → чистка.
 *
 * Одна реализация слияния на всех потребителей: импорт XLSX в админке, правка
 * ключа, релизный CLI (CI/CD родительской задачи) и чистка мёртвых ключей.
 * Второй реализации сравнения в shell-скриптах быть не должно — расхождение
 * правил слияния между админкой и релизом означает потерянные переводы.
 *
 * Порядок работы потребителя:
 *   1. snapshot() при выгрузке — запоминаем, что показали менеджеру;
 *   2. planImport() при загрузке — трёхстороннее сравнение, dry-run по сути;
 *   3. apply() — запись под блокировкой с повторной сверкой expected.
 */
class LexiconBatchService
{
    private LexiconStore $store;
    private SnapshotStore $snapshots;
    private OrphanRegistry $orphans;
    /** Язык, по файлам которого определяется существование ресурса. */
    private string $defaultLang;
    /** Файл лексикона секции статических блоков (вкладка `Static`). */
    private string $staticFile;

    public function __construct(
        LexiconStore $store,
        SnapshotStore $snapshots,
        OrphanRegistry $orphans,
        string $defaultLang = 'ru',
        string $staticFile = 'static'
    ) {
        $this->store       = $store;
        $this->snapshots   = $snapshots;
        $this->orphans     = $orphans;
        $this->defaultLang = $defaultLang;
        $this->staticFile  = $staticFile;
    }

    /** Сборка из настроек MODX — единственное место, где известны пути пакета. */
    public static function fromModx(\modX $modx, ?callable $sanitizer = null): self
    {
        $corePath = $modx->getOption(
            'migxpageconfigurator_core_path',
            null,
            $modx->getOption('core_path') . 'components/migxpageconfigurator/'
        );
        $lexiconBase = $modx->getOption('core_path')
            . $modx->getOption('mpc_lexicon_path', null, 'components/migxpageconfigurator/lexicon/');

        return new self(
            new LexiconStore($lexiconBase, $sanitizer),
            new SnapshotStore(rtrim($corePath, '/') . '/snapshots/'),
            new OrphanRegistry($lexiconBase),
            (string)$modx->getOption('mpc_default_language', null, 'ru'),
            (string)$modx->getOption('mpc_static_blocks_page_lexicon_filename', null, 'static')
        );
    }

    public function store(): LexiconStore
    {
        return $this->store;
    }

    public function snapshots(): SnapshotStore
    {
        return $this->snapshots;
    }

    public function orphans(): OrphanRegistry
    {
        return $this->orphans;
    }

    /**
     * Снимок выгружаемой области. Читается под блокировкой: иначе в снимок
     * попало бы состояние «в процессе записи» другого писателя, и импорт по
     * такой базе решил бы, что менеджер поменял то, чего не менял.
     *
     * @param string[] $rids  файлы лексикона, попавшие в выгрузку
     * @param string[] $langs языки-колонки выгрузки
     * @return array{id:string,entries:array}
     */
    public function snapshot(array $rids, array $langs, string $mode = '', ?int $userId = null): array
    {
        $entries = $this->store->withLock(function (LexiconStore $s) use ($rids, $langs): array {
            $out = [];
            foreach ($rids as $rid) {
                foreach ($langs as $lang) {
                    $kv = $s->read((string)$lang, (string)$rid);
                    // Пустой набор тоже пишем: он значит «в этом языке файла не
                    // было», и импорт отличит новый ключ от изменённого.
                    $out[(string)$rid][(string)$lang] = $kv;
                }
            }
            return $out;
        });

        $this->snapshots->sweep();
        $id = $this->snapshots->create($entries, [
            'mode' => $mode, 'rids' => $rids, 'langs' => $langs,
        ], $userId);

        return ['id' => $id, 'entries' => $entries];
    }

    /**
     * План импорта книги. Снимок обязателен: без него отличить правку менеджера
     * от старого значения невозможно, поэтому вместо слепого импорта
     * возвращается ошибка со ссылкой на повторный экспорт.
     *
     * @param array  $desired    lang => rid => key => value (разобранная книга)
     * @param string $snapshotId из скрытого листа `_meta`
     * @return array{error:string,ops:array,summary:array,conflicts:array,snapshot:array}
     */
    public function planImport(array $desired, string $snapshotId): array
    {
        $empty = ['ops' => [], 'summary' => [], 'conflicts' => [], 'snapshot' => []];

        if ($snapshotId === '') {
            return ['error' => 'no-snapshot'] + $empty;
        }
        $reject = $this->snapshots->reject($snapshotId);
        if ($reject !== '') {
            return ['error' => $reject] + $empty; // unknown | format
        }
        $snapshot = $this->snapshots->load($snapshotId);
        if ($snapshot === null) {
            return ['error' => 'unknown'] + $empty;
        }

        $base = self::baseFromSnapshot($snapshot);
        $ops  = $this->store->withLock(function (LexiconStore $s) use ($base, $desired): array {
            $current = [];
            foreach ($desired as $lang => $byRid) {
                foreach ((array)$byRid as $rid => $_) {
                    $current[(string)$lang][(string)$rid] = $s->read((string)$lang, (string)$rid);
                }
            }
            return LexiconMerge::plan($base, $desired, $current);
        });

        return [
            'error'     => '',
            'ops'       => $ops,
            'summary'   => LexiconMerge::summary($ops),
            'conflicts' => LexiconMerge::conflicts($ops),
            'snapshot'  => [
                'snapshot_id' => $snapshot['snapshot_id'] ?? $snapshotId,
                'exported_at' => $snapshot['exported_at'] ?? '',
                'scope'       => $snapshot['scope'] ?? [],
            ],
        ];
    }

    /**
     * Применить план. Решения по конфликтам (`address => 'mine'|'server'`)
     * пересчитываются на СВЕЖЕМ значении файла: изменилось после показа —
     * запись не делается, запись возвращается как конфликт снова.
     *
     * @param array $ops         операции плана
     * @param array $resolutions ключ {@see self::address} => 'mine'|'server'
     * @param array $opts        ['dryRun'=>bool, 'tag'=>string]
     */
    public function apply(array $ops, array $resolutions = [], array $opts = []): array
    {
        $dryRun = !empty($opts['dryRun']);
        $tag    = (string)($opts['tag'] ?? 'import');

        return $this->store->withLock(function (LexiconStore $s) use ($ops, $resolutions, $dryRun, $tag): array {
            $current  = $s->currentFor($ops);
            $resolved = [];
            foreach ($ops as $op) {
                if (($op['action'] ?? '') === LexiconMerge::CONFLICT) {
                    $addr = self::address($op);
                    $raw  = $resolutions[$addr] ?? '';
                    // Решение приходит либо строкой, либо парой с тем серверным
                    // значением, которое менеджеру ПОКАЗАЛИ. Второе точнее:
                    // между показом конфликта и нажатием кнопки файл могли
                    // переписать, и тогда решение принято по устаревшим данным.
                    $decision = is_array($raw) ? (string)($raw['decision'] ?? '') : (string)$raw;
                    if ($decision === '') {
                        $resolved[] = $op; // не решено — так и остаётся конфликтом
                        continue;
                    }
                    $fresh = $current[$op['lang']][$op['rid']][$op['key']] ?? null;
                    $op['seenCurrent'] = is_array($raw) && array_key_exists('seen', $raw)
                        ? ($raw['seen'] === null ? null : (string)$raw['seen'])
                        : $op['current'];
                    $resolved[] = LexiconMerge::resolve($op, $decision, $fresh === null ? null : (string)$fresh);
                    continue;
                }
                $resolved[] = $op;
            }

            $summary = LexiconMerge::summary($resolved);
            if ($dryRun) {
                return [
                    'dryRun' => true, 'summary' => $summary,
                    'conflicts' => LexiconMerge::conflicts($resolved),
                    'applied' => 0, 'cleared' => 0, 'stale' => [], 'failed' => [],
                    'touched' => [], 'backup' => '', 'appliedOps' => [],
                ];
            }

            $res = $s->apply($resolved, ['backup' => $this->snapshots, 'tag' => $tag]);
            return $res + [
                'dryRun' => false,
                'summary' => $summary,
                'conflicts' => LexiconMerge::conflicts($resolved),
            ];
        });
    }

    /**
     * Чистка мёртвых ключей по реестру кандидатов. По умолчанию dry-run: список
     * возвращается, файлы не трогаются. Реальное удаление делает бэкап и идёт
     * через ту же сверку expected, что и импорт.
     *
     * @param array $only  ограничение rid => [ключи]; пусто — все кандидаты
     */
    public function prune(string $lang, array $only = [], bool $dryRun = true, int $olderThanDays = 0): array
    {
        $candidates = $this->orphans->all($lang, $olderThanDays);
        $ops = [];
        foreach ($candidates as $rid => $entries) {
            foreach ($entries as $key => $meta) {
                if (!empty($only) && !in_array($key, (array)($only[$rid] ?? []), true)) {
                    continue;
                }
                $currentValue = $this->store->read($lang, (string)$rid)[$key] ?? null;
                if ($currentValue === null) {
                    continue; // ключа уже нет — реестр подчистим ниже
                }
                // Ожидаем ИМЕННО то значение, с которым ключ попал в реестр:
                // если перевод с тех пор правили руками, ключ нужен человеку и
                // удалён не будет (вернётся как stale).
                $expected = array_key_exists('value', $meta) ? (string)$meta['value'] : (string)$currentValue;
                $ops[] = [
                    'lang' => $lang, 'rid' => (string)$rid, 'key' => (string)$key,
                    'action' => LexiconMerge::CLEAR, 'reason' => 'prune-orphan',
                    'base' => $expected, 'current' => $expected,
                    'desired' => null, 'seen_at' => (string)($meta['seen_at'] ?? ''),
                ];
            }
        }

        if ($dryRun || empty($ops)) {
            return ['dryRun' => true, 'candidates' => $ops, 'cleared' => 0, 'backup' => '', 'stale' => []];
        }

        $res = $this->store->apply($ops, ['backup' => $this->snapshots, 'tag' => 'prune']);

        // С учёта снимаем только реально удалённое: устаревшие (кто-то вернул
        // значение) остаются кандидатами до следующего разбора.
        $stale = [];
        foreach ($res['stale'] as $op) {
            $stale[self::address($op)] = true;
        }
        $forget = [];
        foreach ($ops as $op) {
            if (!isset($stale[self::address($op)])) {
                $forget[$op['rid']][] = $op['key'];
            }
        }
        foreach ($forget as $rid => $keys) {
            $this->orphans->forget($lang, (string)$rid, $keys);
        }

        return ['dryRun' => false, 'candidates' => $ops] + $res;
    }

    /**
     * Разбор листов книги в план вкладок: id, целевой файл лексикона, языки и
     * данные. Служебные листы пропускаются, нераспознанные остаются с пустым
     * target — их менеджер сопоставляет руками в превью, а CLI пропускает.
     *
     * @param array $sheets результат {@see WorkbookReader::read}
     */
    public function sheetPlan(array $sheets): array
    {
        $existingRids = $this->store->existingRids($this->defaultLang);
        $manifests    = self::collectManifests($sheets);

        $out = [];
        foreach ($sheets as $i => $s) {
            if (\MpcServices\Handlers\LexiconImport::isServiceSheet($s['sheet'])) {
                continue;
            }
            $parsed = \MpcServices\Handlers\LexiconImport::sheetToData($s['headers'], $s['rows']);
            if (empty($parsed['data'])) {
                continue; // лист без колонки ключа / без строк
            }
            // манифест ищем в пределах СВОЕЙ книги: в ZIP их несколько
            $manifestRid = $manifests[$s['book']][mb_strtolower($s['sheet'], 'UTF-8')] ?? null;
            $target = \MpcServices\Handlers\LexiconImport::resolveTarget(
                $s['sheet'],
                $existingRids,
                $this->staticFile,
                $manifestRid
            );
            $out[] = [
                'id'     => $i,
                'file'   => $s['file'],
                'sheet'  => $s['sheet'],
                'target' => (string)($target ?? ''),
                'langs'  => $parsed['langs'],
                'data'   => $parsed['data'],
            ];
        }
        return $out;
    }

    /**
     * Желаемое состояние из книги: lang => rid => key => value. `$only`
     * (id вкладки → выбранный target) ограничивает набор выбором менеджера и
     * учитывает ручной ремап; null — берём все распознанные вкладки.
     */
    public function desiredFromPlan(array $sheetsPlan, ?array $only = null): array
    {
        $desired = [];
        foreach ($sheetsPlan as $sp) {
            $target = $only === null ? $sp['target'] : basename((string)($only[$sp['id']] ?? ''));
            if ($target === '') {
                continue;
            }
            // Целевой файл дефолтного языка должен существовать: импорт правит
            // словарь, а не заводит ресурсы.
            if (!is_file($this->store->path($this->defaultLang, $target))) {
                continue;
            }
            foreach ($sp['data'] as $key => $langVals) {
                foreach ($langVals as $lang => $value) {
                    // Заголовок колонки под контролем загружающего: 'en/../..'
                    // увёл бы запись за пределы каталога лексиконов, а
                    // basename превратил бы мусор в правдоподобный язык —
                    // поэтому проверяем ИСХОДНУЮ строку заголовка целиком.
                    $lang = (string)$lang;
                    if (!preg_match('/^[a-z]{2,8}$/', $lang)) {
                        continue;
                    }
                    $desired[$lang][$target][(string)$key] = (string)$value;
                }
            }
        }
        return $desired;
    }

    /**
     * Карты «вкладка → rid» из скрытых листов `__mpc`, по книгам.
     * Ключ — $s['book'] (уникален даже при совпадении basename внутри ZIP):
     * манифест одной книги не должен применяться к вкладкам другой.
     *
     * @return array<string,array<string,string>> book => (sheetLower => rid)
     */
    public static function collectManifests(array $sheets): array
    {
        $out = [];
        foreach ($sheets as $s) {
            if ($s['sheet'] !== \MpcServices\Handlers\LexiconImport::MANIFEST_SHEET) {
                continue;
            }
            $map = \MpcServices\Handlers\LexiconImport::parseManifest($s['headers'], $s['rows']);
            if (!empty($map)) {
                $out[$s['book']] = $map;
            }
        }
        return $out;
    }

    /** Идентификатор снимка из скрытого листа `_meta` (пусто — паспорта нет). */
    public static function snapshotIdFrom(array $sheets): string
    {
        foreach ($sheets as $s) {
            if ($s['sheet'] !== \MpcServices\Handlers\LexiconImport::META_SHEET) {
                continue;
            }
            $meta = \MpcServices\Handlers\LexiconImport::parseMeta($s['headers'], $s['rows']);
            $id   = (string)($meta['snapshot_id'] ?? '');
            if ($id !== '') {
                return $id;
            }
        }
        return '';
    }

    /**
     * Человеческий текст отказа. Слепой импорт запрещён намеренно: книга без
     * снимка (или со снимком, которого уже нет) не позволяет отличить правку
     * менеджера от старого значения, поэтому единственный безопасный ответ —
     * попросить свежую выгрузку.
     */
    public static function snapshotErrorText(string $error): string
    {
        $texts = [
            'no-snapshot' => 'В файле нет служебного листа _meta: он либо собран вручную, либо выгружен старой версией пакета. Импорт остановлен, чтобы не затереть правки на сервере. Сделайте свежий экспорт и внесите изменения в него.',
            'unknown'     => 'Снимок этой выгрузки на сервере не найден: он старше срока хранения или сделан на другом сайте. Импорт остановлен, чтобы не затереть правки на сервере. Сделайте свежий экспорт.',
            'format'      => 'Формат снимка не поддерживается этой версией пакета. Сделайте свежий экспорт и повторите импорт.',
        ];
        return $texts[$error] ?? $texts['unknown'];
    }

    /** Адрес записи одной строкой — ключ карты решений по конфликтам. */
    public static function address(array $op): string
    {
        return ($op['lang'] ?? '') . '|' . ($op['rid'] ?? '') . '|' . ($op['key'] ?? '');
    }

    /**
     * Снимок хранится как rid => lang => kv (удобно писать при экспорте), а
     * сравнение работает в порядке lang => rid => kv. Отсутствие ключа в снимке
     * означает «ключа не было» и остаётся отсутствием, а не пустой строкой.
     */
    public static function baseFromSnapshot(array $snapshot): array
    {
        $out = [];
        foreach ((array)($snapshot['entries'] ?? []) as $rid => $byLang) {
            foreach ((array)$byLang as $lang => $kv) {
                $out[(string)$lang][(string)$rid] = (array)$kv;
            }
        }
        return $out;
    }
}
