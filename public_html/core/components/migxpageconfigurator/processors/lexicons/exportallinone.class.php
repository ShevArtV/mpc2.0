<?php
/**
 * Экспорт ВСЕХ лексиконов в ОДИН XLSX: вкладка-на-ресурс, колонки
 * `Контекст | key | <язык1> | <язык2> | …`. «Контекст» = «Секция: Поле»
 * (резолвится через LexiconContext: манифест mpc_tracked_fields + migx-конфиги),
 * справочная колонка — на импорт не влияет. Последним листом пишется скрытый
 * `__mpc` — карта «вкладка → файл лексикона» для точного обратного импорта.
 *
 * Старые режимы (per-resource XLSX / ZIP) остаются отдельными процессорами.
 */
class MigxpageconfiguratorLexiconsExportallinoneProcessor extends modProcessor
{
    /** Требуется право mpc_view (как CMP лексиконов); коннектор проверяет лишь сессию. */
    public function checkPermissions()
    {
        return $this->modx->hasPermission('mpc_view');
    }

    /** Файлы лексиконов, не относящиеся к контенту ресурсов. */
    private array $systemFiles = ['default', 'properties', 'setting'];

    private ?\OpenSpout\Common\Entity\Style\Style $dataStyle = null;
    private ?\OpenSpout\Common\Entity\Style\Style $headerStyle = null;

    public function process()
    {
        $this->modx->lexicon->load('migxpageconfigurator:default');

        $corePath = $this->modx->getOption('migxpageconfigurator_core_path', null,
            $this->modx->getOption('core_path') . 'components/migxpageconfigurator/');
        require_once $corePath . 'services/vendor/autoload.php';

        $lexiconBase = $this->modx->getOption('core_path')
            . $this->modx->getOption('mpc_lexicon_path', null, 'components/migxpageconfigurator/lexicon/');
        $defaultLang = $this->modx->getOption('mpc_default_language', null, 'ru');

        $languages = $this->resolveLanguages($lexiconBase, $defaultLang);

        // Какие ресурсы (вкладки): явный список filenames или все нессистемные.
        $files = $this->resolveFiles($lexiconBase, $defaultLang);
        if (empty($files)) {
            return $this->failure($this->modx->lexicon('mpc_err_no_lexicons'));
        }

        // Probe (лёгкий XHR из UI): сообщаем, есть ли вкладки с ключами, чтобы
        // показать «не найдено» вместо навигации на пустой файл. Контекст
        // (дорогой resolve) здесь не считаем — только наличие ключей.
        if ($this->getProperty('probe')) {
            $found = 0;
            foreach ($files as $rid) {
                $_lang = [];
                $f     = $lexiconBase . $defaultLang . '/' . $rid . '.inc.php';
                if (file_exists($f)) {
                    include $f;
                }
                if (!empty($_lang)) {
                    $found++;
                }
            }
            return $this->success('', ['found' => $found]);
        }

        $context = new \MpcServices\Handlers\LexiconContext($this->modx);

        // Строки собираем ДО открытия writer: openToBrowser шлёт заголовки сразу,
        // поэтому решение «есть ли что отдавать» принимаем заранее.
        $sheets         = [];
        $usedSheetNames = [];
        foreach ($files as $rid) {
            $rows = $this->loadRows($lexiconBase, $rid, $languages, $defaultLang, $context);
            if (empty($rows)) {
                continue;
            }
            $sheets[] = [
                'name' => $this->uniqueSheetName($rid, $usedSheetNames),
                'rid'  => $rid,
                'rows' => $rows,
            ];
        }

        if (empty($sheets)) {
            // UI гейтит через probe — сюда штатно не доходим; не навигируем.
            return $this->success('', ['found' => 0]);
        }

        // Стримим XLSX в браузер (см. ExportStreamer): публичного файла нет.
        $tempDir = \MpcServices\Helpers\ExportStreamer::tempDir($this->modx);
        $writer  = \MpcServices\Helpers\ExportStreamer::xlsxWriterToBrowser(
            'lexicons_all-in-one_' . date('Y-m-d_His') . '.xlsx',
            $tempDir
        );

        // Ширины столбцов (на весь workbook — раскладка одинакова на всех вкладках):
        // 1=Контекст, 2=lexicon_key, 3..=языки. Текст в ячейках переносится (wrap).
        $writer->setColumnWidth(40, 1);
        $writer->setColumnWidth(34, 2);
        $langCount = count($languages);
        if ($langCount > 0) {
            $writer->setColumnWidthForRange(60, 3, 2 + $langCount);
        }

        try {
            $first = true;
            foreach ($sheets as $s) {
                // Первый лист уже создан writer'ом, последующие добавляем.
                $sheet = $first ? $writer->getCurrentSheet() : $writer->addNewSheetAndMakeItCurrent();
                $sheet->setName($s['name']);
                $first = false;
                $this->writeSheet($writer, $s['rows'], $languages);
            }
            $this->writeManifest($writer, $sheets);
        } catch (\Throwable $e) {
            $writer->close(); // уберёт temp-папку writer'а при обрыве
            throw $e;
        }

        \MpcServices\Helpers\ExportStreamer::finishAndExit($writer);
    }

