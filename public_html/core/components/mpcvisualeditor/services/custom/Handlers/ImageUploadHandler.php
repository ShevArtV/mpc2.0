<?php

namespace MpcVEServices\Handlers;

use MpcVEServices\Mpcve;

/**
 * Экшен image/upload: приём загруженного медиа-файла, запись в MODX media source
 * (тот же, что использует грабер mpc: `mpc_media_source` → `default_media_source`)
 * и возврат публичного URL. Сам URL фронт затем пишет в поле через field/save —
 * здесь только загрузка.
 *
 * Тип файла — параметр `kind` (image|video|audio, по умолчанию image): задаёт
 * белый список расширений, mime-проверку и подпапку (images/ | videos/ | audios/).
 * Папка: `mpc_media_path` + подпапка (консистентно с грабером). Имя файла —
 * санитайз исходного + короткий sha1 контента (дедуп одинаковых, без коллизий).
 */
class ImageUploadHandler
{
    /** Белые списки расширений по типу медиа. */
    private const ALLOWED = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'avif'];
    private const ALLOWED_VIDEO = ['mp4', 'webm', 'ogv', 'ogg', 'mov', 'm4v'];
    private const ALLOWED_AUDIO = ['mp3', 'ogg', 'oga', 'wav', 'm4a', 'aac', 'weba'];

    /** Тип медиа → [расширения, подпапка, базовое имя по умолчанию]. */
    private const KINDS = [
        'image' => [self::ALLOWED,       'images/', 'image'],
        'video' => [self::ALLOWED_VIDEO, 'videos/', 'video'],
        'audio' => [self::ALLOWED_AUDIO, 'audios/', 'audio'],
    ];

    private \modX $modx;
    private Mpcve $mpcve;

    public function __construct(\modX $modx, Mpcve $mpcve)
    {
        $this->modx  = $modx;
        $this->mpcve = $mpcve;
    }

    public function handle(array $request): array
    {
        $file = $_FILES['file'] ?? null;
        if (!is_array($file) || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return $this->err($this->modx->lexicon('mpcve_err_upload'));
        }
        if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            return $this->err($this->modx->lexicon('mpcve_err_upload'));
        }

        $kind = (string)($request['kind'] ?? 'image');
        if (!isset(self::KINDS[$kind])) {
            $kind = 'image';
        }
        [$allowed, $subdir, $defaultBase] = self::KINDS[$kind];

        $maxBytes = (int)$this->modx->getOption('mpcve_max_upload', null, 10 * 1024 * 1024);
        if ($maxBytes > 0 && (int)($file['size'] ?? 0) > $maxBytes) {
            return $this->err($this->modx->lexicon('mpcve_err_upload_size'));
        }

        $ext = strtolower((string)pathinfo((string)$file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed, true) || !$this->mimeOk((string)$file['tmp_name'], $ext, $kind)) {
            return $this->err($this->modx->lexicon('mpcve_err_upload_ext'));
        }

        $content = file_get_contents($file['tmp_name']);
        if ($content === false || $content === '') {
            return $this->err($this->modx->lexicon('mpcve_err_upload'));
        }

        // SVG — XML, исполняется браузером при открытии → stored XSS. getimagesize
        // его не валидирует (isImage пропускает по расширению), поэтому вырезаем
        // активную разметку перед записью.
        if ($ext === 'svg') {
            $content = $this->sanitizeSvg($content);
        }

        $source = $this->getMediaSource();
        if (!$source) {
            return $this->err($this->modx->lexicon('mpcve_err_source'));
        }

        return $this->processUpload($source, $this->uploadDir($subdir), (string)$file['name'], $ext, $content, $defaultBase);
    }

    /**
     * Чистое ядро записи: строит имя, гарантирует папку, пишет объект, возвращает
     * результат с URL. Без $modx — тестируется с fake-source.
     *
     * @param object $source modMediaSource (или совместимый)
     */
    public function processUpload($source, string $dir, string $originalName, string $ext, string $content, string $defaultBase = 'image'): array
    {
        $base = $this->sanitizeFileName((string)pathinfo($originalName, PATHINFO_FILENAME)) ?: $defaultBase;
        $name = $base . '-' . substr(sha1($content), 0, 8) . '.' . $ext;

        $this->ensureContainer($source, $dir);
        if ($source->createObject($dir, $name, $content) === false) {
            $errors = method_exists($source, 'getErrors') ? implode('; ', (array)$source->getErrors()) : '';
            return $this->err(trim($this->lexicon('mpcve_err_upload') . ' ' . $errors));
        }

        $url = $source->getObjectUrl($dir . $name);
        if (!$url) {
            return $this->err($this->lexicon('mpcve_err_upload'));
        }

        return [
            'success' => true,
            'message' => $this->lexicon('mpcve_uploaded'),
            'data'    => ['url' => $url],
        ];
    }

    /** Обёртка над modx->lexicon (переопределяется в юнит-тестах). */
    protected function lexicon(string $key): string
    {
        return (string)$this->modx->lexicon($key);
    }

    /**
     * Транслитерация + lowercase + замена спецсимволов на дефис (как грабер mpc).
     */
    public function sanitizeFileName(string $name): string
    {
        if (function_exists('transliterator_transliterate')) {
            $name = (string)transliterator_transliterate('Any-Latin; Latin-ASCII', $name);
        }
        $name = strtolower(trim($name));
        $name = preg_replace('/[^a-z0-9_\-]/', '-', $name);
        $name = preg_replace('/-+/', '-', $name);
        return trim((string)$name, '-');
    }

    /**
     * Санитайз SVG: вырезаем активную/исполняемую разметку (stored XSS при
     * открытии в браузере). Не полноценный XML-парсер, но закрывает основные
     * векторы: <script>, <foreignObject>, обработчики on*, javascript:-ссылки,
     * внешние сущности (DOCTYPE/ENTITY → XXE). Статичная графика не страдает.
     */
    public function sanitizeSvg(string $svg): string
    {
        // Опасные элементы (вкл. неймспейс-префикс <svg:script> и SMIL-анимацию,
        // которая может задать href=javascript: через attributeName) — вырезаем
        // вместе с содержимым, затем одиночные/незакрытые.
        $bad = 'script|foreignObject|use|set|animate|animateTransform|animateMotion|'
             . 'image|iframe|embed|object|handler|listener|audio|video|a';
        $svg = preg_replace('#<\s*(?:\w+:)?(' . $bad . ')\b[^>]*>.*?<\s*/\s*(?:\w+:)?\1\s*>#is', '', (string)$svg);
        $svg = preg_replace('#<\s*/?\s*(?:\w+:)?(?:' . $bad . ')\b[^>]*>#is', '', (string)$svg);
        // DOCTYPE/ENTITY (XXE)
        $svg = preg_replace('#<!DOCTYPE[^>]*>#is', '', (string)$svg);
        $svg = preg_replace('#<!ENTITY[^>]*>#is', '', (string)$svg);
        // обработчики событий on*="..."/'...'/без кавычек
        $svg = preg_replace('#\son[a-z0-9_\-]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#is', '', (string)$svg);
        // javascript:/опасные схемы в href/xlink:href/любом *:href
        $svg = preg_replace('#(?:[\w-]+:)?href\s*=\s*("|\')?\s*(?:javascript|vbscript|data):[^"\'>\s]*("|\')?#is', '', (string)$svg);
        return (string)$svg;
    }

    /** Mime-проверка по типу медиа. image → getimagesize; video/audio → finfo. */
    private function mimeOk(string $tmp, string $ext, string $kind): bool
    {
        if ($kind === 'image') {
            return $this->isImage($tmp, $ext);
        }
        return $this->isMedia($tmp, $kind);
    }

    private function isImage(string $tmp, string $ext): bool
    {
        // svg — XML, getimagesize не валидирует; доверяем расширению (загрузка
        // только авторизованному mgr-юзеру с правом mpcve_edit).
        if ($ext === 'svg') {
            return true;
        }
        $info = @getimagesize($tmp);
        return $info !== false && strpos((string)($info['mime'] ?? ''), 'image/') === 0;
    }

    /**
     * Видео/аудио: проверяем по реальному mime (finfo). Контейнер ogg/webm
     * даёт ambiguous mime (video/ vs audio/ vs application/ogg) — принимаем,
     * если mime начинается с нужного типа ИЛИ это ogg-контейнер. Если finfo
     * недоступен — доверяем уже проверенному расширению (право mpcve_edit).
     */
    private function isMedia(string $tmp, string $kind): bool
    {
        if (!function_exists('finfo_open')) {
            return true;
        }
        $finfo = @finfo_open(FILEINFO_MIME_TYPE);
        if (!$finfo) {
            return true;
        }
        $mime = (string)@finfo_file($finfo, $tmp);
        @finfo_close($finfo);
        if ($mime === '') {
            return true;
        }
        return strpos($mime, $kind . '/') === 0
            || strpos($mime, 'application/ogg') === 0;
    }

    private function uploadDir(string $subdir = 'images/'): string
    {
        $prefix = trim((string)$this->modx->getOption(
            'mpc_media_path',
            null,
            'assets/components/migxpageconfigurator/media/'
        ), '/');
        return ($prefix !== '' ? $prefix . '/' : '') . $subdir;
    }

    private function getMediaSource()
    {
        $id = (int)$this->modx->getOption('mpc_media_source', null, 0);
        if (!$id) {
            $id = (int)$this->modx->getOption('default_media_source', null, 1);
        }
        /** @var \modMediaSource|null $source */
        $source = $this->modx->getObject('sources.modMediaSource', $id);
        if ($source) {
            $source->initialize();
        }
        return $source ?: null;
    }

    /**
     * Гарантирует существование каталога в источнике (createObject родительские
     * папки не создаёт). Создаём по уровням; «уже есть» — игнорируем.
     *
     * @param object $source
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

    private function err(string $message): array
    {
        return ['success' => false, 'message' => $message ?: 'upload failed', 'data' => []];
    }
}
