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
}
