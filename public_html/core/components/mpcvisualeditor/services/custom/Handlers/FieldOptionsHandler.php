<?php

namespace MpcVEServices\Handlers;

use MpcVEServices\Mpcve;

/**
 * Экшен fields/options: резолвит опции listbox/option/checkbox-поля в
 * [{label, value}, …]. Два источника:
 *   • TV (request.tv = имя) — берём modTemplateVar.elements;
 *   • секционное поле (request.raw = строка elements, напр. из data-mpc-values) —
 *     резолвим переданную строку (закрывает @SELECT, который фронт не парсит сам).
 *
 * Резолв повторяет нативный рендер listbox (modTemplateVarInputRenderListbox):
 * processBindings (@SELECT/@CHUNK/@INHERIT/…) → parseInputOptions → пары
 * "text==value" (explode по "=="; нет правой части → value=label). Для @SELECT
 * parseInputOptions отдаёт массив строк, поэтому каждая строка-запись приводится
 * к {label,value} по колонкам (text/name/… → подпись, value/id → значение).
 */
class FieldOptionsHandler extends BaseHandler
{


    public function handle(array $request): array
    {
        $rid = (int)($request['resourceId'] ?? 0);
        $tvName = trim((string)($request['tv'] ?? ''));
        $raw = (string)($request['raw'] ?? '');

        $tv = null;
        if ($tvName !== '') {
            $tv = $this->modx->getObject('modTemplateVar', ['name' => $tvName]);
            if (!$tv) {
                return ['success' => false, 'message' => 'TV не найдена: ' . $tvName, 'data' => []];
            }
        } elseif ($raw !== '') {
            // raw приходит из запроса (client-controlled). Биндинги @SELECT/@EVAL/
            // @FILE/@CHUNK/@RESOURCE/@INHERIT в processBindings = SQLi/PHP-eval/LFI/
            // чтение БД → запрещаем ЛЮБОЙ @-биндинг в raw (статические списки опций
            // вида "Label==value||..." никогда не начинаются с @). DB-driven опции —
            // только через именованную TV (ветка tv, доверенный источник из БД).
            if (strpos(ltrim($raw), '@') === 0) {
                return ['success' => false, 'message' => $this->modx->lexicon('mpcve_err_permission'), 'data' => []];
            }
            // Секционный listbox: транзитная TV — чтобы переиспользовать
            // parseInputOptions ровно как нативный рендер (без @-биндингов).
            $tv = $this->modx->newObject('modTemplateVar');
            $tv->set('elements', $raw);
        } else {
            return ['success' => false, 'message' => 'Не задан источник опций (tv|raw)', 'data' => []];
        }

        return ['success' => true, 'message' => '', 'data' => ['options' => $this->resolve($tv, $rid)]];
    }

    /**
     * elements (+@bindings) → [{label,value}].
     */
    private function resolve(\modTemplateVar $tv, int $rid): array
    {
        $parsed = $tv->parseInputOptions($tv->processBindings((string)$tv->get('elements'), $rid));
        if (!is_array($parsed)) {
            return [];
        }
        $out = [];
        foreach ($parsed as $option) {
            if (is_array($option)) {
                // @SELECT-запись (FETCH_ASSOC). Подпись — первая «текстовая» колонка,
                // значение — value/id; иначе [0]=подпись, [1]=значение.
                $label = $this->pick($option, ['text', 'name', 'pagetitle', 'title', 'menutitle', 'label']);
                $value = $this->pick($option, ['value', 'id']);
                $vals = array_values($option);
                if ($label === null) {
                    $label = isset($vals[0]) ? (string)$vals[0] : '';
                }
                if ($value === null) {
                    $value = isset($vals[1]) ? (string)$vals[1] : $label;
                }
            } else {
                $s = trim((string)$option);
                if ($s === '') {
                    continue;
                }
                $pair = explode('==', $s, 2);
                $label = trim($pair[0]);
                $value = isset($pair[1]) ? trim($pair[1]) : $label;
            }
            $out[] = ['label' => $label, 'value' => $value];
        }
        return $out;
    }

    /** Первое присутствующее значение по списку ключей-кандидатов или null. */
    private function pick(array $row, array $keys): ?string
    {
        foreach ($keys as $k) {
            if (array_key_exists($k, $row)) {
                return (string)$row[$k];
            }
        }
        return null;
    }
}
