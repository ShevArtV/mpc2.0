<?php
/**
 * Exports all resource lexicons to a ZIP archive (one XLSX per resource).
 */
class MigxpageconfiguratorLexiconsExportallProcessor extends modProcessor
{
    /** Требуется право mpc_view (как CMP лексиконов); коннектор проверяет лишь сессию. */
    public function checkPermissions()
    {
        return $this->modx->hasPermission('mpc_view');
    }

    public function process()
    {
        $this->modx->lexicon->load('migxpageconfigurator:default');

        $corePath = $this->modx->getOption('migxpageconfigurator_core_path', null,
            $this->modx->getOption('core_path') . 'components/migxpageconfigurator/');

        require_once $corePath . 'services/vendor/autoload.php';

        $lexiconBase = $this->modx->getOption('core_path')
            . $this->modx->getOption('mpc_lexicon_path', null, 'components/migxpageconfigurator/lexicon/');
        $defaultLang = $this->modx->getOption('mpc_default_language', null, 'ru');

        $requestedLangs = $this->getProperty('languages', '');
        if ($requestedLangs !== '') {
            $languages = array_filter(array_map('trim', explode(',', $requestedLangs)));
        } else {
            $langDirs  = glob($lexiconBase . '*', GLOB_ONLYDIR) ?: [];
            $languages = array_map('basename', $langDirs);
        }
        usort($languages, function ($a, $b) use ($defaultLang) {
            if ($a === $defaultLang) return -1;
            if ($b === $defaultLang) return 1;
            return strcmp($a, $b);
        });

        $requested   = $this->getProperty('filenames', '');
        $systemFiles = ['default', 'properties', 'setting'];

        if ($requested !== '') {
            $names = array_filter(array_map('trim', explode(',', $requested)));
            $files = [];
            foreach ($names as $name) {
                $name = basename($name); // security: strip path separators
                if (!in_array($name, $systemFiles)) {
                    $f = $lexiconBase . $defaultLang . '/' . $name . '.inc.php';
                    if (file_exists($f)) {
                        $files[] = $f;
                    }
                }
            }
        } else {
            $files = glob($lexiconBase . $defaultLang . '/*.inc.php') ?: [];
            $files = array_filter($files, function ($f) use ($systemFiles) {
                return !in_array(basename($f, '.inc.php'), $systemFiles);
            });
        }

        if (empty($files)) {
            return $this->failure($this->modx->lexicon('mpc_err_no_lexicons'));
        }

        $assetsPath = $this->modx->getOption('assets_path') . 'components/migxpageconfigurator/';
        $exportDir  = $assetsPath . 'lexicons-export/';
        if (!is_dir($exportDir)) {
            mkdir($exportDir, 0777, true);
        }

        $zipFilename = 'lexicons_all_' . date('Y-m-d_His') . '.zip';
        $zipPath     = $exportDir . $zipFilename;

        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            return $this->failure($this->modx->lexicon('mpc_err_zip_create'));
        }

        foreach ($files as $file) {
            $name    = basename($file, '.inc.php');
            $content = $this->generateExcel($lexiconBase, $name, $languages, $defaultLang);
            if ($content !== null) {
                $zip->addFromString($name . '.xlsx', $content);
            }
        }

        $zip->close();

        $assetsUrl = $this->modx->getOption('assets_url')
            . 'components/migxpageconfigurator/lexicons-export/' . $zipFilename;

        return $this->success('', [
            'filePath' => $assetsUrl,
            'fileName' => $zipFilename,
        ]);
    }

    private function generateExcel(
        string $base,
        string $filename,
        array  $languages,
        string $defaultLang
    ): ?string {
        $incFile     = $filename . '.inc.php';
        $defaultFile = $base . $defaultLang . '/' . $incFile;
        if (!file_exists($defaultFile)) {
            return null;
        }

        $_lang = [];
        include $defaultFile;
        $allKeys = array_keys($_lang);
        if (empty($allKeys)) {
            return null;
        }
        $defaultData = $_lang;

        $langData = [$defaultLang => $defaultData];
        foreach ($languages as $lang) {
            if ($lang === $defaultLang) {
                continue;
            }
            $_lang    = [];
            $langFile = $base . $lang . '/' . $incFile;
            if (file_exists($langFile)) {
                include $langFile;
            }
            $langData[$lang] = $_lang;
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'mpc_xlsx_');

        $writer = \OpenSpout\Writer\Common\Creator\WriterEntityFactory::createXLSXWriter();
        $writer->openToFile($tmpFile);

        // Header
        $header = array_merge(['lexicon_key'], $languages);
        $writer->addRow($this->createRow($header));

        // Data
        foreach ($allKeys as $key) {
            $values = [$key];
            foreach ($languages as $lang) {
                $values[] = $langData[$lang][$key] ?? '';
            }
            $writer->addRow($this->createRow($values));
        }

        $writer->close();

        $content = file_get_contents($tmpFile);
        unlink($tmpFile);

        return $content ?: null;
    }

    private function createRow(array $values): \OpenSpout\Common\Entity\Row
    {
        return \OpenSpout\Writer\Common\Creator\WriterEntityFactory::createRowFromArray($values);
    }
}
return 'MigxpageconfiguratorLexiconsExportallProcessor';
