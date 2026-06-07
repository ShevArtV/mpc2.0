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
class FieldTypesHandler extends BaseHandler
{
    /** Индекс таба «Настройки секции» в formtabs mpc_base (1=Контент, 2=Стили). */
    private const SETTINGS_TAB_INDEX = 0;



    public function handle(array $request): array
    {
        $base = (string)$this->modx->getOption('mpc_base_section_name', null, 'mpc_base');
        $core = (string)$this->modx->getOption('core_path', null, MODX_CORE_PATH);
        $this->modx->addPackage('migx', $core . 'components/migx/model/');

        $map = [];
        $labels = [];
        // Имена полей таба «Настройки секции» (formtabs index 0; 1=Контент, 2=Стили).
        // Фронт исключает их из панели скрытых полей (hide_section, position и т.п.).
        $settings = [];
        if ($cfg = $this->modx->getObject('migxConfig', ['name' => $base])) {
            $tabs = json_decode((string)$cfg->get('formtabs'), true);
            if (is_array($tabs)) {
                foreach ($tabs as $tabIdx => $tab) {
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
                        if ((int)$tabIdx === self::SETTINGS_TAB_INDEX) {
                            $settings[] = $name;
                        }
                    }
                }
            }
        }

        // Карта типов TV (имя TV → тип редактора по modTemplateVar.type). TV — НЕ
        // config-поле mpc_base, поэтому для них своя карта: иначе тип брался по
        // совпадению имени с config-полем (TV subtitle ловил тип одноимённого поля).
        // S14: НЕ грузим все TV сайта (разведка + OOM). На странице видны только
        // TV её шаблона — ограничиваем выборку шаблоном ресурса; backstop-лимит
        // на случай отсутствия ресурса.
        $tvs = [];
        $rid = (int)($request['resourceId'] ?? 0);
        $templateId = 0;
        if ($rid > 0 && ($res = $this->modx->getObject('modResource', $rid))) {
            $templateId = (int)$res->get('template');
        }
        $q = $this->modx->newQuery('modTemplateVar');
        if ($templateId > 0) {
            $q->innerJoin('modTemplateVarTemplate', 'TemplateVarTemplate', 'TemplateVarTemplate.tmplvarid = modTemplateVar.id');
            $q->where(['TemplateVarTemplate.templateid' => $templateId]);
        }
        $q->limit(500);
        foreach ($this->modx->getCollection('modTemplateVar', $q) as $tv) {
            $name = (string)$tv->get('name');
            if ($name === '') {
                continue;
            }
            $type = (string)$tv->get('type');
            $configs = '';
            if ($type === 'migx') {
                $props = $tv->get('input_properties');
                if (is_string($props)) {
                    $props = json_decode($props, true);
                }
                $configs = is_array($props) ? (string)($props['configs'] ?? '') : '';
            }
            $tvs[$name] = $this->editorType($type, $configs);
        }

        return ['success' => true, 'message' => '', 'data' => [
            'fields' => $map, 'labels' => $labels, 'settings' => $settings, 'tvs' => $tvs,
        ]];
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
                return 'textarea';
            case 'listbox':
                return 'listbox';
            case 'listbox-multiple':
                return 'listbox-multiple';
            // option (radio) — одиночный выбор: тот же редактор-список, что и listbox,
            // но рисует радио. checkbox — множественный (как listbox-multiple, но
            // чекбоксы). Оба берут опции из elements TV (резолвер fields/options).
            case 'option':
                return 'option';
            case 'checkbox':
                return 'checkbox';
            case 'number':
                return 'number';
            case 'date':
                return 'date';
            case 'tag':
            case 'tags':
            case 'autotag':
                return 'tags';
            case 'file':
                return 'file';
            // email/url — спец-валидация не нужна, правим как простой текст-инпут.
            case 'email':
            case 'url':
            case 'text':
            case '':
            default:
                return 'text';
        }
    }
}
