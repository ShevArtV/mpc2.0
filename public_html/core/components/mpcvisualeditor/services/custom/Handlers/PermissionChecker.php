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
     * hasPermission() проверяет политику ТЕКУЩЕГО контекста, а панель работает
     * на web. Проверено на dev 04.09.2026: право отрабатывает на фронте, когда
     * политика с ним назначена группе пользователя на веб-контекстах (на
     * sleepandglow это «Content Managers», выданная группе во всех контекстах,
     * включая страновые). Явная загрузка mgr-permissions не нужна.
     * Следствие для установки: одной выдачи права политике «Administrator»
     * (её делает резолвер пакета) хватает только тем, кому эта политика
     * назначена на web; остальным право выдаёт политика их группы.
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
