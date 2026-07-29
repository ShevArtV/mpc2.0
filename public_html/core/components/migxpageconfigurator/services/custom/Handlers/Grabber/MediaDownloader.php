<?php

namespace MpcServices\Handlers\Grabber;

use MpcServices\Handlers\Media\RemoteMediaIngestor;
use MpcServices\Handlers\Support\FileName;

/**
 * Скачивание медиафайлов по URL на сервер (вёрстка → нарезка). Оркестрация:
 * выбор источника, каталог по типу/секции/языку, дедуп, событие
 * mpcOnBeforeDownloadFile. САМО скачивание и запись в источник делегированы
 * RemoteMediaIngestor — единому механизму, общему с редактором mpcVE (вставил
 * ссылку → скачалось). Через него же дёргаются нативные события файлового
 * менеджера, поэтому проектные плагины-конвертеры срабатывают и при нарезке.
 * Полностью независим от DOM и парсинга.
 */
class MediaDownloader
{
    public string $downloadPath = '';

    private string $currentSectionName = '';
    private \modX  $modx;
    private array  $properties;
    private RemoteMediaIngestor $ingestor;

    /** @var \modMediaSource|null|false кэш источника (false — пробовали, нет) */
    private $source = null;

    public function __construct(\modX $modx, array $properties)
    {
        $this->modx       = $modx;
        $this->properties = $properties;
        // Грабер исторически без лимита размера (нарезка серверная, не фронт) —
        // maxBytes=0. Сеть/запись/события — через единый сервис (общий с редактором).
        $this->ingestor   = new RemoteMediaIngestor($modx, [
            'timeout'        => 30,
            'connectTimeout' => 10,
        ]);
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

    /** Расширение по Content-Type (HTTP HEAD). Делегирует единому сервису. */
    public function detectExtensionByContentType(string $url): string
    {
        return $this->ingestor->detectExtensionByContentType(
            $url,
            array_values((array)($this->properties['downloadExtensions'] ?? [])),
            (array)($this->properties['mimeToExt'] ?? [])
        );
    }

    protected function getBaseDir(): string
    {
        return dirname(__FILE__, 8);
    }

    /**
     * Публичный метод API грабера (зовут из проектных плагинов на mpcOn*-событиях),
     * поэтому сохранён. Своей политики больше не держит: имена во всех потоках
     * записи считает единая точка FileName, там же живёт mpcOnSanitizeFileName.
     *
     * $kind/$ctx позволяют вызвать её и для имени папки (snake_case).
     */
    public function sanitizeFileName(string $name, string $kind = FileName::KIND_FILE, array $ctx = []): string
    {
        return FileName::normalize($this->modx, $name, ['kind' => $kind] + $ctx);
    }

    /**
     * Легаси: скачивание во временный путь под baseDir (НЕ в источник). Оставлено
     * для обратной совместимости. SSRF-проверка и сеть — в RemoteMediaIngestor::fetch.
     */
    public function download(string $url, string $path): string
    {
        if (!$path || strpos($path, '..') !== false) {
            return ''; // пустой путь или path traversal
        }
        $baseDir  = $this->getBaseDir();
        $fullPath = $baseDir . $path;
        // итоговый каталог должен оставаться внутри baseDir
        $parentReal = realpath(dirname($fullPath));
        if ($parentReal === false || strpos($parentReal . DIRECTORY_SEPARATOR, realpath($baseDir) . DIRECTORY_SEPARATOR) !== 0) {
            return '';
        }
        if (file_exists($fullPath)) {
            return $path;
        }
        $content = $this->fetch($url); // fetch внутри проверяет isSafeUrl
        if ($content === '') {
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
     * каждый тип — в свою папку: <type>/[<section>/]. Возвращает публичный URL
     * объекта (с учётом возможной конвертации плагином) — он и пишется в
     * поле/лексикон. Запись и нативные события — через RemoteMediaIngestor.
     */
    public function downloadFile(string $attrValue, string $type = 'others', string $language = ''): string
    {
        // Расширение из URL (без сети). HTTP-HEAD НЕ дёргаем сразу: сначала пробуем
        // найти уже скачанный файл локально.
        $extension = $this->checkDownloadExtension($attrValue);

        $source = $this->getMediaSource();
        if (!$source) {
            $this->modx->log(\modX::LOG_LEVEL_ERROR, '[MPC MediaDownloader] media source not found (mpc_media_source / default_media_source)');
            return $attrValue;
        }

        // Каталог ВНУТРИ источника: <download_paths[type]|type>/[<section>/].
        $typeDir = trim((string)($this->properties['downloadPaths'][$type] ?? ''), '/');
        if ($typeDir === '') {
            $typeDir = $type;
        }
        $dir = $typeDir . '/';
        if ($this->currentSectionName) {
            // Имя папки секции — тоже через единую точку (kind=dir → snake_case),
            // иначе проект, переопределивший правила именования, получил бы свои
            // имена файлов внутри папок, названных по старой политике.
            $dir .= FileName::forFolder($this->modx, $this->currentSectionName, [
                'directory' => $typeDir . '/',
                'context'   => FileName::CTX_GRABBER_SECTION,
            ]) . '/';
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

        // mpcOnBeforeDownloadFile выше даёт проекту ОСНОВУ имени (что взять из URL),
        // FileName ниже — политику (как это имя выглядит). События разные и не
        // взаимозаменяемы, поэтому оставлены оба.
        $base    = FileName::forFile($this->modx, (string)$fileName, [
            'extension' => $extension,
            'directory' => $dir,
            'context'   => FileName::CTX_GRABBER,
        ]);
        $baseAbs = rtrim((string)$source->getBasePath(), '/') . '/' . ltrim($dir, '/');

        // Уже скачан? Проверяем по basename БЕЗ сетевого HEAD: с известным
        // расширением (из URL) ИЛИ перебирая разрешённые. Доп. учитываем целевой
        // формат конвертера (thumbnailType источника) — иначе после конвертации
        // jpg→webp дедуп бы не нашёл файл и качал заново при каждой нарезке.
        $exts = $extension !== ''
            ? [$extension]
            : array_filter(array_map('trim', (array)($this->properties['downloadExtensions'] ?? [])));
        $tt = $this->sourceThumbnailType($source);
        if ($tt !== '' && !in_array($tt, $exts, true)) {
            $exts[] = $tt;
        }
        foreach ($exts as $ext) {
            if ($ext !== '' && is_file($baseAbs . $base . '.' . $ext)) {
                return $source->getObjectUrl($dir . $base . '.' . $ext) ?: $attrValue;
            }
        }

        // Локально нет → теперь определяем расширение по типу содержимого
        // (HTTP-HEAD), если в URL его не было, и скачиваем.
        if ($extension === '') {
            $extension = $this->detectExtensionByContentType($attrValue);
        }
        if (!$extension) {
            return $attrValue;
        }

        $fileName = $base . '.' . $extension;

        $content = $this->fetch($attrValue);
        if ($content === '') {
            return $attrValue;
        }
        if (!$this->isAllowedMediaContent($content)) {
            $this->modx->log(\modX::LOG_LEVEL_ERROR, '[MPC MediaDownloader] контент отклонён (похож на скрипт/HTML): ' . $attrValue);
            return $attrValue;
        }

        // Запись в источник + нативные события (плагины-конвертеры) + резолв
        // финального имени/URL после возможной конвертации.
        $res = $this->ingestor->store($source, $dir, $fileName, $content, null, FileName::CTX_GRABBER);
        if ($res === null) {
            return $attrValue;
        }
        return $res['url'] ?: $attrValue;
    }

    /** Целевой формат конвертера — свойство источника thumbnailType (lowercase). */
    private function sourceThumbnailType($source): string
    {
        $props = method_exists($source, 'getPropertyList') ? (array)$source->getPropertyList() : [];
        return strtolower((string)($props['thumbnailType'] ?? ''));
    }

    /**
     * Источник файлов: mpc_media_source, иначе default_media_source. Кэшируется.
     * @return \modMediaSource|null
     */
    protected function getMediaSource()
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

    /** Content sniffing медиа. Делегирует единому сервису. */
    protected function isAllowedMediaContent(string $content): bool
    {
        return $this->ingestor->isAllowedContent($content);
    }

    /**
     * Качает содержимое URL. '' при ошибке/отказе. Делегирует единому сервису
     * (SSRF-гард, таймауты, лимит размера — внутри). Метод-seam для тестов.
     */
    protected function fetch(string $url): string
    {
        return $this->ingestor->fetch($url);
    }
}
