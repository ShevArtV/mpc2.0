<?php

namespace MpcServices\Handlers\Media;

use MpcServices\Handlers\Support\FileName;

/**
 * ЕДИНЫЙ механизм скачивания медиа по URL для обоих сценариев:
 *  - вёрстка (грабер MediaDownloader, CLI mgr_tpl) — авто-скачивание src/srcset/poster
 *    из шаблона при нарезке;
 *  - редактор (mpcVE FileManagerHandler::downloadUrl) — «вставил ссылку → скачалось».
 *
 * Концерны (примитивы, оба вызывающих их компонуют сами):
 *  - fetch()        — безопасное скачивание во временный буфер (SSRF-гард, таймауты,
 *                     жёсткий лимит размера с обрывом on-the-fly);
 *  - detect/allow   — определение расширения по Content-Type, отсев скрипт/HTML;
 *  - store()        — запись в modMediaSource через createObject + РУЧНОЙ вызов
 *                     нативных событий OnFileManagerBeforeUpload/OnFileManagerUpload,
 *                     чтобы срабатывали проектные плагины-конвертеры (как при ручной
 *                     загрузке в файловом менеджере), + резолв финального URL после
 *                     возможной конвертации (resolveFinal).
 *
 * Почему НЕ нативный uploadObjectsToContainer: на MODX 2 он перемещает файл через
 * move_uploaded_file() (внутри is_uploaded_file()) — скачанный по URL tmp туда не
 * передать. createObject + ручное событие даёт ОДИН путь и для MODX 2, и для MODX 3.
 *
 * SSRF/curl-логика портирована из MediaDownloader (security-review V4) и теперь живёт
 * здесь как единственный источник; MediaDownloader делегирует сюда.
 */
class RemoteMediaIngestor
{
    /** Коды последней ошибки fetch() для маппинга в лексикон вызывающим. */
    public const ERR_NONE     = '';
    public const ERR_SSRF     = 'ssrf';
    public const ERR_HTTP     = 'http';
    public const ERR_TOOLARGE = 'toolarge';
    public const ERR_EMPTY    = 'empty';

    private \modX $modx;

    /** Жёсткий лимит размера в байтах (0 = без лимита). */
    private int $maxBytes;
    private int $timeout;
    private int $connectTimeout;
    private string $userAgent;

    /** Дёргать ли OnFileManagerUpload при store() (null → читаем настройку). */
    private ?bool $fireUploadEvent;

    /** host(lower) => проверенный IP для CURLOPT_RESOLVE (anti DNS-rebinding). */
    private array $safeIp = [];

    private string $lastError = self::ERR_NONE;

    /**
     * @param array $opts maxBytes,timeout,connectTimeout,userAgent,fireUploadEvent
     */
    public function __construct(\modX $modx, array $opts = [])
    {
        $this->modx           = $modx;
        $this->maxBytes       = max(0, (int)($opts['maxBytes'] ?? 0));
        $this->timeout        = max(1, (int)($opts['timeout'] ?? 30));
        $this->connectTimeout = max(1, (int)($opts['connectTimeout'] ?? 10));
        $this->userAgent      = (string)($opts['userAgent'] ?? 'Mozilla/5.0 (compatible; MPC/2.0)');
        $this->fireUploadEvent = array_key_exists('fireUploadEvent', $opts)
            ? (bool)$opts['fireUploadEvent'] : null;
    }

    public function getLastError(): string
    {
        return $this->lastError;
    }

    /**
     * Карта MIME → расширение из настройки mpc_mime_to_ext_path (относительно
     * core_path) — та же, что у грабера. Для детекта расширения URL без него.
     */
    public static function loadMimeToExt(\modX $modx): array
    {
        $rel = (string)$modx->getOption(
            'mpc_mime_to_ext_path',
            null,
            'components/migxpageconfigurator/elements/media/mime_to_ext.json'
        );
        $path = rtrim((string)$modx->getOption('core_path', null, MODX_CORE_PATH), '/') . '/' . ltrim($rel, '/');
        if (is_file($path) && ($raw = file_get_contents($path))) {
            return json_decode($raw, true) ?: [];
        }
        return [];
    }

    // --- SSRF-гард (порт MediaDownloader, security V4) ----------------------