    private function resolveLanguages(string $lexiconBase, string $defaultLang): array
    {
        $requested = $this->getProperty('languages', '');
        if ($requested !== '') {
            $languages = array_filter(array_map('trim', explode(',', $requested)));
        } else {
            $langDirs  = glob($lexiconBase . '*', GLOB_ONLYDIR) ?: [];
            $languages = array_map('basename', $langDirs);
        }
        usort($languages, static function ($a, $b) use ($defaultLang) {
            if ($a === $defaultLang) return -1;
            if ($b === $defaultLang) return 1;
            return strcmp($a, $b);
        });
        return $languages;
    }

    /** Список идентификаторов ресурсов (имена .inc.php без расширения) для вкладок. */
    private function resolveFiles(string $lexiconBase, string $defaultLang): array
    {
        $requested = $this->getProperty('filenames', '');
        $rids      = [];

        if ($requested !== '') {
            foreach (array_filter(array_map('trim', explode(',', $requested))) as $name) {
                $name = basename($name); // strip path separators
                if (!in_array($name, $this->systemFiles, true)
                    && file_exists($lexiconBase . $defaultLang . '/' . $name . '.inc.php')) {
                    $rids[] = $name;
                }
            }
        } else {
            foreach (glob($lexiconBase . $defaultLang . '/*.inc.php') ?: [] as $f) {
                $name = basename($f, '.inc.php');
                if (!in_array($name, $this->systemFiles, true)) {
                    $rids[] = $name;
                }
            }
        }
        return $rids;
    }

    /** Строки одной вкладки: [Контекст, key, <по языкам>]. */
    private function loadRows(
        string $base,
        string $rid,
        array $languages,
        string $defaultLang,
        \MpcServices\Handlers\LexiconContext $context
    ): array {
        $incFile = $rid . '.inc.php';

        $langData = [];
        foreach ($languages as $lang) {
            $_lang    = [];
            $langFile = $base . $lang . '/' . $incFile;
            if (file_exists($langFile)) {
                include $langFile;
            }
            $langData[$lang] = is_array($_lang) ? $_lang : [];
        }

        $allKeys = array_keys($langData[$defaultLang] ?? []);
        $rows    = [];
        foreach ($allKeys as $key) {
            $row = [
                'context' => $context->contextFor((string)$key),
                'key'     => $key,
            ];
            foreach ($languages as $lang) {
                $row[$lang] = $langData[$lang][$key] ?? '';
            }
            $rows[] = $row;
        }
        return $rows;
    }

    private function writeSheet(\OpenSpout\Writer\XLSX\Writer $writer, array $rows, array $languages): void
    {
        if (empty($rows)) {
            return;
        }
        $header = array_merge(['Контекст', 'lexicon_key'], $languages);
        $writer->addRow($this->createRow($header, $this->headerStyle()));

        foreach ($rows as $row) {
            $values = [$row['context'] ?? '', $row['key']];
            foreach ($languages as $lang) {
                $values[] = $row[$lang] ?? '';
            }
            $writer->addRow($this->createRow($values, $this->dataStyle()));
        }
    }

    /** Стиль данных: перенос текста (длинные значения не налазят на соседние ячейки). */
    private function dataStyle(): \OpenSpout\Common\Entity\Style\Style
    {
        if ($this->dataStyle === null) {
            $this->dataStyle = (new \OpenSpout\Writer\Common\Creator\Style\StyleBuilder())
                ->setShouldWrapText(true)
                ->build();
        }
        return $this->dataStyle;
    }

    /** Стиль шапки: жирный + перенос. */
    private function headerStyle(): \OpenSpout\Common\Entity\Style\Style
    {
        if ($this->headerStyle === null) {
            $this->headerStyle = (new \OpenSpout\Writer\Common\Creator\Style\StyleBuilder())
                ->setFontBold()
                ->setShouldWrapText(true)
                ->build();
        }
        return $this->headerStyle;
    }

    private function createRow(array $values, ?\OpenSpout\Common\Entity\Style\Style $style = null): \OpenSpout\Common\Entity\Row
    {
        return \OpenSpout\Writer\Common\Creator\WriterEntityFactory::createRowFromArray($values, $style);
    }

    /**
     * Скрытый служебный лист `__mpc`: точная карта «вкладка → файл лексикона».
     * Имя вкладки лимитировано 31 символом и потому не всегда равно rid —
     * импорт берёт соответствие отсюда, не гадая по имени.
     */
    private function writeManifest(\OpenSpout\Writer\XLSX\Writer $writer, array $sheets): void
    {
        $sheet = $writer->addNewSheetAndMakeItCurrent();
        $sheet->setName(\MpcServices\Handlers\LexiconImport::MANIFEST_SHEET);
        $sheet->setIsVisible(false);

        $writer->addRow($this->createRow(['sheet', 'rid'], $this->headerStyle()));
        foreach ($sheets as $s) {
            $writer->addRow($this->createRow([$s['name'], $s['rid']]));
        }
    }

    /**
     * Имя вкладки Excel: правило одно на экспорт и импорт (LexiconImport::
     * sheetNameFor — запрещённые символы, лимит 31, хеш-хвост для длинных rid).
     * Числовой суффикс остаётся страховкой на невероятную коллизию хешей.
     */
    private function uniqueSheetName(string $rid, array &$used): string
    {
        $name = \MpcServices\Handlers\LexiconImport::sheetNameFor($rid);

        $base = $name;
        $i    = 1;
        while (isset($used[mb_strtolower($name)])) {
            $suffix = '_' . $i++;
            $name   = mb_substr($base, 0, 31 - mb_strlen($suffix)) . $suffix;
        }
        $used[mb_strtolower($name)] = true;
        return $name;
    }
}
return 'MigxpageconfiguratorLexiconsExportallinoneProcessor';
