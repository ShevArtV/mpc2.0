<?php
/**
 * Копирование секций из ресурса-типа в текущий ресурс — бэкенд кнопок тулбара
 * MIGX-грида mpc_config (см. migxconfigs/grid/grid.mpc_config.config.inc.php).
 *
 * Параметры: id (ресурс), mode = merge|overwrite|clear.
 *   - merge     — добавить недостающие секции типа (правки ресурса сохраняются);
 *   - overwrite — заменить конфиг ресурса конфигом типа целиком;
 *   - clear     — очистить конфиг секций ресурса (mpc_config = []).
 * merge/overwrite копируют лексиконы добавленных секций (делегируют в
 * FieldWriter::copySectionsFromType). clear — просто пустой конфиг.
 *
 * Возвращает новый конфиг и id TV — фронт обновляет грид БЕЗ перезагрузки.
 */
class MigxpageconfiguratorResourceCopysectionsProcessor extends modProcessor
{
    /** Требуется право редактирования ресурса (коннектор проверяет лишь сессию). */
    public function checkPermissions()
    {
        return $this->modx->hasPermission('save_document') || $this->modx->hasPermission('mpc_edit');
    }

    public function process()
    {
        $this->modx->lexicon->load('migxpageconfigurator:default');

        $corePath = $this->modx->getOption('migxpageconfigurator_core_path', null,
            $this->modx->getOption('core_path') . 'components/migxpageconfigurator/');
        require_once $corePath . 'services/vendor/autoload.php';

        $id = (int)$this->getProperty('id', $this->getProperty('object_id', 0));
        if ($id <= 0) {
            return $this->failure($this->modx->lexicon('mpc_copy_sections_err_no_id'));
        }
        $mode = (string)$this->getProperty('mode', 'merge');
        if (!in_array($mode, ['merge', 'overwrite', 'clear'], true)) {
            $mode = 'merge';
        }

        /** @var \modResource $resource */
        $resource = $this->modx->getObject('modResource', $id);
        if (!$resource) {
            return $this->failure($this->modx->lexicon('mpc_copy_sections_err_no_resource'));
        }

        $configTvName = $this->modx->getOption('mpc_common_config_name', null, 'mpc_config');

        if ($mode === 'clear') {
            $resource->setTVValue($configTvName, '[]');
            $newJson  = '[]';
            $sections = 0;
        } else {
            $res = (new \MpcServices\Handlers\FieldWriter($this->modx))->copySectionsFromType($resource, $mode);
            if (empty($res['success'])) {
                return $this->failure($res['message'] ?? $this->modx->lexicon('mpc_copy_sections_err_no_resource'));
            }
            $newJson  = (string)($res['data']['json'] ?? '');
            $sections = (int)($res['data']['sections'] ?? 0);
        }

        // Конфиг ресурса изменился → сбрасываем запечённый parsed, чтобы фронт
        // перерисовал секции (re-cut не нужен: вёрстку не трогали).
        (new \MpcServices\Mpc($this->modx))->render->deleteParsedConfigFile($id);

        $tv = $this->modx->getObject('modTemplateVar', ['name' => $configTvName]);

        if ($mode === 'clear') {
            $msg = $this->modx->lexicon('mpc_copy_sections_ok_clear');
        } elseif ($mode === 'overwrite') {
            $msg = $this->modx->lexicon('mpc_copy_sections_ok_overwrite');
        } else {
            $msg = sprintf($this->modx->lexicon('mpc_copy_sections_ok_merge'), $sections);
        }

        return $this->success($msg, [
            'id'       => $id,
            'tvid'     => $tv ? (int)$tv->get('id') : 0,
            'config'   => $newJson,
            'sections' => $sections,
        ]);
    }
}
return 'MigxpageconfiguratorResourceCopysectionsProcessor';
