<?php
/**
 * Exports lexicons for a resource to Excel (2 sheets: resource + static sections).
 */
class MigxpageconfiguratorLexiconsExportProcessor extends modProcessor
{
    /** Требуется право mpc_view (как CMP лексиконов); коннектор проверяет лишь сессию. */
    public function checkPermissions()
    {
        return $this->modx->hasPermission('mpc_view');
    }

    private bool $onlyUntranslated = false;
    private string $defaultLang = 'ru';
    private ?\MpcServices\Handlers\PendingTranslations $pending = null;

    public function process()
    {
        $corePath = $this->modx->getOption('migxpageconfigurator_core_path', null,
            $this->modx->getOption('core_path') . 'components/migxpageconfigurator/');

        require_once $corePath . 'services/vendor/autoload.php';

        $this->modx->lexicon->load('migxpageconfigurator:default');

        $filename = basename($this->getProperty('filename', ''));
        if (!$filename) {
            return $this->failure($this->modx->lexicon('mpc_err_no_filename'));
        }

        $lexiconBase   = $this->modx->getOption('core_path')
            . $this->modx->getOption('mpc_lexicon_path', null, 'components/migxpageconfigurator/lexicon/');
        $defaultLang   = $this->modx->getOption('mpc_default_language', null, 'ru');
        $staticFile    = $this->modx->getOption('mpc_static_blocks_page_lexicon_filename', null, 'static');

        // Режим «только непереведённые»: оставляем строки, чьи ключи лежат в
        // pending-реестре хотя бы одного из экспортируемых неосновных языков
        // (явный pending-list, без эвристики lang==default).
        $this->onlyUntranslated = (bool)$this->getProperty('untranslated', false);
        $this->defaultLang      = $defaultLang;
        $this->pending          = new \MpcServices\Handlers\PendingTranslations($lexiconBase);

        $requested = $this->getProperty('languages', '');
        if ($requested !== '') {
            $languages = array_filter(array_map('trim', explode(',', $requested)));
        } else {
            $langDirs  = glob($lexiconBase . '*', GLOB_ONLYDIR) ?: [];
            $languages = array_map('basename', $langDirs);
        }
        usort($languages, function ($a, $b) use ($defaultLang) {
            if ($a === $defaultLang) return -1;
            if ($b === $defaultLang) return 1;
            return strcmp($a, $b);
        });

        // Строки считаем ДО открытия writer: режим probe (лёгкий XHR из UI)
        // лишь сообщает, есть ли что отдавать — чтобы для «непереведённых» при
        // пустом результате показать сообщение, а не навигировать на пустой файл.
        $resourceRows = $this->loadRows($lexiconBase, $filename, $languages);
        $staticRows   = $this->loadRows($lexiconBase, $staticFile, $languages);

        if ($this->getProperty('probe')) {
            return $this->success('', ['found' => count($resourceRows) + count($staticRows)]);
        }

        // Стримим XLSX прямо в браузер (см. ExportStreamer): публичного файла
        // в assets больше нет — отдача идёт через коннектор с проверкой прав.
        $tempDir = \MpcServices\Helpers\ExportStreamer::tempDir($this->modx);
        $writer  = \MpcServices\Helpers\ExportStreamer::xlsxWriterToBrowser(
            $filename . '_lexicons.xlsx',
            $tempDir
        );

        try {
            // Sheet 1: resource lexicons
            $writer->getCurrentSheet()->setName('Resource');
            $this->writeSheet($writer, $resourceRows, $languages);

            // Sheet 2: static section lexicons (if they exist)
            if (!empty($staticRows)) {
                $writer->addNewSheetAndMakeItCurrent()->setName('Static');
                $this->writeSheet($writer, $staticRows, $languages);
            }
        } catch (\Throwable $e) {
            $writer->close(); // уберёт temp-папку writer'а при обрыве
            throw $e;
        }

        \MpcServices\Helpers\ExportStreamer::finishAndExit($writer);
    }

    private function loadRows(string $base, string $filename, array $languages): array
    {
        $defaultLang = $this->modx->getOption('mpc_default_language', null, 'ru');
        $incFile     = $filename . '.inc.php';

        $langData = [];
        foreach ($languages as $lang) {
            $_lang    = [];
            $langFile = $base . $lang . '/' . $incFile;
            if (file_exists($langFile)) {
                include $langFile;
            }
            $langData[$lang] = $_lang;
        }

        $allKeys = array_keys($langData[$defaultLang] ?? []);

        if ($this->onlyUntranslated && $this->pending !== null) {
            $pendingKeys = [];
            foreach ($languages as $lang) {
                if ($lang === $this->defaultLang) {
                    continue;
                }
                foreach ($this->pending->load($lang, $filename) as $pk) {
                    $pendingKeys[$pk] = true;
                }
            }
            $allKeys = array_values(array_filter($allKeys, static fn($k) => isset($pendingKeys[$k])));
        }

        $rows    = [];
        foreach ($allKeys as $key) {
            $row = ['lexicon_key' => $key];
            foreach ($languages as $lang) {
                $row[$lang] = $langData[$lang][$key] ?? '';
            }
            $rows[] = $row;
        }

        return $rows;
    }

    private function writeSheet(
        \OpenSpout\Writer\XLSX\Writer $writer,
        array $rows,
        array $languages
    ): void {
        if (empty($rows)) {
            return;
        }

        // Header
        $header = array_merge(['lexicon_key'], $languages);
        $writer->addRow($this->createRow($header));

        // Data
        foreach ($rows as $row) {
            $values = [$row['lexicon_key']];
            foreach ($languages as $lang) {
                $values[] = $row[$lang] ?? '';
            }
            $writer->addRow($this->createRow($values));
        }
    }

    private function createRow(array $values): \OpenSpout\Common\Entity\Row
    {
        return \OpenSpout\Writer\Common\Creator\WriterEntityFactory::createRowFromArray($values);
    }
}
return 'MigxpageconfiguratorLexiconsExportProcessor';
