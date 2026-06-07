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
        return ['success' => true, 'message' => 'Кэш очищен', 'data' => []];
    }
}
