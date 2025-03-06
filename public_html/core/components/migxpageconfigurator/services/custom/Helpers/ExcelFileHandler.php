<?php
/**
 * @author Arthur Shevchenko
 * @description Сервис для обработки файлов Excel
 */

namespace MpcServices\Helpers;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

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

    public function __construct(\Modx $modx)
    {
        $this->modx = $modx;
        $this->initialize();
    }

    private function initialize()
    {
        $this->assetsPath = $this->modx->getOption('assets_path', '', MODX_ASSETS_PATH);
    }

    public function generateFile(array $data, string $filename, ?string $dir): string
    {
        if (empty($data)) {
            return '';
        }
        $headers = $this->getFileHeaders(array_keys($data[0]));
        $fileData = array_merge($headers, $this->getFileData($data));
        return $this->createFile($fileData, $filename, $dir);
    }

    private function getFileHeaders(array $keys, ?array $exclude = []): array
    {
        $headers = [];
        foreach ($keys as $i => $key) {
            if (in_array($key, $exclude)) {
                continue;
            }
            $index = $this->getColumnIndex($i + 1);
            $headers[$index . '1'] = $this->fields[$key] ?? $key;
        }
        return $headers;
    }

    private function getColumnIndex(int $number, string $index = ''): string
    {
        $c = $number / 26;
        if ($c > 1) {
            $index .= $this->getColumnIndex($c, $index);
            $number = $number - floor($c) * 26;
        }
        return $index . chr($number + 64);
    }

    private function getFileData($data, ?array $exclude = []): array
    {
        $output = [];
        foreach ($data as $row => $values) {
            $col = 1;
            foreach ($values as $key => $value) {
                if (in_array($key, $exclude)) {
                    unset($values[$key]);
                    continue;
                }
                $index = $this->getColumnIndex($col);
                $output[$index . ($row + 2)] = $value;
                $col++;
            }
        }
        return $output;
    }

    private function createFile(array $data, string $filename, ?string $dir = ''): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $pathToReports = $this->assetsPath . $dir;
        foreach ($data as $cell => $value) {
            $sheet->setCellValue($cell, is_array($value) ? implode('; ', $value) : $value);
        }
        $writer = new Xlsx($spreadsheet);
        if (!is_dir($pathToReports)) {
            mkdir($pathToReports, 0755);
        }
        $filePath = $this->assetsPath . $dir . $filename;
        $writer->save($filePath);
        return '/assets/' . $dir . $filename;
    }

    public function getDataFromFile($path)
    {
        $spreadsheet = IOFactory::load($path);
        $file_keys = array_flip($this->fields);
        $listeners = [];

        $cells = $spreadsheet->getActiveSheet()->getCellCollection();

        for ($row = 1; $row <= $cells->getHighestRow(); $row++) {
            $c = 0;
            if ($row === 1) {
                for ($col = 'A'; $col <= $cells->getHighestColumn(); $col++) {
                    $cell = $cells->get($col . $row);
                    if ($cell) {
                        $value = $cell->getValue();
                        $fieldLinks[] = $file_keys[$value] ?? $value;
                    }
                }
            } else {
                for ($col = 'A'; $col <= $cells->getHighestColumn(); $col++) {
                    $cell = $cells->get($col . $row);
                    $listeners[$row][$fieldLinks[$c]] = $cell ? $cell->getFormattedValue() : '';
                    $c++;
                }
            }
        }
        return $listeners;
    }
}
