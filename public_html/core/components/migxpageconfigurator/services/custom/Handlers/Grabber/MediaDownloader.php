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
        $extension = pathinfo($attrValue, PATHINFO_EXTENSION);
        return in_array($extension, $this->properties['downloadExtensions']) ? $extension : '';
    }

    protected function getBaseDir(): string
    {
        return dirname(__FILE__, 8);
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
        $content = curl_exec($ch);
        curl_close($ch);

        return ($content && file_put_contents($fullPath, $content)) ? $path : '';
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

        if (!$extension = $this->checkDownloadExtension($attrValue)) {
            return $attrValue;
        }

        $this->downloadPath = $this->currentSectionName
            ? $this->properties['downloadPaths'][$type] . $this->currentSectionName . '/'
            : $this->properties['downloadPaths'][$type];

        $fileName = pathinfo($attrValue, PATHINFO_FILENAME);
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

        $fileName = $this->properties['resource']->cleanAlias($fileName);
        if ($path = $this->download($attrValue, $this->downloadPath . $fileName . '.' . $extension)) {
            $attrValue = $path;
        }

        return $attrValue;
    }
}
