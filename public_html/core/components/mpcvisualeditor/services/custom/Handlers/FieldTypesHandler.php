<?php

namespace MpcVEServices\Handlers;

use MpcVEServices\Mpcve;

/**
 * Экшен fields/types: карта { fieldName => editorType } из ЖИВОГО конфига
 * mpc_base (formtabs) в БД. Тип редактора выводится из inputTVtype (+configs),
 * как и определено в конфигураторе — редактор на фронте диспатчится по типу
 * поля (text/richtext/image/media/rows). Вариант B: фронт грузит карту раз и
 * кеширует, не трогаем каттер/перенарезку, учитываются кастомные поля сайта.
 */
class FieldTypesHandler
{
    private \modX $modx;
    private Mpcve $mpcve;

    public function __construct(\modX $modx, Mpcve $mpcve)
    {
        $this->modx = $modx;
        $this->mpcve = $mpcve;
    }

    public function handle(array $request): array
    {
        $base = (string)$this->modx->getOption('mpc_base_section_name', null, 'mpc_base');
        $core = (string)$this->modx->getOption('core_path', null, MODX_CORE_PATH);
        $this->modx->addPackage('migx', $core . 'components/migx/model/');

        $map = [];
        $labels = [];
        if ($cfg = $this->modx->getObject('migxConfig', ['name' => $base])) {
            $tabs = json_decode((string)$cfg->get('formtabs'), true);
            if (is_array($tabs)) {
                foreach ($tabs as $tab) {
                    foreach ($tab['fields'] ?? [] as $f) {
                        $name = (string)($f['field'] ?? '');
                        if ($name === '') {
                            continue;
                        }
                        $map[$name] = $this->editorType(
                            (string)($f['inputTVtype'] ?? ''),
                            (string)($f['configs'] ?? '')
                        );
                        // caption поля для подписи в редакторе (вместо ключа).
                        $caption = trim((string)($f['caption'] ?? ''));
                        if ($caption !== '') {
                            $labels[$name] = $caption;
                        }
                    }
                }
            }
        }

        return ['success' => true, 'message' => '', 'data' => ['fields' => $map, 'labels' => $labels]];
    }

    /**
     * inputTVtype (+configs migx-композита) → тип редактора фронта.
     */
    private function editorType(string $inputType, string $configs): string
    {
        $configs = strtolower($configs);
        switch ($inputType) {
            case 'image':
                return 'image';
            case 'richtext':
            case 'tinymce':
            case 'tinymcerte':
                return 'richtext';
            case 'migx':
                if (strpos($configs, 'list') !== false) {
                    return 'rows';
                }
                if (strpos($configs, 'video') !== false || strpos($configs, 'audio') !== false) {
                    return 'media';
                }
                if (strpos($configs, 'img') !== false || strpos($configs, 'picture') !== false) {
                    return 'image';
                }
                return 'rows';
            case 'textarea':
            case 'text':
            case '':
            default:
                return 'text';
        }
    }
}
