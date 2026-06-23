<?php
/**
 * Роутер запросов фронт-коннектора mpcVisualEditor.
 * action → метод-обработчик. Сами экшены (field/save, row/save, files/upload)
 * реализуются в M7 (Handlers/*). На этапе скаффолда (M6) — каркас + проверка прав.
 */

namespace MpcVEServices;

use MpcVEServices\Handlers\PermissionChecker;

/**
 * @author Arthur Shevchenko (https://t.me/ShevArtV)
 */
class Connector
{
    /**
     * Карта роутинга action → [класс-хендлер, метод]. page/save обрабатывается
     * отдельно (отключён). Хендлеры инстанцируются с (\modX, Mpcve).
     */
    private const ROUTES = [
        'fields/types'   => [Handlers\FieldTypesHandler::class,   'handle'],
        'fields/options' => [Handlers\FieldOptionsHandler::class, 'handle'],
        'config/get'     => [Handlers\ConfigGetHandler::class,    'handle'],
        'field/save'     => [Handlers\FieldSaveHandler::class,    'handle'],
        'info/save'      => [Handlers\InfoSaveHandler::class,     'handle'],
        'settings/list'  => [Handlers\SettingsListHandler::class, 'handle'],
        'contact/save'   => [Handlers\ContactSaveHandler::class,  'handle'],
        'row/op'         => [Handlers\RowOpHandler::class,        'handle'],
        'section/op'     => [Handlers\SectionOpHandler::class,    'handle'],
        'cache/clear'    => [Handlers\CacheClearHandler::class,   'handle'],
        'lock/acquire'   => [Handlers\LockHandler::class,         'acquire'],
        'lock/release'   => [Handlers\LockHandler::class,         'release'],
        'log/list'       => [Handlers\LogHandler::class,          'list'],
        'log/revert'     => [Handlers\LogHandler::class,          'revert'],
        'files/list'     => [Handlers\FileManagerHandler::class,  'list'],
        'files/mkdir'    => [Handlers\FileManagerHandler::class,  'mkdir'],
        'files/rename'   => [Handlers\FileManagerHandler::class,  'rename'],
        'files/remove'   => [Handlers\FileManagerHandler::class,  'remove'],
        'files/upload'   => [Handlers\FileManagerHandler::class,  'upload'],
        'files/download-url' => [Handlers\FileManagerHandler::class, 'downloadUrl'],
        'files/locate'   => [Handlers\FileManagerHandler::class,  'locate'],
    ];

    private \modX $modx;
    private Mpcve $mpcve;

    public function __construct(\modX $modx)
    {
        $this->modx = $modx;
        $this->mpcve = new Mpcve($modx);
    }

    /**
     * @param array $request объединённые $_GET + $_POST
     */
    public function handle(array $request): array
    {
        $checker = new PermissionChecker($this->modx, (string)$this->mpcve->getConfig('permission'));
        if (!$checker->userCanEdit()) {
            return $this->error($this->modx->lexicon('mpcve_err_permission'));
        }

        // CSRF: только cookie-сессии недостаточно — сверяем nonce из тела запроса
        // с сессионным (фронт берёт его из window.mpcVEConfig). Внешний сайт
        // токена не знает → fetch с credentials:'include' отбивается.
        if (!$this->nonceValid($request)) {
            return $this->error($this->modx->lexicon('mpcve_err_permission'));
        }

        $action = (string)($request['action'] ?? '');

        // page/save ВРЕМЕННО ОТКЛЮЧЕН: ре-граб всего отрендеренного DOM деструктивен
        // (перезаписывал конфиг ресурса мусором) — переход на пер-полевое сохранение.
        if ($action === 'page/save') {
            return $this->error('Сохранение временно отключено (переход на пер-полевое).');
        }

        $route = self::ROUTES[$action] ?? null;
        if ($route === null) {
            // S10: не отражаем ввод (action) в ответе — фронт мог бы вставить его в
            // innerHTML. Конкретный action логируем, пользователю — общее.
            $this->mpcve->logger->warning('unknown action: ' . $action, ['action' => $action], 'connector');
            return $this->error('Unknown or not-yet-implemented action');
        }
        [$class, $method] = $route;
        return (new $class($this->modx, $this->mpcve))->$method($request);
    }

    /** Сверка CSRF-nonce запроса с сессионным (constant-time). */
    private function nonceValid(array $request): bool
    {
        $sent   = (string)($request['nonce'] ?? '');
        $stored = isset($_SESSION['mpcve_nonce']) ? (string)$_SESSION['mpcve_nonce'] : '';
        return $sent !== '' && $stored !== '' && hash_equals($stored, $sent);
    }

    private function error(string $message): array
    {
        return ['success' => false, 'message' => $message, 'data' => []];
    }
}
