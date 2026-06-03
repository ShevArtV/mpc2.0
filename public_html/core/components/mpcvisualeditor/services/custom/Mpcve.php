<?php
/**
 * Тонкий фасад mpcVisualEditor.
 * Держит конфиг и (в M7) делегирует запись значений в фасад mpc.
 */

namespace MpcVEServices;

/**
 * @author Arthur Shevchenko (https://t.me/ShevArtV)
 */
class Mpcve
{
    public \modX $modx;
    protected array $config;

    public function __construct(\modX $modx, array $config = [])
    {
        $this->modx = $modx;

        $assetsUrl = $modx->getOption('assets_url', null, MODX_ASSETS_URL) . 'components/mpcvisualeditor/';
        $corePath  = $modx->getOption('core_path', null, MODX_CORE_PATH) . 'components/mpcvisualeditor/';

        $this->config = array_merge([
            'corePath'     => $corePath,
            'assetsUrl'    => $assetsUrl,
            'connectorUrl' => $assetsUrl . 'connector.php',
            'editParam'    => $modx->getOption('mpcve_edit_param', null, 'mpcedit'),
            'permission'   => $modx->getOption('mpcve_permission', null, 'mpcve_edit'),
            'active'       => (bool)$modx->getOption('mpcve_active', null, true),
        ], $config);
    }

    /**
     * @return mixed весь конфиг или конкретный ключ
     */
    public function getConfig(?string $key = null)
    {
        if ($key === null) {
            return $this->config;
        }
        return $this->config[$key] ?? null;
    }

    /**
     * Конфиг для фронт-бутстрапа (window.mpcVEConfig).
     */
    public function getClientConfig(): array
    {
        $resource = $this->modx->resource ?? null;
        return [
            'connectorUrl' => $this->config['connectorUrl'],
            'assetsUrl'    => $this->config['assetsUrl'],
            'editParam'    => $this->config['editParam'],
            'resourceId'   => $resource ? (int)$resource->get('id') : 0,
        ];
    }

    /**
     * Записать значение поля по адресу. Делегирует в write-API mpc
     * (MpcServices\Handlers\FieldWriter, M4). Доступен через общий vendor mpc
     * (см. services/autoload.php).
     *
     * @param array $address ['type'=>field|rfield|tv, 'resourceId'=>int, 'fieldName'=>..., 'level'=>..., ...]
     * @param mixed $value
     */
    public function writeField(array $address, $value): array
    {
        if (!class_exists('\\MpcServices\\Handlers\\FieldWriter')) {
            return ['success' => false, 'message' => 'migxpageconfigurator (mpc) 2.4.0+ is required'];
        }
        $writer = new \MpcServices\Handlers\FieldWriter($this->modx);
        return $writer->write($address, $value);
    }

    /**
     * Прочитать декодированный mpc_config уровня (read-only) — для панели
     * скрытых полей редактора. Делегирует в write-API mpc (FieldWriter::readConfig).
     *
     * @param string $level  resource|global|type
     * @param int    $resourceId
     */
    public function readConfig(string $level, int $resourceId): array
    {
        if (!class_exists('\\MpcServices\\Handlers\\FieldWriter')) {
            return ['success' => false, 'message' => 'migxpageconfigurator (mpc) 2.4.0+ is required', 'data' => []];
        }
        $writer = new \MpcServices\Handlers\FieldWriter($this->modx);
        return $writer->readConfig($level, $resourceId);
    }

    /**
     * Карта лексикона уровня {key:value} — чтобы панель скрытых полей показывала
     * ЗНАЧЕНИЯ, а не ключи. Пусто, если лексиконы выключены.
     */
    public function readLexicons(string $level, int $resourceId): array
    {
        if (!class_exists('\\MpcServices\\Handlers\\FieldWriter')) {
            return [];
        }
        $writer = new \MpcServices\Handlers\FieldWriter($this->modx);
        return $writer->readLexicons($level, $resourceId);
    }

    /**
     * Структурная операция над строками списка (add|delete|move). Делегирует в
     * write-API mpc (FieldWriter::writeRowOp).
     *
     * @param array $address level/resourceId/section/parentField/op + idx|fromIdx,toIdx
     */
    public function rowOp(array $address): array
    {
        if (!class_exists('\\MpcServices\\Handlers\\FieldWriter')) {
            return ['success' => false, 'message' => 'migxpageconfigurator (mpc) 2.4.0+ is required', 'data' => []];
        }
        $writer = new \MpcServices\Handlers\FieldWriter($this->modx);
        return $writer->writeRowOp($address);
    }
}
