<?php

namespace MpcVEServices\Handlers;

use MpcVEServices\Mpcve;

/**
 * Мини-файловый менеджер редактора поверх modMediaSource: навигация по папкам,
 * листинг, CRUD папок/файлов и загрузка в текущую папку. Используется редакторами
 * медиа/картинок («Выбрать существующий» рядом с «Загрузить») и TV-полем file.
 *
 * Работает РОВНО с одним источником — выделенным источником mpc (настройка
 * `mpc_media_source`, тот же, что у грабера/ImageUploadHandler; fallback —
 * `default_media_source`). Источник из запроса НЕ принимается: редактор
 * ограничен медиа-папкой mpc, а не всей файловой системой сайта.
 *
 * Доступ уже проверен на уровне коннектора (право mpcve_edit). Поэтому файловые
 * права самого источника принудительно включаются (иначе getContainerList
 * фильтрует выдачу по mgr-правам, которых у пользователя нет в web-контексте),
 * по той же модели доверия, что и ImageUploadHandler.
 *
 * Пути-токены — ОТНОСИТЕЛЬНЫЕ базе источника (поле `id` ноды getContainerList).
 * Большинство методов источника принимают их через getBases() как есть; ИСКЛЮЧЕНИЕ
 * — removeContainer(), который зовёт fileHandler->make($path) напрямую (нужен
 * АБСОЛЮТНЫЙ путь) → для удаления папки склеиваем getBasePath() + относительный путь.
 */
