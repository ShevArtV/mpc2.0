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

        $action = (string)($request['action'] ?? '');
        switch ($action) {
            case 'field/save':
                return (new Handlers\FieldSaveHandler($this->modx, $this->mpcve))->handle($request);
            // TODO M7 (далее):
            // case 'row/save':    return (new Handlers\RowSaveHandler($this->modx, $this->mpcve))->handle($request);
            // case 'image/upload':return (new Handlers\ImageUploadHandler($this->modx, $this->mpcve))->handle($request);
            default:
                return $this->error('Unknown or not-yet-implemented action: ' . $action);
        }
    }

    private function error(string $message): array
    {
        return ['success' => false, 'message' => $message, 'data' => []];
    }

    private function success(string $message = '', array $data = []): array
    {
        return ['success' => true, 'message' => $message, 'data' => $data];
    }
}
