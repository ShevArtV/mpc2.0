<?php
/**
 * Импорт лексиконов из XLSX/ZIP — двухфазный (preview → apply), НЕ зависит от
 * имени загружаемого файла: целевой ресурс определяется по скрытому листу-
 * манифесту `__mpc` (точная карта «вкладка → файл лексикона»), а при его
 * отсутствии — по имени ВКЛАДКИ (LexiconImport::resolveTarget: имя длиннее 31
 * символа Excel не хранит). Понимает формат all-in-one (колонка «Контекст»
 * + lexicon_key + языки) и старый (lexicon_key первой колонкой, листы
 * Resource/Static). Колонка ключа/языков ищется по заголовкам, а не по позиции.
 *
 *  - mode=preview: сохраняет загрузку во временный файл (token), разбирает,
 *    отдаёт план (вкладка→ресурс, что будет записано, что ждёт решения);
 *  - mode=apply: по token + выбранным вкладкам (с ремапом) применяет план,
 *    снимает переведённое с pending-реестра, чистит temp, сбрасывает кэш.
 *
 * Импорт ТРЁХСТОРОННИЙ: книга сравнивается со снимком, сделанным при экспорте
 * (скрытый лист `_meta` → snapshot_id), и с текущим состоянием файлов
 * ({@see \MpcServices\Handlers\Lexicon\LexiconMerge}). Ячейка, которую менеджер
 * не менял, не пишется вовсе, поэтому старая выгрузка больше не откатывает
 * чужие правки; расхождение показывается конфликтом. Книга без пригодного
 * снимка не импортируется — предлагается сделать свежий экспорт.
 */
class MigxpageconfiguratorLexiconsImportProcessor extends modProcessor
{
    /**
     * Массовый импорт словаря — пишущая операция: требуется mpc_lexicon_manage.
     * Одного mpc_view (право «посмотреть словарь») недостаточно: импорт
     * заливает тексты витрины разом по всему сайту.
     * Коннектор проверяет лишь сессию.
     */
    public function checkPermissions()
    {
        /* save_document — второй уровень: импорт меняет тексты сразу по всему
           сайту, поэтому одного «можно писать в словарь» мало, нужно и общее
           право сохранять контент. Правку одного ключа (updatekey) это условие
           намеренно не затрагивает. */
        return $this->modx->hasPermission('mpc_lexicon_manage')
            && $this->modx->hasPermission('save_document');
    }

    private string $lexiconBase = '';
    private string $defaultLang = 'ru';
    private string $staticFile  = 'static';
    private string $allowedTags = '';
    private bool   $allowModxTags = false;
    /** @var \MpcServices\Handlers\Lexicon\LexiconBatchService|null */
    private $service = null;

    public function process()
    {
        $this->modx->lexicon->load('migxpageconfigurator:default');

        $corePath = $this->modx->getOption('migxpageconfigurator_core_path', null,
            $this->modx->getOption('core_path') . 'components/migxpageconfigurator/');
        require_once $corePath . 'services/vendor/autoload.php';

        $this->lexiconBase   = $this->modx->getOption('core_path')
            . $this->modx->getOption('mpc_lexicon_path', null, 'components/migxpageconfigurator/lexicon/');
        $this->defaultLang   = $this->modx->getOption('mpc_default_language', null, 'ru');
        $this->staticFile    = $this->modx->getOption('mpc_static_blocks_page_lexicon_filename', null, 'static');
        $this->allowedTags   = trim($this->modx->getOption('mpc_allowed_tags', null, ''));
        $this->allowModxTags = (bool)$this->modx->getOption('mpc_allow_modx_tags', null, false);

        $mode = $this->getProperty('mode', 'preview');
        return $mode === 'apply' ? $this->doApply() : $this->doPreview();
    }

    // ---------------------------------------------------------------- preview

