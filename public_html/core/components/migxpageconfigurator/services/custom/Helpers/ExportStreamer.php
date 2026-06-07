<?php
/**
 * @author Arthur Shevchenko
 * @description Серверо-независимая потоковая отдача экспортов лексиконов.
 */

namespace MpcServices\Helpers;

use OpenSpout\Writer\Common\Creator\WriterEntityFactory;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Отдаёт выгрузки лексиконов (XLSX/ZIP) в браузер ПОТОКОМ, без записи в
 * публичный assets-каталог. Закрывает S9: раньше файл клался в
 * assets/.../lexicons-export/ с предсказуемым URL и отдавался веб-сервером
 * напрямую, в обход аутентификации (а .htaccess на nginx не работает).
 *
 * Теперь скачивание идёт тем же коннектором (право mpc_view + токен
 * HTTP_MODAUTH проверены ДО процессора), enforcement живёт в PHP и потому
 * одинаков на Apache/nginx/любом стеке. Публично файл не сохраняется;
 * внутренний temp OpenSpout/ZIP лежит в системном temp и подметается по TTL —
 * отдельный плагин-чистильщик не нужен, т.к. персистентного артефакта нет.
 */
final class ExportStreamer
{
    /** TTL временных артефактов (сек): подметаем сирот от оборванных запросов. */
    private const TEMP_TTL = 3600;

    /**
     * Каталог под временные файлы writer'а/zip: системный temp (вне web-root,
     * реапится ОС), fallback в core/cache при запрете open_basedir на /tmp.
     * Заодно подметает старьё.
     */
    public static function tempDir(\modX $modx): string
    {
        $candidates = [
            rtrim(sys_get_temp_dir(), '/\\') . '/mpc-export',
            rtrim((string)$modx->getOption('core_path'), '/\\') . '/cache/mpc-export',
        ];

        foreach ($candidates as $dir) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0700, true);
            }
            if (is_dir($dir) && is_writable($dir)) {
                self::sweep($dir);
                return $dir;
            }
        }

        // последний шанс — системный temp как есть (без своей подпапки)
        return rtrim(sys_get_temp_dir(), '/\\');
    }

    /** Удаляет файлы/папки старше TEMP_TTL (сироты оборванных стримов). */
    public static function sweep(string $dir): void
    {
        $threshold = time() - self::TEMP_TTL;
        foreach (glob($dir . '/*') ?: [] as $path) {
            $mtime = @filemtime($path);
            if ($mtime === false || $mtime >= $threshold) {
                continue;
            }
            is_dir($path) ? self::rrmdir($path) : @unlink($path);
        }
    }

    private static function rrmdir(string $dir): void
    {
        foreach (glob($dir . '/*') ?: [] as $path) {
            is_dir($path) ? self::rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    /**
     * XLSX-writer, настроенный на потоковую отдачу в браузер. Заголовки
     * (Content-Type/Disposition) OpenSpout ставит сам на open, поэтому ПЕРЕД
     * этим сбрасываем буферы вывода — иначе предупреждение/пробел из буфера
     * испортит бинарный файл.
     */
    public static function xlsxWriterToBrowser(string $downloadName, string $tempDir): Writer
    {
        self::prepareBinaryOutput();
        $writer = WriterEntityFactory::createXLSXWriter();
        $writer->setTempFolder($tempDir);
        $writer->openToBrowser(self::sanitizeFilename($downloadName));
        return $writer;
    }

    /** Корректно завершает отдачу XLSX (close уберёт temp-папку writer'а). */
    public static function finishAndExit(Writer $writer): void
    {
        $writer->close();
        @session_write_close();
        exit;
    }

    /**
     * Отдаёт готовый файл (zip/xlsx) потоком и удаляет его. ZipArchive умеет
     * писать только в реальный файл, поэтому zip собирается во временный путь,
     * затем стримится отсюда.
     */
    public static function streamFileAndExit(string $path, string $downloadName, string $contentType): void
    {
        self::prepareBinaryOutput();
        $size = @filesize($path);
        header('Content-Type: ' . $contentType);
        header('Content-Disposition: attachment; filename="' . self::sanitizeFilename($downloadName) . '"');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');
        if ($size !== false) {
            header('Content-Length: ' . $size);
        }
        readfile($path);
        @unlink($path);
        @session_write_close();
        exit;
    }

    /** Сброс буферов вывода и отключение gzip перед бинарным стримом. */
    private static function prepareBinaryOutput(): void
    {
        @ini_set('zlib.output_compression', 'Off');
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
    }

    /** Имя для Content-Disposition: без разделителей пути, CR/LF и кавычек. */
    private static function sanitizeFilename(string $name): string
    {
        return str_replace(['"', "\r", "\n"], '', basename($name));
    }
}
