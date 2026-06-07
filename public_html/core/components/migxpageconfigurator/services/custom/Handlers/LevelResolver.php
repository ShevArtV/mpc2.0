<?php

namespace MpcServices\Handlers;

/**
 * Резолвинг целевого ресурса по уровню наследования (global → type → resource).
 * Вынесено из FieldWriter (God-класс): чистая навигация по ресурсам без
 * write-логики. global = staticBlocksPage; type = ресурс-донор (parent=sbp) того
 * же шаблона; resource = сам ресурс. Старые имена уровней (local/template) —
 * алиасы новых (resource/type).
 *
 * @author Arthur Shevchenko (https://t.me/ShevArtV)
 */
class LevelResolver
{
    private \modX $modx;
    private int $staticBlocksPageId;

    public function __construct(\modX $modx, int $staticBlocksPageId)
    {
        $this->modx = $modx;
        $this->staticBlocksPageId = $staticBlocksPageId;
    }

    /** Ресурс заданного уровня для $resourceId; null — не найден. */
    public function resolve(string $level, int $resourceId)
    {
        // Алиасы старых имён уровней → новая терминология (global не переименовываем).
        $aliases = ['local' => 'resource', 'template' => 'type'];
        $level = $aliases[$level] ?? $level;

        switch ($level) {
            case 'global':
                return $this->modx->getObject('modResource', $this->staticBlocksPageId);
            case 'type':
                $resource = $this->modx->getObject('modResource', $resourceId);
                if (!$resource) {
                    return null;
                }
                $tpl = (int)$resource->get('template');
                return $this->modx->getObject('modResource', [
                    'parent'   => $this->staticBlocksPageId,
                    'template' => $tpl,
                ]);
            case 'resource':
            default:
                return $resourceId > 0 ? $this->modx->getObject('modResource', $resourceId) : null;
        }
    }
}
