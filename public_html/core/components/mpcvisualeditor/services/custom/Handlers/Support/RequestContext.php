<?php

namespace MpcVEServices\Handlers\Support;

/**
 * Восстанавливает контекст страницы внутри standalone-коннектора mpcVE.
 * Сам connector обязан стартовать в web: там живёт общая manager-сессия.
 */
class RequestContext
{
    private \modX $modx;

    public function __construct(\modX $modx)
    {
        $this->modx = $modx;
    }

    public function switchTo(string $key): bool
    {
        $key = trim($key);
        if ($key === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $key)) {
            return false;
        }
        $context = $this->modx->getObject('modContext', ['key' => $key]);
        if (!$context) {
            return false;
        }
        $current = $this->modx->context ? (string)$this->modx->context->get('key') : '';
        if ($current !== $key) {
            $this->modx->switchContext($key);
        }
        return $this->modx->context && (string)$this->modx->context->get('key') === $key;
    }
}
