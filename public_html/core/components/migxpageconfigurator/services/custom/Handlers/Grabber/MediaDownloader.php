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

    /** @var \modMediaSource|null|false кэш источника (false — пробовали, нет) */
    private $source = null;

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

    /**
     * Скачивает медиа по URL в ЕДИНЫЙ источник файлов (modMediaSource),
     * каждый тип — в свою папку: <mediaPath><type>/[<section>/]. Возвращает
     * публичный URL объекта (getObjectUrl) — он и пишется в поле/лексикон.
     * Работа с источником — через его методы (createContainer/createObject).
     */
    public function downloadFile(string $attrValue, string $type = 'others', string $language = ''): string
    {
        $extension = $this->checkDownloadExtension($attrValue)
            ?: $this->detectExtensionByContentType($attrValue);
        if (!$extension) {
            return $attrValue;
        }

        $source = $this->getMediaSource();
        if (!$source) {
            $this->modx->log(\modX::LOG_LEVEL_ERROR, '[MPC MediaDownloader] media source not found (mpc_media_source / default_media_source)');
            return $attrValue;
        }

        // Каталог внутри источника: <mediaPath><type>/[<section>/]
        $prefix = trim((string)($this->properties['mediaPath'] ?? ''), '/');
        $dir = ($prefix !== '' ? $prefix . '/' : '') . $type . '/';
        if ($this->currentSectionName) {
            $dir .= $this->sanitizeFileName($this->currentSectionName) . '/';
        }
        $this->downloadPath = $dir;

        $urlPath  = parse_url($attrValue, PHP_URL_PATH) ?: $attrValue;
        $fileName = pathinfo($urlPath, PATHINFO_FILENAME);
        if ($language) {
            $fileName = $language . '-' . $fileName;
        }

        $this->modx->invokeEvent('mpcOnBeforeDownloadFile', [
            'fileName'     => $fileName,
            'extension'    => $extension,
            'type'         => $type,
            'downloadPath' => $dir,
            'Grabber'      => $this,
        ]);
        $fileName = isset($this->modx->event->returnedValues) && !empty($this->modx->event->returnedValues['fileName'])
            ? $this->modx->event->returnedValues['fileName'] : $fileName;

        $fileName = $this->sanitizeFileName($fileName) . '.' . $extension;
        $objectPath = $dir . $fileName;

        // Уже есть (filesystem) — отдать URL без повторной загрузки.
        $abs = rtrim((string)$source->getBasePath(), '/') . '/' . ltrim($objectPath, '/');
        if (is_file($abs)) {
            return $source->getObjectUrl($objectPath) ?: $attrValue;
        }

        $content = $this->fetch($attrValue);
        if ($content === '') {
            return $attrValue;
        }

        $this->ensureContainer($source, $dir);
        if ($source->createObject($dir, $fileName, $content) === false) {
            $this->modx->log(\modX::LOG_LEVEL_ERROR, '[MPC MediaDownloader] createObject failed: ' . $objectPath . ' errors=' . json_encode($source->getErrors()));
            return $attrValue;
        }

        return $source->getObjectUrl($objectPath) ?: $attrValue;
    }

    /**
     * Источник файлов: mpc_media_source, иначе default_media_source. Кэшируется.
     * @return \modMediaSource|null
     */
    private function getMediaSource()
    {
        if ($this->source !== null) {
            return $this->source ?: null;
        }
        $id = (int)($this->properties['mediaSourceId'] ?? 0);
        if (!$id) {
            $id = (int)$this->modx->getOption('default_media_source', null, 1);
        }
        /** @var \modMediaSource $source */
        $source = $this->modx->getObject('sources.modMediaSource', $id);
        if ($source) {
            $source->initialize();
        }
        $this->source = $source ?: false;
        return $source ?: null;
    }

    /**
     * Гарантирует существование каталога в источнике (createObject родительские
     * папки не создаёт). Создаём по уровням через createContainer; «уже есть» —
     * игнорируем.
     */
    private function ensureContainer($source, string $dir): void
    {
        $dir = trim($dir, '/');
        if ($dir === '') {
            return;
        }
        $parent = '/';
        foreach (explode('/', $dir) as $seg) {
            if ($seg === '') {
                continue;
            }
            $source->createContainer($seg . '/', $parent);
            $parent = ($parent === '/') ? $seg . '/' : $parent . $seg . '/';
        }
    }

    /**
     * Качает содержимое URL (curl). '' при ошибке.
     */
    private function fetch(string $url): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HEADER         => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; MPC/2.0)',
        ]);
        $content  = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($content === false || $content === '' || $httpCode >= 400) {
            $this->modx->log(\modX::LOG_LEVEL_ERROR, "[MPC MediaDownloader] Failed to download: $url (HTTP $httpCode)");
            return '';
        }
        return (string)$content;
    }
}
