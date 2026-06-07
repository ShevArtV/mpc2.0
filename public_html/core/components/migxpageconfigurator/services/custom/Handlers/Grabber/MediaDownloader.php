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
        if (!$this->isSafeUrl($url)) {
            return '';
        }
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt_array($ch, $this->curlSecurityOpts());
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

    /**
     * Защита от SSRF: разрешаем только http/https и публичные адреса. Блокируем
     * file://, gopher://, dict://, loopback, RFC-1918, link-local (включая
     * 169.254.169.254 — cloud-metadata). Хост резолвится — проверяются ВСЕ IP.
     */
    protected function isSafeUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
            return false;
        }
        if (!in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return false;
        }
        $host = $parts['host'];
        $ips = [];
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips[] = $host;
        } else {
            foreach (@dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $r) {
                if (!empty($r['ip'])) { $ips[] = $r['ip']; }
                if (!empty($r['ipv6'])) { $ips[] = $r['ipv6']; }
            }
            if (!$ips) {
                $ip = gethostbyname($host);
                if ($ip && $ip !== $host) { $ips[] = $ip; }
            }
        }
        if (!$ips) {
            return false; // не резолвится — не качаем
        }
        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return false; // приватный/зарезервированный/loopback/link-local
            }
        }
        return true;
    }

    /** Базовые curl-опции с защитой: SSL-верификация + только http/https. */
    protected function curlSecurityOpts(): array
    {
        $opts = [
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];
        if (defined('CURLPROTO_HTTP')) {
            $opts[CURLOPT_PROTOCOLS]       = CURLPROTO_HTTP | CURLPROTO_HTTPS;
            $opts[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        }
        return $opts;
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
        if (!$path || strpos($path, '..') !== false) {
            return ''; // пустой путь или path traversal
        }
        if (!$this->isSafeUrl($url)) {
            return '';
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

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt_array($ch, $this->curlSecurityOpts());
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
        // Расширение из URL (без сети). HTTP-HEAD (detectExtensionByContentType)
        // НЕ дёргаем сразу: для внешних URL без расширения (loremflickr и т.п.)
        // это сетевой запрос на КАЖДОЕ медиа при КАЖДОМ грабе, даже если файл уже
        // скачан. Сначала пробуем найти уже скачанный файл локально.
        $extension = $this->checkDownloadExtension($attrValue);

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

        $base    = $this->sanitizeFileName($fileName);
        $baseAbs = rtrim((string)$source->getBasePath(), '/') . '/' . ltrim($dir, '/');

        // Уже скачан? Проверяем по basename — с известным расширением (из URL) или
        // перебирая разрешённые (для URL без расширения), БЕЗ сетевого HEAD.
        $exts = $extension !== ''
            ? [$extension]
            : array_filter(array_map('trim', (array)($this->properties['downloadExtensions'] ?? [])));
        foreach ($exts as $ext) {
            if ($ext !== '' && is_file($baseAbs . $base . '.' . $ext)) {
                return $source->getObjectUrl($dir . $base . '.' . $ext) ?: $attrValue;
            }
        }

        // Локально нет → теперь (и только теперь) определяем расширение по типу
        // содержимого (HTTP-HEAD), если в URL его не было, и скачиваем.
        if ($extension === '') {
            $extension = $this->detectExtensionByContentType($attrValue);
        }
        if (!$extension) {
            return $attrValue;
        }

        $fileName   = $base . '.' . $extension;
        $objectPath = $dir . $fileName;

        $content = $this->fetch($attrValue);
        if ($content === '') {
            return $attrValue;
        }
        if (!$this->isAllowedMediaContent($content)) {
            $this->modx->log(\modX::LOG_LEVEL_ERROR, '[MPC MediaDownloader] контент отклонён (похож на скрипт/HTML): ' . $attrValue);
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
     * Content sniffing: отклоняем исполняемый/скриптовый контент под видом медиа
     * (webshell `image.jpg` с PHP внутри). Расширение уже ограничено whitelist'ом
     * (downloadExtensions) — это доп. защита по содержимому.
     */
    protected function isAllowedMediaContent(string $content): bool
    {
        $head = strtolower(substr($content, 0, 512));
        foreach (['<?php', '<?=', '<script', '<html', '<!doctype'] as $marker) {
            if (strpos($head, $marker) !== false) {
                return false;
            }
        }
        if (function_exists('finfo_open') && ($finfo = finfo_open(FILEINFO_MIME_TYPE))) {
            $mime = strtolower((string)finfo_buffer($finfo, $content));
            finfo_close($finfo);
            if ($mime !== '' && (strpos($mime, 'php') !== false || strpos($mime, 'html') !== false || strpos($mime, 'javascript') !== false)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Качает содержимое URL (curl). '' при ошибке.
     */
    protected function fetch(string $url): string
    {
        if (!$this->isSafeUrl($url)) {
            $this->modx->log(\modX::LOG_LEVEL_ERROR, "[MPC MediaDownloader] небезопасный URL отклонён: $url");
            return '';
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, $this->curlSecurityOpts() + [
            CURLOPT_RETURNTRANSFER => true,
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
