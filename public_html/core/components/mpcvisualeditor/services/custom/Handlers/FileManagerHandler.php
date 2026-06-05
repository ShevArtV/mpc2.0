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
                $files[] = [
                    'name'  => (string)$node['text'],
                    'path'  => (string)$node['id'],
                    'url'   => (string)($node['url'] ?? ''),
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
        $name   = trim((string)($request['name'] ?? ''));
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
        $name = trim((string)($request['name'] ?? ''));
        $kind = (string)($request['kind'] ?? 'file');
        if ($path === '' || $name === '') {
            return $this->err($this->modx->lexicon('mpcve_fm_err_name'));
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
        if (!$this->acceptExt($ext, $accept)) {
            return $this->err($this->modx->lexicon('mpcve_err_upload_ext'));
        }

        $dir = $this->cleanPath((string)($request['path'] ?? ''));
        // createObject ждёт каталог с завершающим слэшем (getBases + dir + name).
        $dir = $dir === '' ? '' : rtrim($dir, '/') . '/';
        $res = $source->createObject($dir, (string)$file['name'], (string)file_get_contents($file['tmp_name']));
        if ($res === false) {
            return $this->err($this->sourceErr($source) ?: $this->modx->lexicon('mpcve_err_upload'));
        }

        $url = $source->getObjectUrl($dir . $file['name']);
        return [
            'success' => true,
            'message' => $this->modx->lexicon('mpcve_uploaded'),
            'data'    => ['url' => $url, 'path' => $dir . $file['name']],
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
        $path = str_replace('\\', '/', $path);
        $path = preg_replace('#\.{2,}#', '', $path); // выкидываем .. сегменты
        $path = preg_replace('#/+#', '/', (string)$path);
        return ltrim((string)$path, '/');
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