    private function doPreview()
    {
        if (empty($_FILES['file']['tmp_name'])) {
            return $this->failure($this->modx->lexicon('mpc_err_no_file'));
        }
        $originalName = basename((string)($_FILES['file']['name'] ?? ''));
        $ext = preg_match('/\.zip$/i', $originalName) ? 'zip'
             : (preg_match('/\.xlsx$/i', $originalName) ? 'xlsx' : '');
        if ($ext === '') {
            return $this->failure($this->modx->lexicon('mpc_err_invalid_filetype'));
        }

        $tmpDir = $this->tmpDir();
        $this->gcTmp($tmpDir);
        // Имя = единственный барьер к загруженному файлу в core/cache (под
        // webroot). uniqid() предсказуем по microtime → перебираем; берём
        // криптослучайный токен. Формат imp_<hex> проходит regex в doApply.
        $token  = 'imp_' . bin2hex(random_bytes(16));
        $stored = $tmpDir . $token . '.' . $ext;
        if (!@move_uploaded_file($_FILES['file']['tmp_name'], $stored)) {
            // Настоящая загрузка с упавшим move (диск/права) → ошибка, а не
            // тихий copy в обход is_uploaded_file. copy-фолбэк — только для
            // не-HTTP/тестового контекста (tmp_name не является uploaded-файлом).
            if (is_uploaded_file($_FILES['file']['tmp_name'])) {
                return $this->failure($this->modx->lexicon('mpc_err_cannot_read_file'));
            }
            @copy($_FILES['file']['tmp_name'], $stored);
        }

        try {
            $sheets = \MpcServices\Handlers\Lexicon\WorkbookReader::read($stored, $this->tmpDir());
        } catch (\Throwable $e) {
            @unlink($stored);
            return $this->failure($this->modx->lexicon('mpc_err_cannot_read_file') . ': ' . $e->getMessage());
        }

        // Паспорт выгрузки: без снимка сравнить три стороны нечем, и импорт
        // превратился бы в слепую перезапись — ровно то, из-за чего старый
        // Excel затирал свежие правки менеджера.
        $snapshotId = \MpcServices\Handlers\Lexicon\LexiconBatchService::snapshotIdFrom($sheets);
        $reject     = $this->service()->snapshots()->reject($snapshotId);
        if ($snapshotId === '' || $reject !== '') {
            @unlink($stored);
            return $this->failure(\MpcServices\Handlers\Lexicon\LexiconBatchService::snapshotErrorText($snapshotId === '' ? 'no-snapshot' : $reject));
        }

        $sheetsPlan = $this->service()->sheetPlan($sheets);
        $desired    = $this->service()->desiredFromPlan($sheetsPlan, null);

        $result = $this->service()->planImport($desired, $snapshotId);
        if ($result['error'] !== '') {
            @unlink($stored);
            return $this->failure(\MpcServices\Handlers\Lexicon\LexiconBatchService::snapshotErrorText($result['error']));
        }

        $byRid = $this->summaryByRid($result['ops']);
        $plan  = [];
        foreach ($sheetsPlan as $sp) {
            $stats = $byRid[$sp['target']] ?? [];
            $plan[] = [
                'id'         => $sp['id'],
                'file'       => $sp['file'],
                'sheet'      => $sp['sheet'],
                'target'     => $sp['target'],
                'recognized' => $sp['target'] !== '',
                'langs'      => implode(',', $sp['langs']),
                'keys'       => count($sp['data']),
                // Что реально произойдёт, а не абстрактный diff: сколько строк
                // будет записано, сколько уже совпадает, сколько ждёт решения.
                'apply'      => (int)($stats[\MpcServices\Handlers\Lexicon\LexiconMerge::WRITE] ?? 0),
                'clear'      => (int)($stats[\MpcServices\Handlers\Lexicon\LexiconMerge::CLEAR] ?? 0),
                'noop'       => (int)($stats[\MpcServices\Handlers\Lexicon\LexiconMerge::NOOP] ?? 0),
                'skip'       => (int)($stats[\MpcServices\Handlers\Lexicon\LexiconMerge::SKIP] ?? 0),
                'conflicts'  => (int)($stats[\MpcServices\Handlers\Lexicon\LexiconMerge::CONFLICT] ?? 0),
            ];
        }

        if (empty($plan)) {
            @unlink($stored);
            return $this->failure($this->modx->lexicon('mpc_err_cannot_read_file'));
        }

        return $this->success('', [
            'token'     => $token . '.' . $ext,
            'plan'      => $plan,
            'summary'   => $result['summary'],
            'conflicts' => $this->conflictRows($result['conflicts']),
            'snapshot'  => $result['snapshot'],
            'resources' => $this->resourcesWithTitles($this->service()->store()->existingRids($this->defaultLang)),
        ]);
    }

