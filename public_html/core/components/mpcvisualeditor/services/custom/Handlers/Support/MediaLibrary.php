<?php

namespace MpcVEServices\Handlers\Support;

/**
 * Общее ядро загрузки/валидации медиа для всех загрузок редактора (drag-drop в
 * редакторе картинки/медиа и загрузка из файлового менеджера — единый экшен
 * files/upload). До рефактора логика была задвоена между двумя хендлерами
 * (списки расширений, mime, sanitize, имя файла) с РАЗНЫМ поведением. Здесь —
 * единый источник истины.
 *
 * Каноническая папка типа берётся из той же настройки, что и у грабера
 * (`mpc_download_paths` относительно базы источника файлов mpc): ручная загрузка и
 * автоскачивание кладут файлы одного типа в одно место.
 *
 * Чистые хелперы (имя/mime/sanitize/коллизии/url) — СТАТИЧЕСКИЕ: их зовут оба
 * хендлера и юнит-тесты без modX. Методы, читающие настройки (каноническая папка),
 * — инстансные: конструктор принимает $modx.
 */
class MediaLibrary
{
    /** Единые белые списки расширений по типу медиа (ранее дублировались). */
    public const IMAGE_EXT = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'avif'];
    public const VIDEO_EXT = ['mp4', 'webm', 'ogv', 'ogg', 'mov', 'm4v'];
    public const AUDIO_EXT = ['mp3', 'ogg', 'oga', 'wav', 'm4a', 'aac', 'weba'];

    /**
     * Исполняемые/опасные расширения — блок при upload/rename НЕЗАВИСИМО от accept
     * (иначе accept=any пропустил бы .php → webshell, если источник в webroot).
     */
    public const BLOCKED_EXT = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'pht', 'phps', 'phar',
        'shtml', 'cgi', 'pl', 'py', 'sh', 'bash', 'htaccess', 'htpasswd', 'user.ini',
        'asp', 'aspx', 'jsp', 'jspx', 'exe', 'com', 'bat', 'cmd', 'msi', 'dll', 'so',
    ];

    /**
     * Ключи типов в порядке проверки. Совпадают с ключами mpc_download_paths и
     * аргументом грабера downloadFile() (images/videos/audios/others).
     */
    public const TYPE_KEYS = ['images', 'videos', 'audios', 'others'];

    private \modX $modx;

    public function __construct(\modX $modx)
    {
        $this->modx = $modx;
    }

    // --- Чистые хелперы (без modX) -----------------------------------------

    /** Расширение имени в нижнем регистре. */
    public static function extOf(string $name): string
    {
        return strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
    }

    /** Тип-ключ файла по расширению: images|videos|audios|others. */
    public static function typeKeyOfExt(string $ext): string
    {
        $ext = strtolower($ext);
        if (in_array($ext, self::IMAGE_EXT, true)) {
            return 'images';
        }
        if (in_array($ext, self::VIDEO_EXT, true)) {
            return 'videos';
        }
        if (in_array($ext, self::AUDIO_EXT, true)) {
            return 'audios';
        }
        return 'others';
    }

    /** accept (image|video|audio|media|any) → допустимо ли расширение. */
    public static function acceptExt(string $ext, string $accept): bool
    {
        $ext = strtolower($ext);
        if ($accept === 'image') {
            return in_array($ext, self::IMAGE_EXT, true);
        }
        if ($accept === 'video') {
            return in_array($ext, self::VIDEO_EXT, true);
        }
        if ($accept === 'audio') {
            return in_array($ext, self::AUDIO_EXT, true);
        }
        if ($accept === 'media') {
            return in_array($ext, self::IMAGE_EXT, true)
                || in_array($ext, self::VIDEO_EXT, true)
                || in_array($ext, self::AUDIO_EXT, true);
        }
        return true; // any
    }

    /** accept-токен редактора → тип-ключ (для канонической папки нового файла). */
    public static function typeKeyOfAccept(string $accept): string
    {
        switch ($accept) {
            case 'image': return 'images';
            case 'video': return 'videos';
            case 'audio': return 'audios';
            default:      return 'others'; // media/any
        }
    }

    /** Исполняемое/опасное расширение (любой вариант php-тега тоже). */
    public static function isBlockedExt(string $ext): bool
    {
        $ext = strtolower($ext);
        return in_array($ext, self::BLOCKED_EXT, true) || strpos($ext, 'php') !== false;
    }

    /**
     * Блок по ВСЕМУ имени: pathinfo берёт лишь последний сегмент, поэтому
     * shell.php.jpg прошёл бы блок-лист, а Apache с `AddHandler .php` исполнил бы
     * его. Проверяем каждый dot-сегмент + любую `.php` в имени.
     */
    public static function isBlockedName(string $name): bool
    {
        $name = strtolower($name);
        if (strpos($name, '.php') !== false) {
            return true;
        }
        foreach (explode('.', $name) as $seg) {
            if (self::isBlockedExt($seg)) {
                return true;
            }
        }
        return false;
    }

    /** Защита от обхода каталога вверх + нормализация слэшей. */
    public static function cleanPath(string $path): string
    {
        $path = str_replace(['\\', "\0"], ['/', ''], $path);
        $segments = [];
        foreach (explode('/', $path) as $seg) {
            $seg = trim($seg);
            if ($seg === '' || $seg === '.' || $seg === '..' || strpos($seg, '..') !== false) {
                continue;
            }
            $segments[] = $seg;
        }
        return implode('/', $segments);
    }

    /**
     * Базовое имя файла (без расширения) для записи: транслитерация в ASCII +
     * lowercase + спецсимволы→дефис (как грабер mpc). Единая политика для всех
     * загрузок редактора — предсказуемые URL-безопасные имена.
     */
    public static function sanitizeBase(string $name): string
    {
        if (function_exists('transliterator_transliterate')) {
            $name = (string)transliterator_transliterate('Any-Latin; Latin-ASCII', $name);
        }
        $name = strtolower(trim($name));
        $name = preg_replace('/[^a-z0-9_\-]/', '-', $name);
        $name = preg_replace('/-+/', '-', (string)$name);
        return trim((string)$name, '-');
    }

    /**
     * Санитайз SVG: вырезаем активную/исполняемую разметку (stored XSS при открытии
     * в браузере). Не полноценный XML-парсер, но закрывает основные векторы:
     * <script>/<foreignObject>, on*-обработчики, javascript:-ссылки, внешние
     * сущности (DOCTYPE/ENTITY → XXE). Статичная графика не страдает.
     */
    public static function sanitizeSvg(string $svg): string
    {
        $bad = 'script|style|foreignObject|use|set|animate|animateTransform|animateMotion|'
             . 'image|iframe|embed|object|handler|listener|audio|video|a';
        $svg = preg_replace('#<\s*(?:\w+:)?(' . $bad . ')\b[^>]*>.*?<\s*/\s*(?:\w+:)?\1\s*>#is', '', (string)$svg);
        $svg = preg_replace('#<\s*/?\s*(?:\w+:)?(?:' . $bad . ')\b[^>]*>#is', '', (string)$svg);
        $svg = preg_replace('#<!DOCTYPE\b[^>\[]*\[.*?\]\s*>#is', '', (string)$svg);
        $svg = preg_replace('#<!DOCTYPE\b[^>]*>#is', '', (string)$svg);
        $svg = preg_replace('#<!ENTITY[^>]*>#is', '', (string)$svg);
        $svg = preg_replace('#\son[a-z0-9_\-]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#is', '', (string)$svg);
        $svg = preg_replace('#(?:[\w-]+:)?href\s*=\s*("|\')?\s*(?:javascript|vbscript|data):[^"\'>\s]*("|\')?#is', '', (string)$svg);
        return (string)$svg;
    }

    /** Mime-проверка изображения: svg доверяем по расширению, иначе getimagesize. */
    public static function isImageContent(string $tmp, string $ext): bool
    {
        if (strtolower($ext) === 'svg') {
            return true;
        }
        $info = @getimagesize($tmp);
        return $info !== false && strpos((string)($info['mime'] ?? ''), 'image/') === 0;
    }

    /**
     * Видео/аудио по реальному mime (finfo). Контейнеры ogg/webm дают ambiguous
     * mime — принимаем, если начинается с нужного типа ИЛИ это ogg-контейнер.
     * finfo недоступен → доверяем уже проверенному расширению.
     */
    public static function isMediaContent(string $tmp, string $kind): bool
    {
        $mime = self::finfoMime($tmp);
        if ($mime === '') {
            return true;
        }
        return strpos($mime, $kind . '/') === 0 || strpos($mime, 'application/ogg') === 0;
    }

    /** Содержимое не является исполняемым скриптом по реальному mime (finfo). */
    public static function mimeNotExecutable(string $tmp): bool
    {
        $mime = self::finfoMime($tmp);
        if ($mime === '') {
            return true;
        }
        foreach (['application/x-php', 'text/x-php', 'application/x-httpd-php',
                  'application/x-perl', 'application/x-python', 'text/x-shellscript',
                  'application/x-sh', 'application/x-executable', 'application/x-dosexec'] as $bad) {
            if (strpos($mime, $bad) === 0) {
                return false;
            }
        }
        return true;
    }

    private static function finfoMime(string $tmp): string
    {
        if (!function_exists('finfo_open')) {
            return '';
        }
        $finfo = @finfo_open(FILEINFO_MIME_TYPE);
        if (!$finfo) {
            return '';
        }
        $mime = (string)@finfo_file($finfo, $tmp);
        @finfo_close($finfo);
        return $mime;
    }

    /**
     * Финальный URL/путь загруженного файла после нативного пайплайна
     * (uploadObjectsToContainer). Имя на диске могло измениться: проектный плагин-
     * конвертер на OnFileManagerUpload приводит растровые картинки к формату
     * источника. mpc НЕ знает про конкретный плагин — общий контракт идёт через сам
     * источник файлов:
     *  - имя пишет ЯДРО через fileHandler->sanitizePath() — им же вычисляем ожидаемое
     *    имя $pred (как записал нативный пайплайн ДО конвертации);
     *  - целевой формат конвертации — штатное свойство источника thumbnailType
     *    (так же, как его читают ядро и конвертер).
     *
     * Кандидатов ровно два, детерминированы по имени: $pred и base.<thumbnailType>.
     * Существуют оба (конвертер не удалил оригинал) → берём новее по mtime; так
     * требование «конвертер делает replace» снимается, а старый одноимённый файл с
     * прошлой загрузки не перебивает свежий результат.
     *
     * Файловый источник (есть getBasePath) — проверяем диск; иначе фолбэк на $pred.
     *
     * @return array{url:string,path:string}
     */
    public static function resolveUploaded($source, string $dir, string $desired): array
    {
        $dirPath = trim($dir, '/') === '' ? '' : rtrim($dir, '/') . '/';

        // Имя, под которым нативный пайплайн записал файл (тот же sanitizePath ядра).
        $pred = $desired;
        $fh = $source->fileHandler ?? null;
        if (is_object($fh) && method_exists($fh, 'sanitizePath')) {
            $pred = (string)$fh->sanitizePath($desired);
        }

        $base = method_exists($source, 'getBasePath') ? (string)$source->getBasePath() : '';
        if ($base === '') {
            // не файловый источник — диск не проверить, отдаём ожидаемое имя.
            return self::uploadedResult($source, $dirPath, $pred);
        }
        $absDir = rtrim($base, '/') . '/' . $dirPath;

        // Второй детерминированный кандидат: base.<thumbnailType> (результат конвертации).
        $tt  = strtolower((string)$source->getOption('thumbnailType', null, 'png'));
        $alt = $tt !== '' ? pathinfo($pred, PATHINFO_FILENAME) . '.' . $tt : '';

        $predExists = is_file($absDir . $pred);
        $altExists  = $alt !== '' && $alt !== $pred && is_file($absDir . $alt);

        // Конвертированный новее оригинала → берём его (даже если оригинал не удалён).
        if ($altExists && (!$predExists || (int)@filemtime($absDir . $alt) >= (int)@filemtime($absDir . $pred))) {
            return self::uploadedResult($source, $dirPath, $alt);
        }
        if ($predExists) {
            return self::uploadedResult($source, $dirPath, $pred);
        }
        if ($altExists) {
            return self::uploadedResult($source, $dirPath, $alt);
        }
        return self::uploadedResult($source, $dirPath, $pred); // фолбэк
    }

    private static function uploadedResult($source, string $dirPath, string $name): array
    {
        $path = $dirPath . $name;
        return ['url' => (string)$source->getObjectUrl($path), 'path' => $path];
    }

    /**
     * Папка ВНУТРИ источника, в которой лежит файл по его публичному URL —
     * обратная операция к getObjectUrl(). Нужна, чтобы открыть менеджер и грузить
     * drag-drop рядом с уже выбранным файлом поля. URL может быть абсолютным
     * (http://host/…) или root-anchored (/assets/…); срезаем path-часть baseUrl
     * источника (а с сайтом в подпапке — он входит и в url, и в baseUrl).
     *
     * Не резолвится (внешний URL, чужой источник, пусто) → '' (корень источника).
     */
    public static function folderOfUrl($source, string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        $urlPath = rawurldecode((string)(parse_url($url, PHP_URL_PATH) ?: $url));
        $baseUrl = method_exists($source, 'getBaseUrl') ? (string)$source->getBaseUrl() : '';
        $basePath = rawurldecode((string)(parse_url($baseUrl, PHP_URL_PATH) ?: $baseUrl));
        $basePath = trim($basePath, '/');

        $rel = ltrim($urlPath, '/');
        if ($basePath !== '') {
            if (strpos($rel, $basePath . '/') === 0) {
                $rel = substr($rel, strlen($basePath) + 1);
            } elseif ($rel === $basePath) {
                $rel = '';
            } elseif (strpos($rel, $basePath) !== 0) {
                // префикс не совпал → источник не содержит этот url: корень, а не
                // «папка» из чужого дерева.
                return '';
            }
        }

        $dir = (string)pathinfo($rel, PATHINFO_DIRNAME);
        if ($dir === '.' || $dir === '/' || $dir === '\\') {
            $dir = '';
        }
        return self::cleanPath($dir);
    }

    /**
     * Гарантирует существование каталога в источнике (createObject родительские
     * папки не создаёт). Создаём по уровням; «уже есть» — игнорируем.
     */
    public static function ensureContainer($source, string $dir): void
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

    // --- Зависящие от настроек (нужен modX) --------------------------------

    /** Маппинг тип-ключ → подпапка из mpc_download_paths (json|array; пусто допустимо). */
    public function downloadPaths(): array
    {
        $raw = $this->modx->getOption('mpc_download_paths', null, '');
        if (is_array($raw)) {
            return $raw;
        }
        $decoded = json_decode((string)$raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Каноническая папка типа (с хвостовым слэшем) ОТНОСИТЕЛЬНО базы источника:
     * <download_paths[type]|type>/. Источник mpcMedia заскоуплен на медиа-папку
     * (его basePath), поэтому отдельного префикса нет. Та же логика, что у грабера
     * (MediaDownloader::downloadFile): пустая подпапка → имя типа. Сюда по умолчанию
     * кладётся НОВЫЙ файл, если у поля ещё нет значения.
     */
    public function canonicalDir(string $typeKey): string
    {
        $sub = trim((string)($this->downloadPaths()[$typeKey] ?? ''), '/');
        if ($sub === '') {
            $sub = $typeKey;
        }
        return $sub . '/';
    }

    /**
     * Тип-ключ канонической папки, которой принадлежит $dir (== ей или вложен в неё),
     * или null если $dir — обычная папка вне маппинга. Нужно для гибридной защиты
     * «не клади видео в папку картинок»: целевая папка типа T принимает только файлы
     * типа T, даже если accept менеджера = any.
     *
     * Совпадение подпапок разных типов (юзер задал одинаковые download_paths) —
     * берём первый по TYPE_KEYS; защита частично ослабнет, но это явный мисконфиг.
     */
    public function dirTypeKey(string $dir): ?string
    {
        $dir = trim($dir, '/');
        foreach (self::TYPE_KEYS as $key) {
            $canon = trim($this->canonicalDir($key), '/');
            if ($canon === '') {
                continue;
            }
            if ($dir === $canon || strpos($dir . '/', $canon . '/') === 0) {
                return $key;
            }
        }
        return null;
    }

    /**
     * Гибридная проверка «тип файла ↔ целевая папка». Запрещаем, только если папка
     * каноническая для ДРУГОГО типа (видео в images/, картинка в audios/ и т.п.).
     * Обычные папки, папка «своего» типа и общая others/ — пропускаем.
     */
    public function typeFitsDir(string $ext, string $dir): bool
    {
        $dirType = $this->dirTypeKey($dir);
        if ($dirType === null || $dirType === 'others') {
            return true; // не типизированная папка
        }
        return self::typeKeyOfExt($ext) === $dirType;
    }
}
