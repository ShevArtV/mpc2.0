<?php

namespace MpcVEServices\Handlers;

use MpcVEServices\Mpcve;

/**
 * Экшен image/upload: приём загруженного файла-изображения, запись в MODX
 * media source (тот же, что использует грабер mpc: `mpc_media_source` →
 * `default_media_source`) и возврат публичного URL. Сам URL фронт затем пишет
 * в поле через field/save — здесь только загрузка.
 *
 * Папка: `mpc_media_path` + `images/` (консистентно с грабером). Имя файла —
 * санитайз исходного + короткий sha1 контента (дедуп одинаковых, без коллизий).
 */
class ImageUploadHandler
{
    /** Белый список расширений изображений. */
    private const ALLOWED = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'avif'];

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

        $maxBytes = (int)$this->modx->getOption('mpcve_max_upload', null, 10 * 1024 * 1024);
        if ($maxBytes > 0 && (int)($file['size'] ?? 0) > $maxBytes) {
            return $this->err($this->modx->lexicon('mpcve_err_upload_size'));
        }

        $ext = strtolower((string)pathinfo((string)$file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, self::ALLOWED, true) || !$this->isImage((string)$file['tmp_name'], $ext)) {
            return $this->err($this->modx->lexicon('mpcve_err_upload_ext'));
        }

        $content = file_get_contents($file['tmp_name']);
        if ($content === false || $content === '') {
            return $this->err($this->modx->lexicon('mpcve_err_upload'));
        }

        $source = $this->getMediaSource();
        if (!$source) {
            return $this->err($this->modx->lexicon('mpcve_err_source'));
        }

        return $this->processUpload($source, $this->uploadDir(), (string)$file['name'], $ext, $content);
    }

    /**
     * Чистое ядро записи: строит имя, гарантирует папку, пишет объект, возвращает
     * результат с URL. Без $modx — тестируется с fake-source.
     *
     * @param object $source modMediaSource (или совместимый)
     */
    public function processUpload($source, string $dir, string $originalName, string $ext, string $content): array
    {
        $base = $this->sanitizeFileName((string)pathinfo($originalName, PATHINFO_FILENAME)) ?: 'image';
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

    private function uploadDir(): string
    {
        $prefix = trim((string)$this->modx->getOption(
            'mpc_media_path',
            null,
            'assets/components/migxpageconfigurator/media/'
        ), '/');
        return ($prefix !== '' ? $prefix . '/' : '') . 'images/';
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
