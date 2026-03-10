<?php

namespace MpcServices\Handlers\Grabber;

/**
 * Скачивание медиафайлов по URL на сервер.
 * Полностью независим от DOM и парсинга.
 */
class MediaDownloader
{
    public string $downloadPath = '';

    private string $currentSectionName = '';
    private \modX  $modx;
    private array  $properties;

    public function __construct(\modX $modx, array $properties)
    {
        $this->modx       = $modx;
        $this->properties = $properties;
    }

    public function setCurrentSectionName(string $name): void
    {
        $this->currentSectionName = $name;
    }

    public function checkDownloadExtension(string $attrValue): string
    {
        $path = parse_url($attrValue, PHP_URL_PATH) ?: $attrValue;
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return in_array($extension, $this->properties['downloadExtensions']) ? $extension : '';
    }

    public function detectExtensionByContentType(string $url): string
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; MPC/2.0)');
        curl_exec($ch);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        if (!$contentType) {
            return '';
        }

        $mime = strtolower(explode(';', $contentType)[0]);
        $mimeToExt = $this->properties['mimeToExt'] ?? [];
        $extension = $mimeToExt[$mime] ?? '';

        return in_array($extension, $this->properties['downloadExtensions']) ? $extension : '';
    }

    protected function getBaseDir(): string
    {
        return dirname(__FILE__, 8);
    }

    public function sanitizeFileName(string $name): string
    {
        if (function_exists('transliterator_transliterate')) {
            $name = transliterator_transliterate('Any-Latin; Latin-ASCII', $name);
        }
        $name = strtolower(trim($name));
        $name = preg_replace('/[^a-z0-9_\-]/', '-', $name);
        $name = preg_replace('/-+/', '-', $name);
        return trim($name, '-');
    }

    public function download(string $url, string $path): string
    {
        if (!$path) {
            return '';
        }

        $fullPath = $this->getBaseDir() . $path;
        if (file_exists($fullPath)) {
            return $path;
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; MPC/2.0)');
        $content = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!$content || $httpCode >= 400) {
            $this->modx->log(\modX::LOG_LEVEL_ERROR, "[MPC MediaDownloader] Failed to download: $url (HTTP $httpCode)");
            return '';
        }

        return file_put_contents($fullPath, $content) ? $path : '';
    }

    public function downloadImage(string $attrValue, string $language = ''): string
    {
        return $this->downloadFile($attrValue, 'images', $language);
    }

    public function downloadVideo(string $attrValue, string $language = ''): string
    {
        return $this->downloadFile($attrValue, 'videos', $language);
    }

    public function downloadAudio(string $attrValue, string $language = ''): string
    {
        return $this->downloadFile($attrValue, 'audios', $language);
    }

    public function downloadFile(string $attrValue, string $type = 'others', string $language = ''): string
    {
        if (empty($this->properties['downloadPaths'][$type])) {
            return $attrValue;
        }

        $extension = $this->checkDownloadExtension($attrValue)
            ?: $this->detectExtensionByContentType($attrValue);

        if (!$extension) {
            return $attrValue;
        }

        $this->downloadPath = $this->currentSectionName
            ? $this->properties['downloadPaths'][$type] . $this->currentSectionName . '/'
            : $this->properties['downloadPaths'][$type];

        $urlPath  = parse_url($attrValue, PHP_URL_PATH) ?: $attrValue;
        $fileName = pathinfo($urlPath, PATHINFO_FILENAME);
        if ($language) {
            $fileName = $language . '-' . $fileName;
        }

        $this->modx->invokeEvent('mpcOnBeforeDownloadFile', [
            'fileName'     => $fileName,
            'extension'    => $extension,
            'type'         => $type,
            'downloadPath' => $this->downloadPath,
            'Grabber'      => $this,
        ]);

        $fileName = isset($this->modx->event->returnedValues) && !empty($this->modx->event->returnedValues['fileName'])
            ? $this->modx->event->returnedValues['fileName'] : $fileName;

        $fullPathToDir = $this->getBaseDir() . $this->downloadPath;
        if (!file_exists($fullPathToDir)) {
            mkdir($fullPathToDir, 0777, true);
        }

        $fileName = $this->sanitizeFileName($fileName);
        if ($path = $this->download($attrValue, $this->downloadPath . $fileName . '.' . $extension)) {
            $attrValue = $path;
        }

        return $attrValue;
    }
}
