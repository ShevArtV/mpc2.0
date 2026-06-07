<?php

namespace MpcServices\Handlers;

/**
 * Инвалидация кэша после правки поля: resource-кэш контекста ресурса (MODX
 * cacheManager) + parsed/-файлы pdoTools (запечённые значения нестатичных секций).
 * Вынесено из FieldWriter (God-класс, одна из 6 ответственностей) — отдельный
 * сервис на $modx, тестируется изолированно (parsed-логика на tmp-папке).
 *
 * @author Arthur Shevchenko (https://t.me/ShevArtV)
 */
class CacheInvalidator
{
    private \modX $modx;

    public function __construct(\modX $modx)
    {
        $this->modx = $modx;
    }

    /** Полная инвалидация после записи значения на заданном уровне. */
    public function invalidate(object $resource, string $level): void
    {
        $this->refreshResourceContext($resource);
        $this->invalidateParsed($resource, $level);
    }

    /** Обновляет resource-кэш контекста ресурса (как точечная запись). */
    private function refreshResourceContext(object $resource): void
    {
        $cm = null;
        if (method_exists($this->modx, 'getCacheManager')) {
            $cm = $this->modx->getCacheManager();
        } elseif (isset($this->modx->cacheManager)) {
            $cm = $this->modx->cacheManager;
        }
        if ($cm && method_exists($cm, 'refresh')) {
            $context = (string)($resource->get('context_key') ?: 'web');
            $cm->refresh(['resource' => ['contexts' => [$context]]]);
        }
    }

    /**
     * Удаляет parsed-файл(ы) после правки. Обычно — файл самого ресурса. Но правка
     * на уровне global (статичные блоки) ИЛИ правка ресурса-ТИПА (донор, parent =
     * staticBlocksPage) влияет на ВСЕ наследующие страницы → сносим весь parsed
     * (каждый регенерится лениво при заходе).
     */
    private function invalidateParsed(object $resource, string $level): void
    {
        $elements = (string)$this->modx->getOption('pdotools_elements_path', null, MODX_CORE_PATH . 'elements/');
        $elements = str_replace('{core_path}', MODX_CORE_PATH, $elements);
        $dist = $elements . (string)$this->modx->getOption('mpc_path_to_dist', null, 'parsed/');
        if (!is_dir($dist)) {
            return;
        }
        $ext = (string)$this->modx->getOption('mpc_tpl_file_extension', null, '.tpl');
        $sbp = (int)$this->modx->getOption('mpc_static_block_page_id', null, 1);
        $isDonor = (int)$resource->get('parent') === $sbp;

        if ($level === 'global' || $isDonor) {
            foreach ((array)@scandir($dist) as $f) {
                if ($f !== '.' && $f !== '..' && is_file($dist . $f)) {
                    @unlink($dist . $f);
                }
            }
            return;
        }
        $file = $dist . (int)$resource->get('id') . $ext;
        if (is_file($file)) {
            @unlink($file);
        }
    }
}
