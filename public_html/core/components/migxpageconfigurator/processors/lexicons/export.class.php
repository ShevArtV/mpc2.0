<?php
/**
 * Exports lexicons for a resource to Excel (2 sheets: resource + static sections).
 */
class MigxpageconfiguratorLexiconsExportProcessor extends modProcessor
{
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

        // Write to assets export dir
        $assetsPath = $this->modx->getOption('assets_path') . 'components/migxpageconfigurator/';
        $exportDir  = $assetsPath . 'lexicons-export/';
        if (!is_dir($exportDir)) {
            mkdir($exportDir, 0777, true);
        }

        $exportFilename = $filename . '_lexicons.xlsx';
        $exportPath     = $exportDir . $exportFilename;

        $writer = \OpenSpout\Writer\Common\Creator\WriterEntityFactory::createXLSXWriter();
        $writer->openToFile($exportPath);

        // Sheet 1: resource lexicons
        $resourceRows = $this->loadRows($lexiconBase, $filename, $languages);
        $writer->getCurrentSheet()->setName('Resource');
        $this->writeSheet($writer, $resourceRows, $languages);

        // Sheet 2: static section lexicons (if they exist)
        $staticRows = $this->loadRows($lexiconBase, $staticFile, $languages);
        if (!empty($staticRows)) {
            $writer->addNewSheetAndMakeItCurrent()->setName('Static');
            $this->writeSheet($writer, $staticRows, $languages);
        }

        $writer->close();

        $assetsUrl = $this->modx->getOption('assets_url')
            . 'components/migxpageconfigurator/lexicons-export/' . $exportFilename;

        return $this->success('', [
            'filePath' => $assetsUrl,
            'fileName' => $exportFilename,
        ]);
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