    /**
     * Ресурсы для combo ремапа: [{rid, label}] где label = «rid — Заголовок».
     * Заголовок берём по нормализованному filename-field (id/alias/uri) +
     * mpc_cmp_resource_label_field. Нет ресурса (static/контакты) → label = rid.
     */
    private function resourcesWithTitles(array $rids): array
    {
        $field = \MpcServices\Handlers\Grabber\LexiconManager::normalizeFilenameField(
            $this->modx->getOption('mpc_lexicon_filename_field', null, 'id')
        );
        $labelField = $this->modx->getOption('mpc_cmp_resource_label_field', null, 'pagetitle');

        $out = [];
        foreach ($rids as $rid) {
            $label = $rid;
            $res   = $this->modx->getObject('modResource', [$field => $rid]);
            if ($res) {
                $title = (string)$res->get($labelField);
                if ($title !== '') {
                    $label = $rid . ' — ' . $title;
                }
            }
            $out[] = ['rid' => $rid, 'label' => $label];
        }
        return $out;
    }

    // ---------------------------------------------------------------- apply

    private function doApply()
    {
        $token = basename((string)$this->getProperty('token', '')); // защита от traversal
        if ($token === '' || !preg_match('/^imp_[a-z0-9]+\.(xlsx|zip)$/i', $token)) {
            return $this->failure($this->modx->lexicon('mpc_err_no_file'));
        }
        $stored = $this->tmpDir() . $token;
        if (!is_file($stored)) {
            return $this->failure($this->modx->lexicon('mpc_err_cannot_read_file'));
        }

        $selRaw = $this->getProperty('selections', '[]');
        $selections = is_array($selRaw) ? $selRaw : json_decode((string)$selRaw, true);
        $byId = [];
        foreach ((array)$selections as $sel) {
            $tid = (string)($sel['target'] ?? '');
            if ($tid !== '') {
                $byId[(int)($sel['id'] ?? -1)] = $tid;
            }
        }

        try {
            $sheets = \MpcServices\Handlers\Lexicon\WorkbookReader::read($stored, $this->tmpDir());
        } catch (\Throwable $e) {
            return $this->failure($this->modx->lexicon('mpc_err_cannot_read_file') . ': ' . $e->getMessage());
        }

        // Снимок нужен и на применении: план считается заново по актуальному
        // состоянию файлов, а не берётся из превью — между превью и apply
        // менеджер мог править словарь в соседней вкладке браузера.
        $snapshotId = \MpcServices\Handlers\Lexicon\LexiconBatchService::snapshotIdFrom($sheets);
        $reject     = $this->service()->snapshots()->reject($snapshotId);
        if ($snapshotId === '' || $reject !== '') {
            return $this->failure(\MpcServices\Handlers\Lexicon\LexiconBatchService::snapshotErrorText($snapshotId === '' ? 'no-snapshot' : $reject));
        }

        $sheetsPlan = $this->service()->sheetPlan($sheets);
        $desired    = $this->service()->desiredFromPlan($sheetsPlan, $byId);
        if (empty($desired)) {
            return $this->failure($this->modx->lexicon('mpc_err_cannot_read_file'));
        }

        $result = $this->service()->planImport($desired, $snapshotId);
        if ($result['error'] !== '') {
            return $this->failure(\MpcServices\Handlers\Lexicon\LexiconBatchService::snapshotErrorText($result['error']));
        }

        $applied = $this->service()->apply($result['ops'], $this->resolutions(), ['tag' => 'import']);
        $this->clearPending($applied['appliedOps']);

        @unlink($stored);
        $this->modx->cacheManager->refresh(['lexicon_topics' => []]);

        $unresolved = count($applied['conflicts']);
        $message = 'Записано значений: ' . $applied['applied']
            . ', очищено: ' . $applied['cleared']
            . ', без изменений: ' . (int)($applied['summary'][\MpcServices\Handlers\Lexicon\LexiconMerge::NOOP] ?? 0)
            . ($unresolved > 0 ? ', ждут решения: ' . $unresolved : '')
            . (!empty($applied['stale']) ? ', пропущено из-за правки в процессе: ' . count($applied['stale']) : '');

        return $this->success($message, [
            'applied'   => $applied['applied'],
            'cleared'   => $applied['cleared'],
            'stale'     => $this->conflictRows($applied['stale']),
            'conflicts' => $this->conflictRows($applied['conflicts']),
            'failed'    => $applied['failed'],
            'backup'    => $applied['backup'],
            'summary'   => $applied['summary'],
        ]);
    }

