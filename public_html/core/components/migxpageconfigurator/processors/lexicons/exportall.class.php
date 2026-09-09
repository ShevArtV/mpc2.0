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

        // Один снимок на всю выгрузку: все ресурсы этого ZIP описывают одно и
        // то же состояние словаря, поэтому им нужен ОДИН snapshot_id — тот же
        // паспорт кладём в каждую книгу архива (см. exportallinone).
        $rids     = array_map(static fn($f) => basename($f, '.inc.php'), $files);
        $service  = \MpcServices\Handlers\Lexicon\LexiconBatchService::fromModx($this->modx);
        $snapshot = $service->snapshot(
            $rids,
            $languages,
            'exportall',
            $this->modx->user ? (int)$this->modx->user->get('id') : null
        );

        // ZIP собираем во временный файл (ZipArchive умеет писать только в
        // реальный путь) в системном temp, затем стримим в браузер и удаляем —
        // публичного файла в assets нет (см. ExportStreamer, закрывает S9).
        $zipFilename = 'lexicons_all_' . date('Y-m-d_His') . '.zip';
        $tempDir     = \MpcServices\Helpers\ExportStreamer::tempDir($this->modx);
        $zipPath     = tempnam($tempDir, 'mpc_zip_');
        if ($zipPath === false) {
            return $this->failure($this->modx->lexicon('mpc_err_zip_create'));
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            @unlink($zipPath);
            return $this->failure($this->modx->lexicon('mpc_err_zip_create'));
        }

        try {
            foreach ($files as $file) {
                $name    = basename($file, '.inc.php');
                $content = $this->generateExcel(
                    $snapshot['entries'][$name] ?? [],
                    $languages,
                    $defaultLang,
                    (string)$snapshot['id'],
                    $name
                );
                if ($content !== null) {
                    $zip->addFromString($name . '.xlsx', $content);
                }
            }
            $zip->close();
        } catch (\Throwable $e) {
            @$zip->close();
            @unlink($zipPath);
            throw $e;
        }

        \MpcServices\Helpers\ExportStreamer::streamFileAndExit($zipPath, $zipFilename, 'application/zip');
    }

    /**
     * Книга одного ресурса. Значения — из снимка (lang => key => value), а не
     * из повторного чтения файлов: книга обязана совпадать со снимком запись
     * в запись (см. exportallinone). Пустой снимок (ключей в default-языке
     * нет) — как и раньше, файл в архив не кладём.
     */
    private function generateExcel(
        array  $langData,
        array  $languages,
        string $defaultLang,
        string $snapshotId,
        string $rid
    ): ?string {
        $allKeys = array_keys($langData[$defaultLang] ?? []);
        if (empty($allKeys)) {
            return null;
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'mpc_xlsx_');

        $writer = \OpenSpout\Writer\Common\Creator\WriterEntityFactory::createXLSXWriter();
        $writer->openToFile($tmpFile);
        // Имя вкладки по общему правилу экспорта и импорта (лимит 31 символ,
        // хеш-хвост), точный адрес — в скрытом манифесте ниже.
        $sheetName = \MpcServices\Handlers\LexiconImport::sheetNameFor($rid);
        $writer->getCurrentSheet()->setName($sheetName);

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

        $this->writeManifest($writer, $sheetName, $rid);
        $this->writeMeta($writer, $snapshotId);

        $writer->close();

        $content = file_get_contents($tmpFile);
        unlink($tmpFile);

        return $content ?: null;
    }

    private function createRow(array $values): \OpenSpout\Common\Entity\Row
    {
        return \OpenSpout\Writer\Common\Creator\WriterEntityFactory::createRowFromArray($values);
    }

    /** Скрытый служебный лист `__mpc`: карта «вкладка → файл лексикона». */
    private function writeManifest(\OpenSpout\Writer\XLSX\Writer $writer, string $sheetName, string $rid): void
    {
        $sheet = $writer->addNewSheetAndMakeItCurrent();
        $sheet->setName(\MpcServices\Handlers\LexiconImport::MANIFEST_SHEET);
        $sheet->setIsVisible(false);

        $writer->addRow($this->createRow(['sheet', 'rid']));
        $writer->addRow($this->createRow([$sheetName, $rid]));
    }

    /**
     * Скрытый служебный лист `_meta` — паспорт выгрузки для импорта (снимок,
     * версия формата, время). Формат тот же, что в exportallinone::writeMeta;
     * snapshot_id один на весь ZIP — во все книги пишется одно и то же значение.
     */
    private function writeMeta(\OpenSpout\Writer\XLSX\Writer $writer, string $snapshotId): void
    {
        $sheet = $writer->addNewSheetAndMakeItCurrent();
        $sheet->setName(\MpcServices\Handlers\LexiconImport::META_SHEET);
        $sheet->setIsVisible(false);

        $writer->addRow($this->createRow(['key', 'value']));
        $writer->addRow($this->createRow(['snapshot_id', $snapshotId]));
        $writer->addRow($this->createRow([
            'format_version',
            (string)\MpcServices\Handlers\Lexicon\SnapshotStore::FORMAT_VERSION,
        ]));
        $writer->addRow($this->createRow(['exported_at', date('c')]));
    }
}
return 'MigxpageconfiguratorLexiconsExportallProcessor';
