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

        // Probe (лёгкий XHR из UI): считаем строки прямым чтением файлов —
        // снимок создавать не нужно, это ещё не настоящая выгрузка книги
        // (см. exportallinone, там probe тоже отвечает раньше snapshot()).
        if ($this->getProperty('probe')) {
            $resourceRows = $this->loadRows($this->readLangData($lexiconBase, $filename, $languages), $languages, $filename);
            $staticRows   = $this->loadRows($this->readLangData($lexiconBase, $staticFile, $languages), $languages, $staticFile);
            return $this->success('', ['found' => count($resourceRows) + count($staticRows)]);
        }

        // Снимок выгружаемых значений: без него импорт этой книги не сможет
        // отличить правку менеджера от старого значения (см. exportallinone).
        // Рид покрывает ОБА файла (Resource и Static) сразу — так снимок
        // остаётся верным паспортом книги независимо от того, попал ли Static
        // на отдельный лист.
        $service  = \MpcServices\Handlers\Lexicon\LexiconBatchService::fromModx($this->modx);
        $snapshot = $service->snapshot(
            [$filename, $staticFile],
            $languages,
            'export',
            $this->modx->user ? (int)$this->modx->user->get('id') : null
        );

        $resourceRows = $this->loadRows($snapshot['entries'][$filename] ?? [], $languages, $filename);
        $staticRows   = $this->loadRows($snapshot['entries'][$staticFile] ?? [], $languages, $staticFile);

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

            // Вкладки названы 'Resource'/'Static', а не по rid, поэтому карта
            // «вкладка → файл лексикона» обязательна: без неё импорт угадывает
            // адрес по имени листа и на переименованном ресурсе промахнётся.
            $this->writeManifest($writer, [
                ['Resource', $filename],
                ['Static', $staticFile],
            ]);
            $this->writeMeta($writer, (string)$snapshot['id']);
        } catch (\Throwable $e) {
            $writer->close(); // уберёт temp-папку writer'а при обрыве
            throw $e;
        }

        \MpcServices\Helpers\ExportStreamer::finishAndExit($writer);
    }

    /** Прямое чтение файлов лексикона — только для probe, снимок там не нужен. */
    private function readLangData(string $base, string $filename, array $languages): array
    {
        $incFile  = $filename . '.inc.php';
        $langData = [];
        foreach ($languages as $lang) {
            $_lang    = [];
            $langFile = $base . $lang . '/' . $incFile;
            if (file_exists($langFile)) {
                include $langFile;
            }
            $langData[$lang] = $_lang;
        }
        return $langData;
    }

    /**
     * Строки одной вкладки: [lexicon_key, <по языкам>]. Для реального экспорта
     * источник значений — снимок (lang => key => value), а не повторное чтение
     * файлов: книга обязана совпадать со снимком запись в запись (см.
     * exportallinone). Для probe сюда передаётся результат readLangData().
     */
    private function loadRows(array $langData, array $languages, string $filename): array
    {
        $allKeys = array_keys($langData[$this->defaultLang] ?? []);

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

    /** Скрытый служебный лист `__mpc`: карта «вкладка → файл лексикона». */
    private function writeManifest(\OpenSpout\Writer\XLSX\Writer $writer, array $pairs): void
    {
        $sheet = $writer->addNewSheetAndMakeItCurrent();
        $sheet->setName(\MpcServices\Handlers\LexiconImport::MANIFEST_SHEET);
        $sheet->setIsVisible(false);

        $writer->addRow($this->createRow(['sheet', 'rid']));
        foreach ($pairs as $pair) {
            $writer->addRow($this->createRow($pair));
        }
    }

    /**
     * Скрытый служебный лист `_meta` — паспорт выгрузки для импорта (снимок,
     * версия формата, время). Формат тот же, что в exportallinone::writeMeta.
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
return 'MigxpageconfiguratorLexiconsExportProcessor';
