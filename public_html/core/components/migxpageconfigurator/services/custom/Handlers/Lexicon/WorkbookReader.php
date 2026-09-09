<?php

namespace MpcServices\Handlers\Lexicon;

/**
 * Чтение книги импорта (xlsx или ZIP из xlsx) в плоский список листов.
 *
 * Вынесено из процессора импорта, чтобы админка и релизный CLI читали книгу
 * ОДНИМ кодом: расхождение в разборе листов означало бы, что CI/CD применяет
 * не то, что показало превью менеджеру.
 *
 * PURE file-IO, без modX.
 */
class WorkbookReader
{
    /**
     * @param string $tmpDir каталог для распаковки ZIP (создаётся вызывающим)
     * @return array<int,array{file:string,book:string,sheet:string,headers:array,rows:array}>
     */
    public static function read(string $path, string $tmpDir): array
    {
        if (!preg_match('/\.zip$/i', $path)) {
            return self::readXlsx($path, basename($path), basename($path));
        }

        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            return [];
        }
        $exDir = rtrim($tmpDir, '/') . '/x_' . bin2hex(random_bytes(8)) . '/';
        mkdir($exDir, 0777, true);

        // ZIP-slip: НЕ доверяем extractTo (его защита от '../' версионно-
        // зависима и обходилась). Извлекаем вручную только *.xlsx, по
        // basename имени записи — '../foo' схлопывается в 'foo' и не может
        // выбраться из $exDir; ничего исполняемого не распаковываем.
        $written = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = (string)$zip->getNameIndex($i);
            if ($entry === '' || substr($entry, -1) === '/') {
                continue; // каталоги
            }
            $name = basename($entry);
            if (!preg_match('/\.xlsx$/i', $name)) {
                continue;
            }
            $stream = $zip->getStream($entry);
            if ($stream === false) {
                continue;
            }
            // индекс-префикс пути исключает перезапись при совпадении basename
            $dest = $exDir . $i . '_' . $name;
            $out  = @fopen($dest, 'wb');
            if ($out !== false) {
                stream_copy_to_stream($stream, $out);
                fclose($out);
                // label — для показа, book — уникальный ключ книги: basename
                // в ZIP может повторяться (файлы из разных папок), а по book
                // группируются манифесты
                $written[] = ['path' => $dest, 'label' => $name, 'book' => $i . '_' . $name];
            }
            fclose($stream);
        }
        $zip->close();

        $sheets = [];
        foreach ($written as $w) {
            foreach (self::readXlsx($w['path'], $w['label'], $w['book']) as $s) {
                $sheets[] = $s;
            }
        }
        foreach (glob($exDir . '*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($exDir);
        return $sheets;
    }

    /** @return array<int,array{file:string,book:string,sheet:string,headers:array,rows:array}> */
    public static function readXlsx(string $path, string $fileLabel, string $book): array
    {
        $reader = \OpenSpout\Reader\Common\Creator\ReaderFactory::createFromType('xlsx');
        $reader->open($path);
        $sheets = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            $headers = [];
            $rows    = [];
            $idx = 0;
            foreach ($sheet->getRowIterator() as $row) {
                $cells = $row->toArray();
                if ($idx === 0) {
                    $headers = $cells;
                } else {
                    $rows[] = $cells;
                }
                $idx++;
            }
            $sheets[] = [
                'file'    => $fileLabel,
                'book'    => $book,
                'sheet'   => $sheet->getName(),
                'headers' => $headers,
                'rows'    => $rows,
            ];
        }
        $reader->close();
        return $sheets;
    }
}
