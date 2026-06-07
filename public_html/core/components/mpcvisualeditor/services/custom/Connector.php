<?php
/**
 * Роутер запросов фронт-коннектора mpcVisualEditor.
 * action → метод-обработчик. Сами экшены (field/save, row/save, image/upload)
 * реализуются в M7 (Handlers/*). На этапе скаффолда (M6) — каркас + проверка прав.
 */

namespace MpcVEServices;

use MpcVEServices\Handlers\PermissionChecker;

/**
 * @author Arthur Shevchenko (https://t.me/ShevArtV)
 */
class Connector
{
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
        switch ($action) {
            case 'fields/types':
                // Карта field→тип редактора из mpc_base (вариант B).
                return (new Handlers\FieldTypesHandler($this->modx, $this->mpcve))->handle($request);
            case 'fields/options':
                // Опции listbox/option/checkbox-поля: TV (по имени) или сырой
                // elements (@SELECT секционного listbox) → [{label,value}].
                return (new Handlers\FieldOptionsHandler($this->modx, $this->mpcve))->handle($request);
            case 'config/get':
                // Декодированный mpc_config (resource+global) для панели скрытых
                // полей: те, что вырезаны data-mpc-remove или вспомогательные.
                return (new Handlers\ConfigGetHandler($this->modx, $this->mpcve))->handle($request);
            case 'page/save':
                // ВРЕМЕННО ОТКЛЮЧЕНО: ре-граб всего отрендеренного DOM деструктивен
                // (рендер = склейка тип+ресурс + caттер-трансформы lazyload/fake-img),
                // он перезаписывал конфиг ресурса мусором. Переходим на пер-полевое
                // сохранение. До его готовности — экшен ничего не пишет.
                return $this->error('Сохранение временно отключено (переход на пер-полевое).');
            case 'field/save':
                // Легаси точечной записи (rfield/tv/config) — оставлено для совместимости.
                return (new Handlers\FieldSaveHandler($this->modx, $this->mpcve))->handle($request);
            case 'row/op':
                // Структурная операция над строками списка (add|delete|move).
                return (new Handlers\RowOpHandler($this->modx, $this->mpcve))->handle($request);
            case 'section/op':
                // Структурная операция над секциями (move|visibility|static) —
                // RAW-запись массива конфига, не через лексикон-aware field/save.
                return (new Handlers\SectionOpHandler($this->modx, $this->mpcve))->handle($request);
            case 'cache/clear':
                // Полная очистка кэша MODX (кнопка тулбара).
                return (new Handlers\CacheClearHandler($this->modx, $this->mpcve))->handle($request);
            case 'lock/acquire':
                // Взять/продлить блокировку ресурса на время редактирования.
                return (new Handlers\LockHandler($this->modx, $this->mpcve))->acquire($request);
            case 'lock/release':
                // Снять блокировку (явный выход / закрытие вкладки — beacon).
                return (new Handlers\LockHandler($this->modx, $this->mpcve))->release($request);
            case 'log/list':
                // Журнал изменений ресурса (кто/когда/что).
                return (new Handlers\LogHandler($this->modx, $this->mpcve))->list($request);
            case 'log/revert':
                // Откат правки поля (записать старое значение обратно).
                return (new Handlers\LogHandler($this->modx, $this->mpcve))->revert($request);
            case 'image/upload':
                // Загрузка картинки в media source mpc → возврат URL (фронт пишет
                // его в поле через field/save). См. ImageUploadHandler.
                return (new Handlers\ImageUploadHandler($this->modx, $this->mpcve))->handle($request);
            case 'files/list':
                // Файловый менеджер: листинг папки источника (навигация + выбор).
                return (new Handlers\FileManagerHandler($this->modx, $this->mpcve))->list($request);
            case 'files/mkdir':
                // Файловый менеджер: создать папку.
                return (new Handlers\FileManagerHandler($this->modx, $this->mpcve))->mkdir($request);
            case 'files/rename':
                // Файловый менеджер: переименовать файл/папку.
                return (new Handlers\FileManagerHandler($this->modx, $this->mpcve))->rename($request);
            case 'files/remove':
                // Файловый менеджер: удалить файл/папку.
                return (new Handlers\FileManagerHandler($this->modx, $this->mpcve))->remove($request);
            case 'files/upload':
                // Файловый менеджер: загрузить файл в текущую папку.
                return (new Handlers\FileManagerHandler($this->modx, $this->mpcve))->upload($request);
            default:
                // S10: не отражаем ввод (action) в ответе — фронт мог бы вставить
                // его в innerHTML. Конкретный action логируем, пользователю — общее.
                $this->modx->log(\modX::LOG_LEVEL_WARN, '[mpcVE] unknown action: ' . $action);
                return $this->error('Unknown or not-yet-implemented action');
        }
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
