<?php

namespace MpcServices\Handlers\Cutter;

/**
 * Генерирует PHP/Fenom-код вызова сниппета из пресетов.
 * Полностью независим от MODX и DOM.
 */
class SnippetCallBuilder
{
    private array $properties;

    public function __construct(array $properties)
    {
        $this->properties = $properties;
    }

    /**
     * Генерирует код вызова сниппета для подстановки в шаблон.
     *
     * @param string $value    Значение data-mpc-snippet: "snippetName|presetName"
     * @param string $firstSymbol  '{' или '##'
     */
    public function getSnippetCall(string $value, string $firstSymbol): string
    {
        $params = '';
        $value = explode('|', $value);
        $snippetName = $value[0];
        $isStatic = ($firstSymbol === '##');
        $firstVarCondition = null;

        if (strpos($value[0], '@FILE') === 0) {
            $presetKey = str_replace('@FILE', '', strtolower(pathinfo($value[0], PATHINFO_FILENAME)));
        } else {
            $presetKey = str_replace('!', '', strtolower($value[0]));
        }

        $presetName = $value[1] ?? '';

        if (isset($this->properties['presets'][$presetKey][$presetName])) {
            $preset = $this->properties['presets'][$presetKey][$presetName];

            if (!empty($preset['extends'])) {
                if (strpos($preset['extends'], '.') === false) {
                    $preset['extends'] = $presetKey . '.' . $preset['extends'];
                }
                $extendsPreset = $this->getExtends($preset['extends'], []);
                $preset = array_merge($extendsPreset, $preset);
                unset($preset['extends']);
            }

            foreach ($preset as $k => $v) {
                if (is_null($v)) {
                    continue;
                }
                if (is_array($v)) {
                    // Рекурсивный Fenom array-литерал ([...]) вместо json: доносит
                    // до рендера вложенные условия с ЖИВЫМИ выражениями, например
                    // 'where' => ['alias' => $resource.alias]. Прежний json+хаки
                    // отдавали это строкой-литералом, и переменная не вычислялась.
                    // ## внутри массива по-прежнему → { (eager-плейсхолдеры статики).
                    $v = str_replace('##', '{', $this->arrayToFenom($v));
                }
                $v = (string)$v;
                if (strpos($v, '#/') === 0) {
                    $v = str_replace('#/', '@FILE ' . $this->properties['pathToChunks'], $v);
                }

                if (strpos($v, '$') === 0 || strpos($v, '[') === 0 || strpos($v, '"') === 0) {
                    // Для статичных секций (##) переменные с $ оборачиваем в {…},
                    // чтобы предпарсинг (parseChunk) подставил лексиконные значения.
                    if ($isStatic && strpos($v, '$') === 0) {
                        if ($firstVarCondition === null) {
                            $firstVarCondition = $v;
                        }
                        $params .= "'$k' => {" . $v . "}," . PHP_EOL;
                    } else {
                        $params .= "'$k' => $v," . PHP_EOL;
                    }
                } else {
                    $params .= "'$k' => '$v'," . PHP_EOL;
                }

                if ($k === 'toPls' && $v) {
                    $firstSymbol = PHP_EOL . $firstSymbol . 'set $' . $v . ' = ';
                }
            }
        }

        if ($params) {
            $call = PHP_EOL . "$firstSymbol'$snippetName' | snippet: [
                        $params
                        ]}" . PHP_EOL;

            // Для статичных секций: {$var} вычисляется при предпарсинге, а ##snippet —
            // отложен до финального рендера. Если $var пуст, будет 'input' => , —
            // синтаксическая ошибка. Оборачиваем в {if} (предпарсинг), чтобы при пустом
            // значении весь вызов удалялся.
            if ($isStatic && $firstVarCondition !== null) {
                $call = '{if ' . $firstVarCondition . '}' . $call . '{/if}';
            }

            return $call;
        }

        return PHP_EOL . "$firstSymbol'$snippetName' | snippet: []}" . PHP_EOL;
    }

    /**
     * Рекурсивно сериализует PHP-массив пресета в Fenom array-литерал ([...]),
     * сохраняя Fenom-выражения живыми. Каждый лист обрабатывается так же, как
     * скалярный параметр (scalarToFenom): выражение ($/[/") отдаётся как есть,
     * литерал — в одинарных кавычках, число — как есть. Позволяет доносить до
     * рендера вложенные условия вида ['alias' => $resource.alias] на любой глубине.
     */
    private function arrayToFenom(array $arr): string
    {
        $isList = array_keys($arr) === range(0, count($arr) - 1);
        $parts  = [];
        foreach ($arr as $k => $v) {
            $rendered = is_array($v) ? $this->arrayToFenom($v) : $this->scalarToFenom($v);
            $parts[]  = $isList ? $rendered : "'$k' => $rendered";
        }
        return '[' . implode(', ', $parts) . ']';
    }

    /**
     * Сериализует скалярное значение листа массива: число — как есть; #/path —
     * в @FILE-чанк (литерал в кавычках); выражение ($/[/") — как есть; прочее —
     * строковый литерал в кавычках. Та же эвристика «выражение vs литерал», что
     * и для скалярных параметров верхнего уровня в getSnippetCall.
     */
    private function scalarToFenom($v): string
    {
        if (is_int($v) || is_float($v)) {
            return (string)$v;
        }
        $v = (string)$v;
        if (strpos($v, '#/') === 0) {
            $v = str_replace('#/', '@FILE ' . $this->properties['pathToChunks'], $v);
            return "'" . $v . "'";
        }
        if (strpos($v, '$') === 0 || strpos($v, '[') === 0 || strpos($v, '"') === 0) {
            return $v;
        }
        return "'" . $v . "'";
    }

    /**
     * Рекурсивно разрешает наследование пресетов (extends).
     * $seen защищает от циклического extends (A→B→A → переполнение стека).
     */
    private function getExtends(string $preset, array $extends = [], array $seen = []): array
    {
        if (in_array($preset, $seen, true)) {
            return $extends; // цикл extends — прерываем
        }
        $seen[] = $preset;

        $parts = explode('.', $preset);
        $presetData = $this->properties['presets'][$parts[0]][$parts[1] ?? ''] ?? null;

        if ($presetData && is_array($presetData)) {
            $extends = array_merge($extends, $presetData);
            if (!empty($presetData['extends'])) {
                $extends = $this->getExtends($presetData['extends'], $extends, $seen);
            }
        }

        return $extends;
    }
}
