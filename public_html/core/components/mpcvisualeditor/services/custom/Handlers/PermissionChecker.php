<?php
/**
 * Проверка прав на редактирование с фронта.
 */

namespace MpcVEServices\Handlers;

/**
 * @author Arthur Shevchenko (https://t.me/ShevArtV)
 */
class PermissionChecker
{
    private \modX $modx;
    private string $permission;

    public function __construct(\modX $modx, string $permission = 'mpcve_edit')
    {
        $this->modx = $modx;
        $this->permission = $permission;
    }

    /**
     * Может ли текущий пользователь редактировать.
     * Требует: аутентификация как mgr-пользователь + наличие permission.
     *
     * ВАЖНО (проверить на сайте): hasPermission() проверяет политику текущего
     * контекста. На web-контексте право из Administrator-политики доступно
     * только если пользователь sudo или его роль несёт это право в web-контексте.
     * Возможно потребуется грузить mgr-permissions явно. Помечено для M7.
     */
    public function userCanEdit(): bool
    {
        $user = $this->modx->user;
        if (!$user || !$user->isAuthenticated('mgr')) {
            return false;
        }
        if ($user->get('sudo')) {
            return true;
        }
        return (bool)$this->modx->hasPermission($this->permission);
    }

    /**
     * Право на правку ГЛОБАЛЬНЫХ данных из редактора: системные/контекстные/
     * ClientConfig-настройки (data-mpc-info) и контакты — они меняются на всём
     * сайте, поэтому отдельное право `mpcve_edit_global` (sudo — bypass).
     */
    public function canEditGlobal(): bool
    {
        $user = $this->modx->user;
        if (!$user || !$user->isAuthenticated('mgr')) {
            return false;
        }
        if ($user->get('sudo')) {
            return true;
        }
        return (bool)$this->modx->hasPermission('mpcve_edit_global');
    }

    /**
     * Право на запись КОНКРЕТНОГО ресурса (anti-IDOR): глобального mpcve_edit
     * мало — проверяем resource-level политику save. sudo — bypass.
     */
    public function canEditResource(int $rid): bool
    {
        return $this->canPolicy($rid, 'save');
    }

    /**
     * Право на просмотр КОНКРЕТНОГО ресурса (для log/list — не отдаём значения
     * чужого ресурса). sudo — bypass.
     */
    public function canViewResource(int $rid): bool
    {
        return $this->canPolicy($rid, 'view');
    }

    private function canPolicy(int $rid, string $policy): bool
    {
        $user = $this->modx->user;
        if (!$user || !$user->isAuthenticated('mgr')) {
            return false;
        }
        if ($user->get('sudo')) {
            return true;
        }
        if ($rid <= 0) {
            return false;
        }
        $resource = $this->modx->getObject('modResource', $rid);
        if (!$resource) {
            return false;
        }
        // view фоллбэчит на load: на ряде политик именно load несёт «видимость».
        return (bool)$resource->checkPolicy($policy)
            || ($policy === 'view' && (bool)$resource->checkPolicy('load'));
    }
}