    /**
     * Только http/https и публичные адреса. Блокируем file://, loopback, RFC-1918,
     * link-local (вкл. 169.254.169.254 cloud-metadata). Хост резолвится — проверяются
     * ВСЕ IP; первый прошедший пиним для curl (anti TOCTOU rebinding).
     */
    public function isSafeUrl(string $url): bool
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
            return false;
        }
        foreach ($ips as $ip) {
            if ($this->isBlockedIp($ip)) {
                return false;
            }
        }
        $this->safeIp[strtolower($host)] = $ips[0];
        return true;
    }

    /**
     * Внутренний/опасный IP. FILTER_FLAG_NO_RES_RANGE до PHP 8.1 не ловит IPv6
     * ::1/ULA/link-local — на целевом php-7.4 это обход SSRF. Нормализуем
     * IPv4-mapped IPv6 (::ffff:127.0.0.1).
     */
    private function isBlockedIp(string $ip): bool
    {
        $lc = strtolower(trim($ip, '[]'));
        if (strpos($lc, '::ffff:') === 0 && filter_var(substr($lc, 7), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $lc = substr($lc, 7);
        }
        if (!filter_var($lc, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return true;
        }
        if ($lc === '::1' || $lc === '::' || preg_match('/^(fe80|fc|fd)/', $lc)) {
            return true;
        }
        return false;
    }

    /** Базовые curl-опции с защитой: SSL-верификация + только http/https. */
    private function curlSecurityOpts(): array
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

    /** CURLOPT_RESOLVE-пин проверенного IP для хоста URL (порты 80/443). */
    private function curlResolveOpt(string $url): array
    {
        $host = strtolower((string)(parse_url($url, PHP_URL_HOST) ?? ''));
        if ($host === '' || empty($this->safeIp[$host])) {
            return [];
        }
        $ip = $this->safeIp[$host];
        return [CURLOPT_RESOLVE => [$host . ':80:' . $ip, $host . ':443:' . $ip]];
    }

    // --- Скачивание ---------------------------------------------------------

    /**
     * Определение расширения по Content-Type (HTTP HEAD). '' если неизвестно/не в
     * whitelist. Сетевой запрос — звать только когда расширение из URL не извлечь.
     */
    public function detectExtensionByContentType(string $url, array $allowedExts, array $mimeToExt): string
    {
        if (!$this->isSafeUrl($url)) {
            return '';
        }
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt_array($ch, $this->curlSecurityOpts());
        curl_setopt_array($ch, $this->curlResolveOpt($url));
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, min(10, $this->timeout));
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $this->connectTimeout);
        curl_setopt($ch, CURLOPT_USERAGENT, $this->userAgent);
        curl_exec($ch);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        if (!$contentType) {
            return '';
        }
        $mime = strtolower(explode(';', $contentType)[0]);
        $extension = $mimeToExt[$mime] ?? '';
        return in_array($extension, $allowedExts, true) ? $extension : '';
    }

    /**
     * Качает содержимое URL (curl). '' при ошибке/отказе/превышении лимита; код
     * причины — в getLastError(). Лимит размера обрывает закачку on-the-fly
     * (progress-callback), не дожидаясь конца тела — память ограничена сверху.
     */
    public function fetch(string $url): string
    {
        $this->lastError = self::ERR_NONE;
        if (!$this->isSafeUrl($url)) {
            $this->lastError = self::ERR_SSRF;
            $this->modx->log(\modX::LOG_LEVEL_ERROR, "[MPC RemoteMediaIngestor] небезопасный URL отклонён: $url");
            return '';
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, $this->curlSecurityOpts() + [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_USERAGENT      => $this->userAgent,
        ]);
        curl_setopt_array($ch, $this->curlResolveOpt($url));
        if ($this->maxBytes > 0) {
            curl_setopt($ch, CURLOPT_NOPROGRESS, false);
            curl_setopt($ch, CURLOPT_BUFFERSIZE, 16384);
            curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, function ($ch, $dlTotal, $dlNow) {
                // Известный Content-Length больше лимита ИЛИ уже скачано больше —
                // обрываем (возврат !=0 → CURLE_ABORTED_BY_CALLBACK).
                if ($dlTotal > $this->maxBytes || $dlNow > $this->maxBytes) {
                    return 1;
                }
                return 0;
            });
        }
        $content  = curl_exec($ch);
        $errno    = curl_errno($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno === CURLE_ABORTED_BY_CALLBACK) {
            $this->lastError = self::ERR_TOOLARGE;
            $this->modx->log(\modX::LOG_LEVEL_ERROR, "[MPC RemoteMediaIngestor] превышен лимит размера: $url");
            return '';
        }
        if ($content === false || $content === '' || $httpCode >= 400) {
            $this->lastError = $content === false || $content === '' ? self::ERR_EMPTY : self::ERR_HTTP;
            $this->modx->log(\modX::LOG_LEVEL_ERROR, "[MPC RemoteMediaIngestor] ошибка скачивания: $url (HTTP $httpCode, curl $errno)");
            return '';
        }
        // Двойная подстраховка к progress-обрыву (на случай его недоступности).
        if ($this->maxBytes > 0 && strlen($content) > $this->maxBytes) {
            $this->lastError = self::ERR_TOOLARGE;
            return '';
        }
        return (string)$content;
    }

    /**
     * Content sniffing: отклоняем исполняемый/скриптовый контент под видом медиа
     * (webshell `image.jpg` с PHP внутри). Доп. защита к whitelist расширений.
     */
    public function isAllowedContent(string $content): bool
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

    // --- Запись в источник + нативные события -------------------------------

    /**
     * Записывает контент в источник как «загруженный файл» и дёргает нативные
     * события файлового менеджера, чтобы отработали проектные плагины-конвертеры
     * (OnFileManagerUpload). Возвращает финальные url/path/name с учётом возможной
     * конвертации (resolveFinal). null при ошибке записи.
     *
     * $fireEvent === null → берём настройку mpc_fire_upload_event (default true).
     * $context — поток записи для mpcOnSanitizeFileName (кто вызвал: грабер или
     * редактор), нужен, только если имя переопределит плагин на BeforeUpload.
     *
     * @return array{url:string,path:string,name:string}|null
     */
    public function store($source, string $dir, string $fileName, string $content, ?bool $fireEvent = null, string $context = ''): ?array
    {
        $fire = $fireEvent ?? $this->fireUploadEvent;
        if ($fire === null) {
            $fire = (bool)$this->modx->getOption('mpc_fire_upload_event', null, true);
        }

        $this->ensureContainer($source, $dir);

        // Дескриптор «загруженного» файла в нативной форме (как ждут плагины на
        // событиях файлового менеджера: ключ 'file', поля name/tmp_name/error/...).
        $files = ['file' => [
            'name'     => $fileName,
            'tmp_name' => '',
            'type'     => '',
            'error'    => 0,
            'size'     => strlen($content),
        ]];

        if ($fire) {
            // BeforeUpload — до записи (как в нативном пайплайне: файла ещё нет).
            $this->modx->invokeEvent('OnFileManagerBeforeUpload', [
                'files'     => &$files,
                'file'      => &$files['file'],
                'directory' => $dir,
                'source'    => &$source,
            ]);
            // Плагин на этом событии переименовывает файл, меняя дескриптор —
            // раньше правку выбрасывали и писали свою $fileName, из-за чего
            // нативное событие здесь было бутафорией, а resolveFinal() искал не
            // тот файл. Берём имя из дескриптора и прогоняем через ЕДИНУЮ точку
            // (политика пакета + mpcOnSanitizeFileName + security-постфильтр):
            // имя из плагина не должно уводить запись из каталога.
            $afterEvent = trim((string)($files['file']['name'] ?? ''));
            if ($afterEvent !== '' && $afterEvent !== $fileName) {
                $fileName = FileName::forFileName($this->modx, $afterEvent, [
                    'directory' => $dir,
                    'context'   => $context !== '' ? $context : FileName::CTX_EDITOR_URL,
                ]);
                $files['file']['name'] = $fileName;
            }
        }

        if ($source->createObject($dir, $fileName, $content) === false) {
            $this->modx->log(\modX::LOG_LEVEL_ERROR, '[MPC RemoteMediaIngestor] createObject failed: ' . $dir . $fileName . ' errors=' . json_encode($source->getErrors()));
            return null;
        }

        if ($fire) {
            // Upload — после записи: конвертер читает файл с диска и переформатирует.
            $this->modx->invokeEvent('OnFileManagerUpload', [
                'files'     => &$files,
                'directory' => $dir,
                'source'    => &$source,
            ]);
        }

        return $this->resolveFinal($source, $dir, $fileName);
    }

    /**
     * Финальный url/path после возможной конвертации плагином на OnFileManagerUpload.
     * Кандидатов два, детерминированы по имени: $fileName и base.<thumbnailType>
     * (целевой формат источника). Существуют оба — берём новее по mtime. Логика
     * совпадает с MpcVEServices\Handlers\Support\MediaLibrary::resolveUploaded
     * (mpcVE не доступен из mpc — namespace-граница, поэтому копия).
     *
     * @return array{url:string,path:string,name:string}
     */
    public function resolveFinal($source, string $dir, string $fileName): array
    {
        $dirPath = trim($dir, '/') === '' ? '' : rtrim($dir, '/') . '/';

        $base = method_exists($source, 'getBasePath') ? (string)$source->getBasePath() : '';
        if ($base === '') {
            return $this->finalResult($source, $dirPath, $fileName);
        }
        $absDir = rtrim($base, '/') . '/' . $dirPath;

        $props = method_exists($source, 'getPropertyList') ? (array)$source->getPropertyList() : [];
        $tt  = strtolower((string)($props['thumbnailType'] ?? ''));
        $alt = $tt !== '' ? pathinfo($fileName, PATHINFO_FILENAME) . '.' . $tt : '';

        $predExists = is_file($absDir . $fileName);
        $altExists  = $alt !== '' && $alt !== $fileName && is_file($absDir . $alt);

        if ($altExists && (!$predExists || (int)@filemtime($absDir . $alt) >= (int)@filemtime($absDir . $fileName))) {
            return $this->finalResult($source, $dirPath, $alt);
        }
        if ($predExists) {
            return $this->finalResult($source, $dirPath, $fileName);
        }
        if ($altExists) {
            return $this->finalResult($source, $dirPath, $alt);
        }
        return $this->finalResult($source, $dirPath, $fileName);
    }

    private function finalResult($source, string $dirPath, string $name): array
    {
        $path = $dirPath . $name;
        return [
            'url'  => (string)$source->getObjectUrl($path),
            'path' => $path,
            'name' => $name,
        ];
    }

    /**
     * Гарантирует существование каталога в источнике (createObject родительские
     * папки не создаёт). Создаём по уровням; «уже есть» — игнорируем.
     */
    public function ensureContainer($source, string $dir): void
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
}