class FileManagerHandler
{
    /** Белые списки расширений по «accept» (синхронны ImageUploadHandler). */
    private const IMAGE_EXT = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'avif'];
    private const VIDEO_EXT = ['mp4', 'webm', 'ogv', 'ogg', 'mov', 'm4v'];
    private const AUDIO_EXT = ['mp3', 'ogg', 'oga', 'wav', 'm4a', 'aac', 'weba'];

    /** Файловые права источника, которые включаем после доверенной проверки. */
    private const GRANT_PERMS = [
        'directory_list', 'directory_create', 'directory_remove', 'directory_update',
        'file_list', 'file_remove', 'file_update', 'file_upload', 'file_create', 'file_view',
    ];

    /**
     * Исполняемые/опасные расширения — блок при upload и rename НЕЗАВИСИМО от
     * accept (иначе accept=any пропускал .php → webshell, если источник в webroot).
     */
    private const BLOCKED_EXT = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'pht', 'phps', 'phar',
        'shtml', 'cgi', 'pl', 'py', 'sh', 'bash', 'htaccess', 'htpasswd', 'user.ini',
        'asp', 'aspx', 'jsp', 'jspx', 'exe', 'com', 'bat', 'cmd', 'msi', 'dll', 'so',
    ];

    private \modX $modx;
    private Mpcve $mpcve;

    public function __construct(\modX $modx, Mpcve $mpcve)
    {
        $this->modx  = $modx;
        $this->mpcve = $mpcve;
    }

    /** Листинг папки источника: подпапки + файлы (с фильтром по accept). */
    public function list(array $request): array
    {
        $source = $this->getSource();
        if (!$source) {
            return $this->err($this->modx->lexicon('mpcve_err_source'));
        }
        $path   = $this->cleanPath((string)($request['path'] ?? ''));
        $accept = (string)($request['accept'] ?? 'any');

        $ls = $source->getContainerList($path);
        $dirs = [];
        $files = [];
        foreach ((array)$ls as $node) {
            $type = (string)($node['type'] ?? '');
            if ($type === 'dir') {
                $dirs[] = ['name' => (string)$node['text'], 'path' => (string)$node['id']];
            } elseif ($type === 'file') {
                $ext = strtolower((string)pathinfo((string)$node['text'], PATHINFO_EXTENSION));
                if (!$this->acceptExt($ext, $accept)) {
                    continue;
                }
                // URL — от КОРНЯ сайта (getObjectUrl: getBaseUrl=urlAbsolute + путь),
                // ровно как пишет грабер. Поле getContainerList['url'] строится от
                // «сырого» baseUrl (без ведущего слэша) → как src на вложенной
                // странице резолвится относительно её URL и ломается. В поле всегда
                // кладём root-anchored путь.
                $files[] = [
                    'name'  => (string)$node['text'],
                    'path'  => (string)$node['id'],
                    'url'   => (string)$source->getObjectUrl((string)$node['id']),
                    'ext'   => $ext,
                    'image' => in_array($ext, self::IMAGE_EXT, true),
                ];
            }
        }

        return [
            'success' => true,
            'message' => '',
            'data'    => [
                'path'     => $path,
                'dirs'     => $dirs,
                'files'    => $files,
                'sourceId' => (int)$source->get('id'),
            ],
        ];
    }

    /** Создать папку в текущем каталоге. */
    public function mkdir(array $request): array
    {
        $source = $this->getSource();
        if (!$source) {
            return $this->err($this->modx->lexicon('mpcve_err_source'));
        }
        $parent = $this->cleanPath((string)($request['path'] ?? ''));
        $name   = $this->sanitizeFileName(trim((string)($request['name'] ?? '')));
        if ($name === '') {
            return $this->err($this->modx->lexicon('mpcve_fm_err_name'));
        }
        $ok = $source->createContainer($name, $parent === '' ? '/' : $parent);
        return $ok
            ? $this->ok($this->modx->lexicon('mpcve_fm_created'))
            : $this->err($this->sourceErr($source));
    }

    /** Переименовать файл или папку (kind=dir|file). */
    public function rename(array $request): array
    {
        $source = $this->getSource();
        if (!$source) {
            return $this->err($this->modx->lexicon('mpcve_err_source'));
        }
        $path = $this->cleanPath((string)($request['path'] ?? ''));
        $name = $this->sanitizeFileName(trim((string)($request['name'] ?? '')));
        $kind = (string)($request['kind'] ?? 'file');
        if ($path === '' || $name === '') {
            return $this->err($this->modx->lexicon('mpcve_fm_err_name'));
        }
        // Файл нельзя переименовать в исполняемое расширение (image.jpg → shell.php).
        if ($kind !== 'dir' && $this->isBlockedExt($this->extOf($name))) {
            return $this->err($this->modx->lexicon('mpcve_err_upload_ext'));
        }
        $ok = $kind === 'dir'
            ? $source->renameContainer($path, $name)
            : $source->renameObject($path, $name);
        return $ok
            ? $this->ok($this->modx->lexicon('mpcve_fm_renamed'))
            : $this->err($this->sourceErr($source));
    }

    /** Удалить файл или папку (kind=dir|file). */
    public function remove(array $request): array
    {
        $source = $this->getSource();
        if (!$source) {
            return $this->err($this->modx->lexicon('mpcve_err_source'));
        }
        $path = $this->cleanPath((string)($request['path'] ?? ''));
        $kind = (string)($request['kind'] ?? 'file');
        if ($path === '') {
            return $this->err($this->modx->lexicon('mpcve_fm_err_name'));
        }
        if ($kind === 'dir') {
            // removeContainer() резолвит путь через make() (нужен абсолютный),
            // в отличие от прочих методов, работающих от getBases().
            $ok = $source->removeContainer(rtrim($source->getBasePath(), '/') . '/' . ltrim($path, '/'));
        } else {
            $ok = $source->removeObject($path);
        }
        return $ok
            ? $this->ok($this->modx->lexicon('mpcve_fm_removed'))
            : $this->err($this->sourceErr($source));
    }

    /** Загрузить файл в текущую папку. accept ограничивает типы. */
    public function upload(array $request): array
    {
        $source = $this->getSource();
        if (!$source) {
            return $this->err($this->modx->lexicon('mpcve_err_source'));
        }
        $file = $_FILES['file'] ?? null;
        if (!is_array($file) || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return $this->err($this->modx->lexicon('mpcve_err_upload'));
        }
        if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            return $this->err($this->modx->lexicon('mpcve_err_upload'));
        }

        $maxBytes = (int)$this->modx->getOption('mpcve_max_upload', null, 10 * 1024 * 1024);
        if ($maxBytes > 0 && (int)($file['size'] ?? 0) > $maxBytes) {
            return $this->err($this->modx->lexicon('mpcve_err_upload_size'));
        }

        $accept = (string)($request['accept'] ?? 'any');
        $ext    = strtolower((string)pathinfo((string)$file['name'], PATHINFO_EXTENSION));
        // accept=any пропускал любое расширение → блок-лист исполняемых ОБЯЗАТЕЛЕН
        // независимо от accept; MIME содержимого тоже не должен быть скриптом.
        if (!$this->acceptExt($ext, $accept) || $this->isBlockedExt($ext)
            || !$this->mimeNotExecutable((string)$file['tmp_name'])) {
            return $this->err($this->modx->lexicon('mpcve_err_upload_ext'));
        }

        $name = $this->sanitizeFileName((string)$file['name']);
        $dir = $this->cleanPath((string)($request['path'] ?? ''));
        // createObject ждёт каталог с завершающим слэшем (getBases + dir + name).
        $dir = $dir === '' ? '' : rtrim($dir, '/') . '/';
        $res = $source->createObject($dir, $name, (string)file_get_contents($file['tmp_name']));
        if ($res === false) {
            return $this->err($this->sourceErr($source) ?: $this->modx->lexicon('mpcve_err_upload'));
        }

        $url = $source->getObjectUrl($dir . $name);
        return [
            'success' => true,
            'message' => $this->modx->lexicon('mpcve_uploaded'),
            'data'    => ['url' => $url, 'path' => $dir . $name],
        ];
    }

    /**
     * Выделенный источник mpc (mpc_media_source → default_media_source) с
     * принудительно включёнными файловыми правами и контекстом (нужен
     * getContainerList для построения ссылок/превью). Источник один и тот же
     * для грабера, ImageUploadHandler и файлового менеджера.
     */
    private function getSource(): ?\modMediaSource
    {
        $id = (int)$this->modx->getOption('mpc_media_source', null, 0);
        if (!$id) {
            $id = (int)$this->modx->getOption('default_media_source', null, 1);
        }
        $this->modx->loadClass('sources.modMediaSource');
        /** @var \modMediaSource|null $source */
        $source = \modMediaSource::getDefaultSource($this->modx, $id);
        if (!$source) {
            return null;
        }
        // hideTooltips — не строить phpthumb-qtip (нам превью даёт сам url файла).
        $source->setRequestProperties(['hideTooltips' => true]);
        $source->initialize();
        $source->ctx = $this->modx->context;
        foreach (self::GRANT_PERMS as $p) {
            $source->permissions[$p] = true;
        }
        return $source;
    }

    private function acceptExt(string $ext, string $accept): bool
    {
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

    /** Защита от обхода каталога вверх + нормализация слэшей. */
    private function cleanPath(string $path): string
    {
        $path = str_replace(['\\', "\0"], ['/', ''], $path);
        // Отсекаем traversal посегментно: '..' и '.' выкидываем целиком (regex
        // \.{2,} не ловил './' и мог оставить точки в середине сегмента).
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

    /** Расширение имени в нижнем регистре. */
    private function extOf(string $name): string
    {
        return strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
    }

    /** Исполняемое/опасное расширение (любой вариант php-тега тоже). */
    private function isBlockedExt(string $ext): bool
    {
        $ext = strtolower($ext);
        return in_array($ext, self::BLOCKED_EXT, true) || strpos($ext, 'php') !== false;
    }

    /**
     * Имя файла/папки: срезаем путь и управляющие, схлопываем '..', убираем
     * ведущую точку (скрытый/.htaccess). Юникод (кириллицу) сохраняем.
     */
    private function sanitizeFileName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('#[/\x00-\x1f]+#', '', $name);
        $name = preg_replace('#\.{2,}#', '.', (string)$name);
        $name = ltrim((string)$name, '.');
        return (string)$name;
    }

    /** Содержимое не является исполняемым скриптом по реальному MIME (finfo). */
    private function mimeNotExecutable(string $tmp): bool
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
        foreach (['application/x-php', 'text/x-php', 'application/x-httpd-php',
                  'application/x-perl', 'application/x-python', 'text/x-shellscript',
                  'application/x-sh', 'application/x-executable', 'application/x-dosexec'] as $bad) {
            if (strpos($mime, $bad) === 0) {
                return false;
            }
        }
        return true;
    }

    private function sourceErr($source): string
    {
        return method_exists($source, 'getErrors') ? trim(implode('; ', (array)$source->getErrors())) : '';
    }

    private function ok(string $message): array
    {
        return ['success' => true, 'message' => $message, 'data' => []];
    }

    private function err(string $message): array
    {
        return ['success' => false, 'message' => $message ?: 'file manager error', 'data' => []];
    }
}