    // ---------------------------------------------------------------- helpers

    /** Пакетный сервис словаря — один на весь запрос. */
    private function service(): \MpcServices\Handlers\Lexicon\LexiconBatchService
    {
        if ($this->service === null) {
            $this->service = \MpcServices\Handlers\Lexicon\LexiconBatchService::fromModx(
                $this->modx,
                function (string $v): string {
                    return $this->sanitizeValue($v);
                }
            );
        }
        return $this->service;
    }

    /** Сводка действий плана по файлу лексикона: rid => (действие => счётчик). */
    private function summaryByRid(array $ops): array
    {
        $out = [];
        foreach ($ops as $op) {
            $rid = (string)($op['rid'] ?? '');
            $act = (string)($op['action'] ?? '');
            $out[$rid][$act] = (int)($out[$rid][$act] ?? 0) + 1;
        }
        return $out;
    }

    /** Конфликты и пропуски в виде строк для грида превью. */
    private function conflictRows(array $ops): array
    {
        $out = [];
        foreach ($ops as $op) {
            $out[] = [
                'address' => \MpcServices\Handlers\Lexicon\LexiconBatchService::address($op),
                'lang'    => (string)($op['lang'] ?? ''),
                'rid'     => (string)($op['rid'] ?? ''),
                'key'     => (string)($op['key'] ?? ''),
                'base'    => $op['base'] ?? null,
                'current' => array_key_exists('actual', $op) ? $op['actual'] : ($op['current'] ?? null),
                'desired' => $op['desired'] ?? null,
                'reason'  => (string)($op['reason'] ?? ''),
            ];
        }
        return $out;
    }

    /**
     * Решения менеджера по конфликтам: адрес => ['decision'=>mine|server,
     * 'seen'=>значение, которое ему показали]. `seen` обязателен для проверки
     * на устаревание: файл могли переписать, пока конфликт был на экране.
     */
    private function resolutions(): array
    {
        $raw = $this->getProperty('resolutions', '[]');
        $data = is_array($raw) ? $raw : json_decode((string)$raw, true);
        if (!is_array($data)) {
            return [];
        }

        $out = [];
        foreach ($data as $address => $decision) {
            if (is_array($decision)) {
                $out[(string)$address] = [
                    'decision' => (string)($decision['decision'] ?? ''),
                    'seen'     => array_key_exists('seen', $decision) ? $decision['seen'] : null,
                ];
                continue;
            }
            $out[(string)$address] = (string)$decision;
        }
        return $out;
    }

    /** Снять с реестра непереведённых ключи, которым импорт дал перевод. */
    private function clearPending(array $appliedOps): void
    {
        $pending = new \MpcServices\Handlers\PendingTranslations($this->lexiconBase);
        foreach ($appliedOps as $op) {
            $lang = (string)($op['lang'] ?? '');
            if ($lang === '' || $lang === $this->defaultLang) {
                continue;
            }
            if ((string)($op['action'] ?? '') !== \MpcServices\Handlers\Lexicon\LexiconMerge::WRITE) {
                continue;
            }
            if (trim((string)($op['desired'] ?? '')) === '') {
                continue;
            }
            $pending->remove($lang, (string)$op['rid'], (string)$op['key']);
        }
    }

    /** Существующие rid (basenames .inc.php дефолтного языка), кроме системных. */
    private function sanitizeValue(string $value): string
    {
        if ($this->allowedTags !== '') {
            $value = strip_tags($value, array_filter(array_map('trim', explode(',', $this->allowedTags))));
        } else {
            $value = strip_tags($value);
        }
        if (!$this->allowModxTags) {
            $value = preg_replace('/\[\[.+?\]\]/s', '', $value);
            $value = preg_replace('/\{.+?\}/s', '', $value);
        }
        return (string)$value;
    }

    private function tmpDir(): string
    {
        $dir = $this->modx->getCachePath() . 'mpc_import/';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        return $dir;
    }

    /** Удаляет временные файлы старше часа. */
    private function gcTmp(string $dir): void
    {
        foreach (glob($dir . '*') ?: [] as $f) {
            if (is_file($f) && (time() - (int)@filemtime($f)) > 3600) {
                @unlink($f);
            }
        }
    }
}
return 'MigxpageconfiguratorLexiconsImportProcessor';
