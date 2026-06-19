<?php

namespace MpcVEServices\Handlers;

use MpcVEServices\Mpcve;
use MpcVEServices\Handlers\Support\MediaLibrary;

/**
 * Мини-файловый менеджер редактора поверх modMediaSource: навигация по папкам,
 * листинг, CRUD папок/файлов и загрузка в текущую папку. Используется редакторами
 * медиа/картинок («Выбрать существующий» рядом с «Загрузить») и TV-полем file.
 *
 * Работает РОВНО с одним источником — выделенным источником mpc (настройка
 * `mpc_media_source`, тот же, что у грабера; fallback — `default_media_source`).
 * Источник из запроса НЕ принимается: редактор ограничен медиа-папкой mpc, а не
 * всей файловой системой сайта.
 *
 * Доступ уже проверен на уровне коннектора (право mpcve_edit). Поэтому файловые
 * права самого источника принудительно включаются (иначе getContainerList
 * фильтрует выдачу по mgr-правам, которых у пользователя нет в web-контексте).
 *
 * upload() обслуживает И файловый менеджер (явный `path`), И загрузку из
 * редакторов (image/media/picture — без `path` → каноническая папка по accept).
 *
 * Пути-токены — ОТНОСИТЕЛЬНЫЕ базе источника (поле `id` ноды getContainerList).
 * Большинство методов источника принимают их через getBases() как есть; ИСКЛЮЧЕНИЕ
 * — removeContainer(), который зовёт fileHandler->make($path) напрямую (нужен
 * АБСОЛЮТНЫЙ путь) → для удаления папки склеиваем getBasePath() + относительный путь.
 */
class FileManagerHandler
{
    /** Файловые права источника, которые включаем после доверенной проверки. */
    private const GRANT_PERMS = [
        'directory_list', 'directory_create', 'directory_remove', 'directory_update',
        'file_list', 'file_remove', 'file_update', 'file_upload', 'file_create', 'file_view',
    ];

    private \modX $modx;
    private Mpcve $mpcve;
    private MediaLibrary $lib;

