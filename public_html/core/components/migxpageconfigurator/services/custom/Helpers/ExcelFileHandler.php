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
        $pathToReports = $this->assetsPath . $dir;
        $filePath = $this->assetsPath . $dir . $filename;

        if (!is_dir($pathToReports)) {
            mkdir($pathToReports, 0755, true);
        }

        $this->modx->invokeEvent('mpcOnBeforeSaveExcel', [
            'filePath' => $filePath,
            'ExcelFileHandler' => $this
        ]);
        $filePath = isset($this->modx->event->returnedValues) && !empty($this->modx->event->returnedValues['filePath'])
            ? $this->modx->event->returnedValues['filePath'] : $filePath;

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

    public function getDataFromFile(string $path): array
    {
        // существующий .xlsx-файл (не произвольный путь/расширение)
        if (!is_file($path) || strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'xlsx') {
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
