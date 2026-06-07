<?php
/**
 * @author Arthur Shevchenko
 * @description Сервис для обработки файлов Excel
 */

namespace MpcServices\Helpers;

use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\Common\Creator\ReaderFactory;
use OpenSpout\Writer\Common\Creator\WriterEntityFactory;

class ExcelFileHandler
{
    /**
     * @var \Modx $modx
     */
    public \ModX $modx;
    /**
     * @var array $fields
     */
    public array $fields = [];
    /**
     * @var string $assetsPath
     */
    private string $assetsPath;
    /**
     * @var string $basePath
     */
    private string $basePath;

    public function __construct(\Modx $modx)
    {
        $this->modx = $modx;
        $this->initialize();
    }

    private function initialize()
    {
        $this->assetsPath = $this->modx->getOption('assets_path', '', MODX_ASSETS_PATH);
        $this->basePath = $this->modx->getOption('base_path', '', MODX_BASE_PATH);
    }

    public function generateFile(array $data, string $filename, ?string $dir): string
    {
        if (empty($data)) {
            return '';
        }
        $headers = array_keys($data[0]);
        $headerLabels = array_map(fn($key) => $this->fields[$key] ?? $key, $headers);

        return $this->createFile($headers, $headerLabels, $data, $filename, $dir);
    }

    private function createFile(array $keys, array $headerLabels, array $data, string $filename, ?string $dir = ''): string
    {
        // Дефолтный путь тоже в границах assets: $dir/$filename с ../ вышли бы
        // наружу (override проверяется ниже, а базовый путь — нет) (V7).
        if (strpos((string)$dir, '..') !== false || strpos($filename, '..') !== false) {
            return '';
        }
        $pathToReports = $this->assetsPath . $dir;
        $filePath = $this->assetsPath . $dir . $filename;

        if (!is_dir($pathToReports)) {
            mkdir($pathToReports, 0755, true);
        }

        $this->modx->invokeEvent('mpcOnBeforeSaveExcel', [
            'filePath' => $filePath,
            'ExcelFileHandler' => $this
        ]);
        // Путь от плагина mpcOnBeforeSaveExcel принимаем ТОЛЬКО в границах assets
        // (иначе запись .xlsx в произвольный каталог); иначе — дефолтный путь (V7).
        $override = (isset($this->modx->event->returnedValues) && !empty($this->modx->event->returnedValues['filePath']))
            ? (string)$this->modx->event->returnedValues['filePath'] : '';
        if ($override !== '' && $this->withinAssets($override)) {
            $filePath = $override;
        }

        $writer = WriterEntityFactory::createXLSXWriter();
        $writer->openToFile($filePath);

        // Header row
        $writer->addRow($this->createRow($headerLabels));

        // Data rows
        foreach ($data as $rowData) {
            $values = [];
            foreach ($keys as $key) {
                $value = $rowData[$key] ?? '';
                $values[] = is_array($value) ? implode('; ', $value) : $value;
            }

            $this->modx->invokeEvent('mpcOnAddCellToExcel', [
                'values' => $values,
                'keys' => $keys,
                'filePath' => $filePath,
                'ExcelFileHandler' => $this
            ]);

            $writer->addRow($this->createRow($values));
        }

        $writer->close();

        return '/' . str_replace($this->basePath, '', $filePath);
    }

    private function createRow(array $values): Row
    {
        return WriterEntityFactory::createRowFromArray($values);
    }

    /** Путь (или его каталог для ещё-не-созданного файла) внутри assets_path. */
    private function withinAssets(string $path): bool
    {
        $base = realpath($this->assetsPath);
        if ($base === false) {
            return false;
        }
        $base = rtrim($base, '/\\') . DIRECTORY_SEPARATOR;
        $real = realpath($path);
        if ($real === false) { // файл может ещё не существовать (запись) → проверяем каталог
            $real = realpath(dirname($path));
            if ($real !== false) { $real .= DIRECTORY_SEPARATOR; }
        }
        return $real !== false && strpos($real, $base) === 0;
    }

    public function getDataFromFile(string $path): array
    {
        // существующий .xlsx-файл В ГРАНИЦАХ assets (не произвольный путь/расширение, V7)
        if (!is_file($path) || strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'xlsx'
            || !$this->withinAssets($path)) {
            return [];
        }
        $reader = ReaderFactory::createFromType('xlsx');
        $reader->open($path);

        $fileKeys = array_flip($this->fields);
        $listeners = [];
        $fieldLinks = [];

        foreach ($reader->getSheetIterator() as $sheet) {
            $rowIndex = 0;
            foreach ($sheet->getRowIterator() as $row) {
                $cells = $row->toArray();
                if ($rowIndex === 0) {
                    foreach ($cells as $value) {
                        $fieldLinks[] = $fileKeys[$value] ?? $value;
                    }
                } else {
                    $rowData = [];
                    foreach ($cells as $c => $value) {
                        if (!isset($fieldLinks[$c])) {
                            continue;
                        }
                        $rowData[$fieldLinks[$c]] = (string)($value ?? '');
                    }
                    $listeners[$rowIndex + 1] = $rowData;
                }
                $rowIndex++;
            }
            break; // только первый лист
        }

        $reader->close();
        return $listeners;
    }
}
