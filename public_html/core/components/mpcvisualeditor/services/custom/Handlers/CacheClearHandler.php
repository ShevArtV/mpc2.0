<?php

namespace MpcVEServices\Handlers;

use MpcVEServices\Mpcve;

/**
 * Экшен cache/clear: полная очистка кэша MODX (как кнопка «Очистить кэш» в
 * админке). Безопасно: права уже проверены в Connector (mpcve_edit).
 */
class CacheClearHandler extends BaseHandler
{


    public function handle(array $request): array
    {
        $cm = method_exists($this->modx, 'getCacheManager')
            ? $this->modx->getCacheManager()
            : ($this->modx->cacheManager ?? null);
        if (!$cm || !method_exists($cm, 'refresh')) {
            return ['success' => false, 'message' => 'cache manager unavailable', 'data' => []];
        }
        $cm->refresh(); // полный сброс всех партиций (как system/clearcache)

        // Дополнительно физически чистим партицию system_settings: refresh() её
        // пересобирает, но собранный config/значения ClientConfig могут держаться
        // из файлов кэша до их удаления (настройка не подхватывается без ручного
        // сброса). Папку оставляем — MODX пересоздаёт файлы при следующем запросе.
        $dir = rtrim((string)$this->modx->getOption('core_path'), '/\\') . '/cache/system_settings/';
        $this->purgeDir($dir);

        return ['success' => true, 'message' => 'Кэш очищен', 'data' => []];
    }

    /** Удалить ВСЁ содержимое каталога (файлы и подпапки), сам каталог оставить. */
    private function purgeDir(string $dir): void
    {
        if ($dir === '' || !is_dir($dir)) {
            return;
        }
        try {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $f) {
                $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
            }
        } catch (\Throwable $e) {
            $this->modx->log(\modX::LOG_LEVEL_WARN, '[mpcVE cache] purgeDir: ' . $e->getMessage());
        }
    }
}
