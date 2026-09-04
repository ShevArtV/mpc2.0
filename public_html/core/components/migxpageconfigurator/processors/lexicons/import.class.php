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
 *    отдаёт план (вкладка→ресурс, ключей, новых/изменённых, распознан ли);
 *  - mode=apply: по token + выбранным вкладкам (с ремапом) пишет значения,
 *    снимает переведённое с pending-реестра, чистит temp, сбрасывает кэш.
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
            $sheets = $this->readWorkbook($stored);
        } catch (\Throwable $e) {
            @unlink($stored);
            return $this->failure($this->modx->lexicon('mpc_err_cannot_read_file') . ': ' . $e->getMessage());
        }

        $existingRids = $this->existingRids();
        $manifests    = $this->collectManifests($sheets);
        $plan = [];
        foreach ($sheets as $i => $s) {
            if ($s['sheet'] === \MpcServices\Handlers\LexiconImport::MANIFEST_SHEET) {
                continue; // служебный лист карты, не данные
            }
            $parsed = \MpcServices\Handlers\LexiconImport::sheetToData($s['headers'], $s['rows']);
            if (empty($parsed['data'])) {
                continue; // лист без колонки ключа / без строк
            }
            // манифест ищем в пределах СВОЕЙ книги: в ZIP их несколько
            $manifestRid = $manifests[$s['book']][mb_strtolower($s['sheet'], 'UTF-8')] ?? null;
            $target     = \MpcServices\Handlers\LexiconImport::resolveTarget($s['sheet'], $existingRids, $this->staticFile, $manifestRid);
            $recognized = $target !== null;
            $diff = $recognized
                ? \MpcServices\Handlers\LexiconImport::computeDiff($parsed['data'], $this->loadExisting((string)$target, $parsed['langs']))
                : ['keys' => count($parsed['data']), 'new' => count($parsed['data']), 'changed' => 0];

            $plan[] = [
                'id'         => $i,
                'file'       => $s['file'],
                'sheet'      => $s['sheet'],
                'target'     => (string)($target ?? ''),
                'recognized' => $recognized,
                'langs'      => implode(',', $parsed['langs']),
                'keys'       => $diff['keys'],
                'new'        => $diff['new'],
                'changed'    => $diff['changed'],
            ];
        }

        if (empty($plan)) {
            @unlink($stored);
            return $this->failure($this->modx->lexicon('mpc_err_cannot_read_file'));
        }

        return $this->success('', [
            'token'     => $token . '.' . $ext,
            'plan'      => $plan,
            'resources' => $this->resourcesWithTitles($existingRids),
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
            $sheets = $this->readWorkbook($stored);
        } catch (\Throwable $e) {
            return $this->failure($this->modx->lexicon('mpc_err_cannot_read_file') . ': ' . $e->getMessage());
        }

        $importedSheets = 0;
        $importedKeys   = 0;
        foreach ($sheets as $i => $s) {
            if ($s['sheet'] === \MpcServices\Handlers\LexiconImport::MANIFEST_SHEET) {
                continue; // служебный лист карты — не импортируем даже по выбору
            }
            if (!isset($byId[$i])) {
                continue; // вкладка не выбрана
            }
            $target = basename($byId[$i]);
            // целевой файл дефолтного языка должен существовать
            if (!file_exists($this->lexiconBase . $this->defaultLang . '/' . $target . '.inc.php')) {
                continue;
            }
            $parsed = \MpcServices\Handlers\LexiconImport::sheetToData($s['headers'], $s['rows']);
            if (empty($parsed['data'])) {
                continue;
            }
            $this->writeSheetData($target, $parsed['data']);
            $importedSheets++;
            $importedKeys += count($parsed['data']);
        }

        @unlink($stored);
        $this->modx->cacheManager->refresh(['lexicon_topics' => []]);

        return $this->success(
            'Импортировано вкладок: ' . $importedSheets . ' (ключей: ' . $importedKeys . ')',
            ['sheets' => $importedSheets, 'keys' => $importedKeys]
        );
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Карты «вкладка → rid» из скрытых листов `__mpc`, по книгам.
     * Ключ — $s['book'] (уникален даже при совпадении basename внутри ZIP):
     * манифест одной книги не должен применяться к вкладкам другой.
     *
     * @return array<string,array<string,string>> book => (sheetLower => rid)
     */
    private function collectManifests(array $sheets): array
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

    /** Пишет данные листа (key=>lang=>value) в файлы лексиконов целевого ресурса. */
    private function writeSheetData(string $rid, array $data): void
    {
        // перегруппируем в lang=>key=>value
        $byLang = [];
        foreach ($data as $key => $langVals) {
            foreach ($langVals as $lang => $val) {
                $byLang[$lang][$key] = $val;
            }
        }

        foreach ($byLang as $lang => $kv) {
            // basename + якорный regex: заголовок колонки XLSX под контролем
            // загружающего → 'en/../../assets' дал бы запись .inc.php вне lexiconBase.
            $lang = basename((string)$lang);
            if (!preg_match('/^[a-z]{2,8}$/', $lang)) {
                continue;
            }
            $langDir  = $this->lexiconBase . $lang . '/';
            $filePath = $langDir . $rid . '.inc.php';
            if (!is_dir($langDir)) {
                mkdir($langDir, 0777, true);
            }

            $_lang = [];
            if (file_exists($filePath)) {
                include $filePath;
            }
            foreach ($kv as $k => $v) {
                $_lang[$k] = $this->sanitizeValue((string)$v);
            }
            ksort($_lang);

            $content = '<?php' . PHP_EOL;
            foreach ($_lang as $k => $v) {
                // var_export ключа И значения — безопасный PHP-литерал (RCE-защита).
                $content .= '$_lang[' . var_export((string)$k, true) . '] = '
                    . var_export((string)$v, true) . ';' . PHP_EOL;
            }
            file_put_contents($filePath, $content, LOCK_EX);

            // переводы неосновного языка снимают ключи с pending-реестра
            if ($lang !== $this->defaultLang) {
                $pending = new \MpcServices\Handlers\PendingTranslations($this->lexiconBase);
                foreach ($kv as $k => $v) {
                    if ((string)$this->sanitizeValue((string)$v) !== '') {
                        $pending->remove($lang, $rid, (string)$k);
                    }
                }
            }
        }
    }

    /** Существующие rid (basenames .inc.php дефолтного языка), кроме системных. */
    private function existingRids(): array
    {
        $sys = ['default', 'properties', 'setting'];
        $out = [];
        foreach (glob($this->lexiconBase . $this->defaultLang . '/*.inc.php') ?: [] as $f) {
            $rid = basename($f, '.inc.php');
            if (!in_array($rid, $sys, true)) {
                $out[] = $rid;
            }
        }
        return $out;
    }

    /** Текущие значения по языкам для ресурса: lang=>(key=>value). */
    private function loadExisting(string $rid, array $langs): array
    {
        $byLang = [];
        foreach ($langs as $lang) {
            $_lang = [];
            $f = $this->lexiconBase . $lang . '/' . $rid . '.inc.php';
            if (file_exists($f)) {
                include $f;
            }
            $byLang[$lang] = is_array($_lang) ? $_lang : [];
        }
        return $byLang;
    }

    /**
     * Читает workbook (xlsx или zip из xlsx) в список листов.
     * @return array<int,array{file:string,sheet:string,headers:array,rows:array}>
     */
    private function readWorkbook(string $path): array
    {
        if (preg_match('/\.zip$/i', $path)) {
            $sheets = [];
            $zip = new \ZipArchive();
            if ($zip->open($path) !== true) {
                return [];
            }
            $exDir = $this->tmpDir() . 'x_' . bin2hex(random_bytes(8)) . '/';
            mkdir($exDir, 0777, true);

            // ZIP-slip: НЕ доверяем extractTo (его защита от '../' версионно-
            // зависима и обходилась). Извлекаем вручную только *.xlsx, по
            // basename имени записи — '../foo' схлопывается в 'foo' и не может
            // выбраться из $exDir; ничего исполняемого не распаковываем.
            $written = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = (string)$zip->getNameIndex($i);
                if ($entry === '' || substr($entry, -1) === '/') {
                    continue; // каталоги
                }
                $name = basename($entry);
                if (!preg_match('/\.xlsx$/i', $name)) {
                    continue;
                }
                $stream = $zip->getStream($entry);
                if ($stream === false) {
                    continue;
                }
                // индекс-префикс пути исключает перезапись при совпадении basename
                $dest = $exDir . $i . '_' . $name;
                $out  = @fopen($dest, 'wb');
                if ($out !== false) {
                    stream_copy_to_stream($stream, $out);
                    fclose($out);
                    // label — для показа, book — уникальный ключ книги: basename
                    // в ZIP может повторяться (файлы из разных папок), а по book
                    // группируются манифесты
                    $written[] = ['path' => $dest, 'label' => $name, 'book' => $i . '_' . $name];
                }
                fclose($stream);
            }
            $zip->close();

            foreach ($written as $w) {
                foreach ($this->readXlsx($w['path'], $w['label'], $w['book']) as $s) {
                    $sheets[] = $s;
                }
            }
            foreach (glob($exDir . '*') ?: [] as $f) { @unlink($f); }
            @rmdir($exDir);
            return $sheets;
        }
        return $this->readXlsx($path, basename($path), basename($path));
    }

    /** @return array<int,array{file:string,book:string,sheet:string,headers:array,rows:array}> */
    private function readXlsx(string $path, string $fileLabel, string $book): array
    {
        $reader = \OpenSpout\Reader\Common\Creator\ReaderFactory::createFromType('xlsx');
        $reader->open($path);
        $sheets = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            $headers = [];
            $rows    = [];
            $idx = 0;
            foreach ($sheet->getRowIterator() as $row) {
                $cells = $row->toArray();
                if ($idx === 0) {
                    $headers = $cells;
                } else {
                    $rows[] = $cells;
                }
                $idx++;
            }
            $sheets[] = [
                'file'    => $fileLabel,
                'book'    => $book,
                'sheet'   => $sheet->getName(),
                'headers' => $headers,
                'rows'    => $rows,
            ];
        }
        $reader->close();
        return $sheets;
    }

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
