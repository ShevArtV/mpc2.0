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
        // Разрешённые HTML-теги (та же настройка, что фильтрует html перед записью
        // в лексикон: strip_tags). RTE строит тулбар из неё и чистит html на save —
        // кнопок для тегов, которые всё равно вырежутся, не показываем.
        $allowedTags = array_values(array_filter(array_map(
            'trim',
            explode(',', (string)$this->modx->getOption('mpc_allowed_tags', null, ''))
        )));
        // Доп. атрибуты к безопасным дефолтам sanitizeHtml (per-tag whitelist в
        // rte.js). Настройка mpcve_allowed_attrs (как mpc_allowed_tags) — пусто
        // по умолчанию: применяются только хардкод-дефолты.
        $allowedAttrs = array_values(array_filter(array_map(
            'trim',
            explode(',', (string)$this->modx->getOption('mpcve_allowed_attrs', null, ''))
        )));
        return [
            'connectorUrl' => $this->config['connectorUrl'],
            'assetsUrl'    => $this->config['assetsUrl'],
            'editParam'    => $this->config['editParam'],
            'resourceId'   => $resource ? (int)$resource->get('id') : 0,
            'allowedTags'  => $allowedTags,
            'allowedAttrs' => $allowedAttrs,
            // Право на правку ГЛОБАЛЬНЫХ данных (настройки data-mpc-info, контакты):
            // фронт помечает их редактируемыми только при наличии mpcve_edit_global.
            'editGlobal'   => (new Handlers\PermissionChecker($this->modx))->canEditGlobal(),
            // CSRF-nonce: коннектор сверяет его с сессией на каждом write-действии.
            'nonce'        => $this->nonce(),
            // Для кнопки «Открыть в админке» (тулбар).
            'managerUrl'   => (string)$this->modx->getOption('manager_url', null, '/manager/'),
        ];
    }

    /**
     * CSRF-nonce редактора: один на сессию (стабилен между вкладками/перезагрузками).
     * Хранится в сессии MODX (та же, что у коннектора). Пусто, если сессии нет.
     */
    public function nonce(): string
    {
        if (!isset($_SESSION)) {
            return '';
        }
        if (empty($_SESSION['mpcve_nonce'])) {
            $_SESSION['mpcve_nonce'] = bin2hex(random_bytes(16));
        }
        return (string)$_SESSION['mpcve_nonce'];
    }

    /**
     * Записать значение поля по адресу. Делегирует в write-API mpc
     * (MpcServices\Handlers\FieldWriter, M4). Доступен через общий vendor mpc
     * (см. services/autoload.php).
     *
     * @param array $address ['type'=>field|rfield|tv, 'resourceId'=>int, 'fieldName'=>..., 'level'=>..., ...]
     * @param mixed $value
     */
    /** Сообщение при отсутствии mpc-пакета. */
    private const MPC_REQUIRED = 'migxpageconfigurator (mpc) 2.4.0+ is required';

    /** @var \MpcServices\Handlers\FieldWriter|null */
    private $writer;
    private bool $writerResolved = false;

    /** Ленивый FieldWriter из общего vendor mpc; null — пакет mpc не установлен. */
    private function getWriter()
    {
        if (!$this->writerResolved) {
            $this->writerResolved = true;
            $this->writer = class_exists('\\MpcServices\\Handlers\\FieldWriter')
                ? new \MpcServices\Handlers\FieldWriter($this->modx)
                : null;
        }
        return $this->writer;
    }

    public function writeField(array $address, $value): array
    {
        $w = $this->getWriter();
        return $w ? $w->write($address, $value) : ['success' => false, 'message' => self::MPC_REQUIRED];
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
        $w = $this->getWriter();
        return $w ? $w->readConfig($level, $resourceId)
            : ['success' => false, 'message' => self::MPC_REQUIRED, 'data' => []];
    }

    /**
     * Записать ВЕСЬ массив секций уровня (RAW) — для структурных операций над
     * секциями (порядок/видимость/статичность). Делегирует FieldWriter::saveConfig.
     */
    public function saveConfig(string $level, int $resourceId, array $config): array
    {
        $w = $this->getWriter();
        return $w ? $w->saveConfig($level, $resourceId, $config)
            : ['success' => false, 'message' => self::MPC_REQUIRED];
    }

    /**
     * Скопировать ОДНУ секцию из типа в ресурс (выборочно, кнопка-замок сайдбара).
     */
    public function copySectionFromType(int $resourceId, string $section): array
    {
        $w = $this->getWriter();
        return $w ? $w->copyTypeSectionToResource($resourceId, $section)
            : ['success' => false, 'message' => self::MPC_REQUIRED];
    }

    /**
     * Массовое копирование секций из типа: mode=merge (дополнить) | overwrite (заменить).
     */
    public function copySectionsFromType(int $resourceId, string $mode): array
    {
        $w = $this->getWriter();
        if (!$w) {
            return ['success' => false, 'message' => self::MPC_REQUIRED];
        }
        $resource = $this->modx->getObject('modResource', $resourceId);
        if (!$resource) {
            return ['success' => false, 'message' => 'resource not found: ' . $resourceId];
        }
        return $w->copySectionsFromType($resource, $mode === 'overwrite' ? 'overwrite' : 'merge');
    }

    /**
     * Ресурс является ТИПОМ страницы (родитель = mpc_static_block_page_id)?
     * У типа нет родительского типа → в сайдборе не показываем «из типа».
     */
    public function isTypeResource(int $resourceId): bool
    {
        $r = $this->modx->getObject('modResource', $resourceId);
        if (!$r) {
            return false;
        }
        $pid = (int)$this->modx->getOption('mpc_static_block_page_id', null, 0);
        return $pid > 0 && (int)$r->get('parent') === $pid;
    }

    /**
     * Карта лексикона уровня {key:value} — чтобы панель скрытых полей показывала
     * ЗНАЧЕНИЯ, а не ключи. Пусто, если лексиконы выключены.
     */
    public function readLexicons(string $level, int $resourceId): array
    {
        $w = $this->getWriter();
        return $w ? $w->readLexicons($level, $resourceId) : [];
    }

    /**
     * Структурная операция над строками списка (add|delete|move). Делегирует в
     * write-API mpc (FieldWriter::writeRowOp).
     *
     * @param array $address level/resourceId/section/parentField/op + idx|fromIdx,toIdx
     */
    public function rowOp(array $address): array
    {
        $w = $this->getWriter();
        return $w ? $w->writeRowOp($address)
            : ['success' => false, 'message' => self::MPC_REQUIRED, 'data' => []];
    }

    /**
     * Откат row-операции: восстановить поле-список к снимку ДО (значение из
     * истории). Делегирует FieldWriter::restoreRows.
     */
    public function restoreRows(array $address, $rowsValue): array
    {
        $w = $this->getWriter();
        return $w ? $w->restoreRows($address, $rowsValue)
            : ['success' => false, 'message' => self::MPC_REQUIRED, 'data' => []];
    }
}
