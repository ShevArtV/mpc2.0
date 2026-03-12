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
                    $v = json_encode($v);
                    $v = str_replace('{', '{ ', $v);
                    $v = str_replace('##', '{', $v);
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
     * Рекурсивно разрешает наследование пресетов (extends).
     */
    private function getExtends(string $preset, ?array $extends = []): array
    {
        $parts = explode('.', $preset);
        $presetData = $this->properties['presets'][$parts[0]][$parts[1]] ?? null;

        if ($presetData && is_array($presetData)) {
            $extends = array_merge($extends, $presetData);
            if (!empty($presetData['extends'])) {
                $extends = $this->getExtends($presetData['extends'], $extends);
            }
        }

        return $extends;
    }
}