    public function __construct(\modX $modx, Mpcve $mpcve)
    {
        $this->modx  = $modx;
        $this->mpcve = $mpcve;
        $this->lib   = new MediaLibrary($modx);
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
                    'image' => in_array($ext, MediaLibrary::IMAGE_EXT, true),
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
        if ($kind !== 'dir' && $this->isBlockedName($name)) {
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

    /**
     * Загрузить файл. Целевая папка — `path` из запроса (drag-drop шлёт папку
     * текущего файла поля; менеджер — открытую папку). accept ограничивает типы.
     * Единое ядро MediaLibrary: блок-лист + mime, гибридная защита «тип ↔ папка»,
     * SVG-санитайз, дедуп+авто-суффикс (без молчаливой перезаписи).
     */
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
        $tmp    = (string)$file['tmp_name'];
        $ext    = MediaLibrary::extOf((string)$file['name']);
        // accept=any пропускал любое расширение → блок-лист исполняемых ОБЯЗАТЕЛЕН
        // независимо от accept; MIME содержимого тоже не должен быть скриптом.
        if (!$this->acceptExt($ext, $accept) || $this->isBlockedName((string)$file['name'])
            || !MediaLibrary::mimeNotExecutable($tmp)) {
            return $this->err($this->modx->lexicon('mpcve_err_upload_ext'));
        }
        // Содержимое действительно того типа, что заявлено расширением (поддельный
        // .jpg, не являющийся картинкой, отклоняем — то же делал ImageUploadHandler).
        $typeKey = MediaLibrary::typeKeyOfExt($ext);
        if ($typeKey === 'images' && !MediaLibrary::isImageContent($tmp, $ext)) {
            return $this->err($this->modx->lexicon('mpcve_err_upload_ext'));
        }
        if (($typeKey === 'videos' || $typeKey === 'audios')
            && !MediaLibrary::isMediaContent($tmp, $typeKey === 'videos' ? 'video' : 'audio')) {
            return $this->err($this->modx->lexicon('mpcve_err_upload_ext'));
        }

        // Папка: менеджер шлёт явный `path` (даже '' = корень); редактор папку не
        // указывает → каноническая папка типа из mpc_download_paths (по accept), как
        // раньше делал ImageUploadHandler по kind. Различаем по наличию ключа.
        if (array_key_exists('path', $request)) {
            $cleanDir = $this->cleanPath((string)$request['path']);
        } else {
            $cleanDir = $this->cleanPath($this->lib->canonicalDir(MediaLibrary::typeKeyOfAccept($accept)));
        }
        // Гибридная защита: в каноническую папку типа (images/videos/audios из
        // mpc_download_paths) нельзя класть файл другого типа.
        if (!$this->lib->typeFitsDir($ext, $cleanDir)) {
            return $this->err($this->modx->lexicon('mpcve_fm_err_type_dir'));
        }

        // SVG исполняется браузером → stored XSS. Нативный путь перемещает СЫРОЙ
        // tmp-файл (move_uploaded_file), поэтому очищенный контент пишем обратно в
        // tmp ДО перемещения; is_uploaded_file после перезаписи остаётся истинным.
        if ($ext === 'svg') {
            file_put_contents($tmp, MediaLibrary::sanitizeSvg((string)file_get_contents($tmp)));
        }

        $dir     = $cleanDir === '' ? '' : rtrim($cleanDir, '/') . '/';
        $base    = MediaLibrary::sanitizeBase((string)pathinfo((string)$file['name'], PATHINFO_FILENAME)) ?: 'file';
        $desired = $base . '.' . $ext;

        // Имя задаёт mpc; финальное имя/URL вернёт пайплайн (контракт sgUploads).
        $files = ['file' => array_merge($file, ['name' => $desired])];
        MediaLibrary::ensureContainer($source, $dir);
        if ($source->uploadObjectsToContainer($dir, $files) === false) {
            return $this->err($this->sourceErr($source) ?: $this->modx->lexicon('mpcve_err_upload'));
        }

        $res = MediaLibrary::resolveUploaded($source, $dir, $desired);
        return [
            'success' => true,
            'message' => $this->modx->lexicon('mpcve_uploaded'),
            'data'    => ['url' => $res['url'], 'path' => $res['path']],
        ];
    }

    /**
     * Папка внутри источника, где лежит файл по его URL (обратное к getObjectUrl).
     * Фронт зовёт перед открытием менеджера/загрузкой, чтобы стартовать в папке
     * текущего значения поля. Не резолвится → '' (корень).
     */
    public function locate(array $request): array
    {
        $source = $this->getSource();
        if (!$source) {
            return $this->err($this->modx->lexicon('mpcve_err_source'));
        }
        $path = $this->cleanPath(MediaLibrary::folderOfUrl($source, (string)($request['url'] ?? '')));
        return [
            'success' => true,
            'message' => '',
            'data'    => ['path' => $path, 'sourceId' => (int)$source->get('id')],
        ];
    }

    /**
     * Выделенный источник mpc (mpc_media_source → default_media_source) с
     * принудительно включёнными файловыми правами и контекстом (нужен
     * getContainerList для построения ссылок/превью). Источник один и тот же
     * для грабера и файлового менеджера.
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
            return in_array($ext, MediaLibrary::IMAGE_EXT, true);
        }
        if ($accept === 'video') {
            return in_array($ext, MediaLibrary::VIDEO_EXT, true);
        }
        if ($accept === 'audio') {
            return in_array($ext, MediaLibrary::AUDIO_EXT, true);
        }
        if ($accept === 'media') {
            return in_array($ext, MediaLibrary::IMAGE_EXT, true)
                || in_array($ext, MediaLibrary::VIDEO_EXT, true)
                || in_array($ext, MediaLibrary::AUDIO_EXT, true);
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

    /** Исполняемое/опасное расширение (любой вариант php-тега тоже). */
    private function isBlockedExt(string $ext): bool
    {
        $ext = strtolower($ext);
        return in_array($ext, MediaLibrary::BLOCKED_EXT, true) || strpos($ext, 'php') !== false;
    }

    /**
     * Блок по ВСЕМУ имени (V2): pathinfo берёт лишь последний сегмент, поэтому
     * shell.php.jpg прошёл бы блок-лист, а Apache с `AddHandler .php` исполнил бы
     * его. Проверяем каждый dot-сегмент + любую `.php` в имени.
     */
    private function isBlockedName(string $name): bool
    {
        $name = strtolower($name);
        if (strpos($name, '.php') !== false) {
            return true;
        }
        foreach (explode('.', $name) as $seg) {
            if ($this->isBlockedExt($seg)) {
                return true;
            }
        }
        return false;
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
