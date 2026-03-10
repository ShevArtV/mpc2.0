<?php
/**
 * Imports lexicons from an uploaded XLSX or ZIP (containing XLSX files).
 * Supports 2-sheet XLSX format: Sheet "Resource" → resource file, Sheet "Static" → static file.
 * Single-sheet XLSX files are imported into a file named after the sheet title.
 * ZIP files are processed as a collection of XLSX files.
 */
class MigxpageconfiguratorLexiconsImportProcessor extends modProcessor
{
    public function process()
    {
        $this->modx->lexicon->load('migxpageconfigurator:default');

        $corePath = $this->modx->getOption('migxpageconfigurator_core_path', null,
            $this->modx->getOption('core_path') . 'components/migxpageconfigurator/');

        require_once $corePath . 'services/vendor/autoload.php';

        if (empty($_FILES['file']['tmp_name'])) {
            return $this->failure($this->modx->lexicon('mpc_err_no_file'));
        }

        $tmpFile      = $_FILES['file']['tmp_name'];
        $originalName = basename($_FILES['file']['name'] ?? '');
        $lexiconBase    = $this->modx->getOption('core_path')
            . $this->modx->getOption('mpc_lexicon_path', null, 'components/migxpageconfigurator/lexicon/');
        $staticFile     = $this->modx->getOption('mpc_static_blocks_page_lexicon_filename', null, 'static');
        $allowedTags    = trim($this->modx->getOption('mpc_allowed_tags', null, ''));
        $allowModxTags  = (bool)$this->modx->getOption('mpc_allow_modx_tags', null, false);

        $defaultLang = $this->modx->getOption('mpc_default_language', null, 'ru');

        if (preg_match('/\.zip$/i', $originalName)) {
            $result = $this->importZip($tmpFile, $lexiconBase, $staticFile, $defaultLang, $allowedTags, $allowModxTags);
        } elseif (preg_match('/\.xlsx$/i', $originalName)) {
            $result = $this->importXlsx($tmpFile, $originalName, $lexiconBase, $staticFile, $defaultLang, $allowedTags, $allowModxTags);
        } else {
            return $this->failure($this->modx->lexicon('mpc_err_invalid_filetype'));
        }

        if (!empty($result['errors'])) {
            return $this->failure(implode('; ', $result['errors']));
        }

        $this->modx->cacheManager->refresh(['lexicon_topics' => []]);

        return $this->success('Импорт выполнен (' . $result['count'] . ' файл(ов))');
    }

    private function importZip(
        string $zipPath,
        string $lexiconBase,
        string $staticFile,
        string $defaultLang,
        string $allowedTags,
        bool   $allowModxTags
    ): array {
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return ['count' => 0, 'errors' => []];
        }

        $tmpDir = sys_get_temp_dir() . '/mpc_import_' . uniqid();
        mkdir($tmpDir, 0777, true);
        $zip->extractTo($tmpDir);
        $zip->close();

        $count  = 0;
        $errors = [];
        foreach (glob($tmpDir . '/*.xlsx') as $xlsxFile) {
            $result = $this->importXlsx($xlsxFile, basename($xlsxFile), $lexiconBase, $staticFile, $defaultLang, $allowedTags, $allowModxTags);
            $count += $result['count'];
            $errors = array_merge($errors, $result['errors']);
        }

        foreach (glob($tmpDir . '/*') as $f) { unlink($f); }
        rmdir($tmpDir);

        return ['count' => $count, 'errors' => $errors];
    }

    private function importXlsx(
        string $tmpFile,
        string $originalName,
        string $lexiconBase,
        string $staticFile,
        string $defaultLang,
        string $allowedTags,
        bool   $allowModxTags
    ): array {
        try {
            $reader = \OpenSpout\Reader\Common\Creator\ReaderFactory::createFromType('xlsx');
            $reader->open($tmpFile);
        } catch (\Exception $e) {
            return ['count' => 0, 'errors' => [$this->modx->lexicon('mpc_err_cannot_read_file') . ': ' . $e->getMessage()]];
        }

        $baseName = preg_replace('/_lexicons$/', '', basename($originalName, '.xlsx'));
        $errors   = [];

        foreach ($reader->getSheetIterator() as $sheet) {
            $title = $sheet->getName();
            if ($title === 'Resource') {
                $targetFilename = $baseName . '.inc.php';
            } elseif ($title === 'Static') {
                $targetFilename = $staticFile . '.inc.php';
            } else {
                $targetFilename = $title . '.inc.php';
            }

            if (!file_exists($lexiconBase . $defaultLang . '/' . $targetFilename)) {
                $errors[] = $this->modx->lexicon('mpc_err_file_not_found', ['file' => $targetFilename]);
                continue;
            }

            $this->processSheet($sheet, $lexiconBase, $targetFilename, $allowedTags, $allowModxTags);
        }

        $reader->close();

        return ['count' => empty($errors) ? 1 : 0, 'errors' => $errors];
    }

    private function sanitizeValue(string $value, string $allowedTags, bool $allowModxTags): string
    {
        if ($allowedTags !== '') {
            $tagList = array_filter(array_map('trim', explode(',', $allowedTags)));
            $value   = strip_tags($value, $tagList);
        } else {
            $value = strip_tags($value);
        }

        if (!$allowModxTags) {
            $value = preg_replace('/\[\[.+?\]\]/s', '', $value);
            $value = preg_replace('/\{.+?\}/s', '', $value);
        }

        return $value;
    }

    private function processSheet(
        \OpenSpout\Reader\XLSX\Sheet $sheet,
        string $basePath,
        string $targetFilename,
        string $allowedTags = '',
        bool   $allowModxTags = false
    ): void {
        $headers   = [];
        $rows      = [];
        $rowIndex  = 0;

        foreach ($sheet->getRowIterator() as $row) {
            $cells = $row->toArray();
            if ($rowIndex === 0) {
                $headers = $cells;
            } else {
                $rows[] = $cells;
            }
            $rowIndex++;
        }

        if (empty($headers) || ($headers[0] ?? '') !== 'lexicon_key') {
            return;
        }

        $languages = array_slice($headers, 1);

        // Build per-language arrays
        $langData = [];
        foreach ($rows as $row) {
            $key = (string)($row[0] ?? '');
            if ($key === '') {
                continue;
            }
            foreach ($languages as $i => $lang) {
                $raw = (string)($row[$i + 1] ?? '');
                $langData[$lang][$key] = $this->sanitizeValue($raw, $allowedTags, $allowModxTags);
            }
        }

        foreach ($langData as $lang => $data) {
            if (!preg_match('/^[a-z]{2}/', $lang)) {
                continue;
            }

            $langDir  = $basePath . $lang . '/';
            $filePath = $langDir . $targetFilename;

            if (!is_dir($langDir)) {
                mkdir($langDir, 0777, true);
            }

            // Merge with existing
            $_lang = [];
            if (file_exists($filePath)) {
                include $filePath;
            }
            $_lang = array_merge($_lang, $data);
            ksort($_lang);

            $content = '<?php' . PHP_EOL;
            foreach ($_lang as $k => $v) {
                $v        = str_replace("'", '&apos;', (string)$v);
                $content .= '$_lang[\'' . $k . '\'] = \'' . $v . '\';' . PHP_EOL;
            }
            file_put_contents($filePath, $content);
        }
    }
}
return 'MigxpageconfiguratorLexiconsImportProcessor';
