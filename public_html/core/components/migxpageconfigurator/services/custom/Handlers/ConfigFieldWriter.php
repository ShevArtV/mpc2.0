<?php

namespace MpcServices\Handlers;

/**
 * Чистая мутация значения config-поля внутри JSON значения TV mpc_config.
 *
 * Структура mpc_config (как пишет SectionProcessor::handleSections):
 *   { "<position>": { section_name, MIGX_formname, position, is_static, ...поля... }, ... }
 * Поле migx-типа хранится строкой-JSON массива строк: "[{MIGX_id:1, ...}, ...]".
 *
 * Адрес:
 *   section      — section_name (или MIGX_formname) секции;
 *   fieldName    — имя поля;
 *   parentField  — имя migx-поля-контейнера (для значения внутри строки);
 *   idx          — индекс строки (0-based) внутри parentField.
 *
 * PURE: не трогает БД/файлы, работает со строкой JSON. Полностью юнит-тестируемо.
 * Лексиконные поля: в mpc_config лежит КЛЮЧ; перезапись текста лексикона —
 * отдельно (FieldWriter, через LexiconManager), сюда не входит.
 */
class ConfigFieldWriter
{
    /**
     * @return array ['success'=>bool, 'message'=>string, 'data'=>['json'=>string]]
     */
    public function setValue(string $configJson, array $address, $value): array
    {
        $config = json_decode($configJson, true);
        if (!is_array($config)) {
            return $this->err('invalid mpc_config JSON');
        }

        $section   = (string)($address['section'] ?? '');
        $fieldName = (string)($address['fieldName'] ?? '');
        if ($section === '' || $fieldName === '') {
            return $this->err('section and fieldName required');
        }

        $key = $this->findSectionKey($config, $section);
        if ($key === null) {
            return $this->err('section not found: ' . $section);
        }

        $path = $this->pathFromAddress($address);
        if ($path) {
            $ok = $this->mutateAtPath($config[$key], $path, 0, static function (array &$row) use ($fieldName, $value) {
                $row[$fieldName] = $value;
                return true;
            });
            if (!$ok) {
                return $this->err('row not found in path for field ' . $fieldName);
            }
        } else {
            $config[$key][$fieldName] = $value;
        }

        return [
            'success' => true,
            'message' => 'ok',
            'data'    => ['json' => json_encode($config, JSON_UNESCAPED_UNICODE)],
        ];
    }

    /**
     * Прочитать текущее значение (для readField / детекта лексиконного ключа).
     * @return array ['success'=>bool, 'message'=>string, 'data'=>['value'=>mixed]]
     */
    public function getValue(string $configJson, array $address): array
    {
        $config = json_decode($configJson, true);
        if (!is_array($config)) {
            return $this->err('invalid mpc_config JSON');
        }
        $section   = (string)($address['section'] ?? '');
        $fieldName = (string)($address['fieldName'] ?? '');
        $key = $this->findSectionKey($config, $section);
        if ($key === null) {
            return $this->err('section not found: ' . $section);
        }

        $path = $this->pathFromAddress($address);
        if ($path) {
            $value = null;
            $this->mutateAtPath($config[$key], $path, 0, static function (array &$row) use ($fieldName, &$value) {
                $value = $row[$fieldName] ?? null;
                return false; // чтение: не мутируем, json не пересобираем
            });
        } else {
            $value = $config[$key][$fieldName] ?? null;
        }

        return ['success' => true, 'message' => 'ok', 'data' => ['value' => $value]];
    }

    /**
     * Путь к строке как массив сегментов [['field'=>list, 'idx'=>i], ...] —
     * из address.path (вложенность любой глубины) либо из parentField+idx (1 ур.).
     */
    private function pathFromAddress(array $address): array
    {
        if (!empty($address['path']) && is_array($address['path'])) {
            $path = [];
            foreach ($address['path'] as $seg) {
                if (is_array($seg) && isset($seg['field']) && $seg['field'] !== '' && isset($seg['idx'])) {
                    $path[] = ['field' => (string)$seg['field'], 'idx' => (int)$seg['idx']];
                }
            }
            return $path;
        }
        $pf  = (string)($address['parentField'] ?? '');
        $idx = $address['idx'] ?? null;
        if ($pf !== '' && $idx !== null && $idx !== '') {
            return [['field' => $pf, 'idx' => (int)$idx]];
        }
        return [];
    }

    /**
     * Навигация по пути вложенных строк с декодом JSON-строк на каждом уровне.
     * На листе вызывает $leaf(&$row) (может мутировать строку). Возвращает успех
     * (false, если путь не резолвится). Для чтения $leaf просто читает строку;
     * пере-кодирование JSON безвредно (данные те же).
     */
    private function mutateAtPath(array &$container, array $path, int $depth, callable $leaf): bool
    {
        $seg   = $path[$depth];
        $field = $seg['field'];
        $i     = $seg['idx'];

        $rows = $this->decodeRows($container[$field] ?? '');
        if (!isset($rows[$i]) || !is_array($rows[$i])) {
            return false;
        }

        if ($depth === count($path) - 1) {
            $leaf($rows[$i]);
        } elseif (!$this->mutateAtPath($rows[$i], $path, $depth + 1, $leaf)) {
            return false;
        }

        $container[$field] = json_encode($rows, JSON_UNESCAPED_UNICODE);
        return true;
    }

    /**
     * Добавить ПУСТУЮ строку в конец списка (parentField). Структура под-полей
     * копируется из первой существующей строки (значения сброшены), MIGX_id = max+1.
     * ВЛОЖЕННЫЕ списки (JSON-строка строк) — структура СОХРАНЯЕТСЯ рекурсивно, а
     * значения чистятся (чтобы в новом элементе дочерний список был заполняем —
     * каждое поле получает плейсхолдер на фронте). Лексиконы: новые под-поля —
     * пустые литералы (лексиконизируются на нарезке с `1`).
     */
    public function addRow(string $configJson, array $address): array
    {
        $self = $this;
        return $this->mutateRows($configJson, $address, static function (array $rows) use ($self) {
            $maxId = 0;
            $template = [];
            foreach ($rows as $row) {
                if (is_array($row)) {
                    $maxId = max($maxId, (int)($row['MIGX_id'] ?? 0));
                    if (!$template) {
                        $template = $row;
                    }
                }
            }
            $rows[] = $self->blankRow($template, $maxId + 1);
            return $rows;
        });
    }

    /**
     * Пустая строка по образцу $template: скаляры → '', нативные массивы → [],
     * вложенные списки (JSON-строка строк-объектов) → структура каждой строки
     * сохраняется, но значения чистятся (рекурсивно). Так дочерний список нового
     * элемента приходит заполняемым, а не пропадает. PURE.
     *
     * @internal вызывается из addRow (замыкание); публичность — для замыкания.
     */
    public function blankRow(array $template, int $newId): array
    {
        $row = ['MIGX_id' => $newId];
        foreach ($template as $k => $v) {
            if ($k === 'MIGX_id') {
                continue;
            }
            if (is_array($v)) {
                // НАТИВНЫЙ массив. Вложенный список (массив строк-объектов) хранится
                // именно так: ContentParser кодирует JSON только верхний уровень поля,
                // вложенность остаётся нативной. Сохраняем структуру (deep-clone),
                // иначе дочерний список нового элемента затрётся. Прочее (sources,
                // пустой массив) — чистим в [].
                $row[$k] = ($v !== [] && is_array(reset($v))) ? $this->blankRows($v) : [];
            } elseif ($this->looksLikeRows($v)) {
                // То же, но вложенный список лежит JSON-строкой (после правок через
                // mutateAtPath) — сохраняем строкой.
                $row[$k] = json_encode($this->blankRows($this->decodeRows($v)), JSON_UNESCAPED_UNICODE);
            } else {
                $row[$k] = '';
            }
        }
        return $row;
    }

    /** Пустые версии массива строк (рекурсивно, MIGX_id переиндексирован). */
    private function blankRows(array $rows): array
    {
        $out = [];
        $nid = 0;
        foreach ($rows as $r) {
            if (is_array($r)) {
                $out[] = $this->blankRow($r, ++$nid);
            }
        }
        return $out;
    }

    /** Значение похоже на migx-строки (JSON-массив объектов или пустой массив)? */
    private function looksLikeRows($v): bool
    {
        if (!is_string($v) || $v === '') {
            return false;
        }
        $d = json_decode($v, true);
        return is_array($d) && ($d === [] || is_array(reset($d)));
    }

    /** Удалить строку по idx. Значения остальных строк (вкл. лексикон-ключи) едут с ними. */
    public function deleteRow(string $configJson, array $address): array
    {
        $idx = (int)($address['idx'] ?? -1);
        return $this->mutateRows($configJson, $address, static function (array $rows) use ($idx) {
            if ($idx < 0 || $idx >= count($rows)) {
                return null;
            }
            array_splice($rows, $idx, 1);
            return $rows;
        });
    }

    /** Переместить строку fromIdx → toIdx (значения/лексикон-ключи едут со строкой). */
    public function moveRow(string $configJson, array $address): array
    {
        $from = (int)($address['fromIdx'] ?? -1);
        $to   = (int)($address['toIdx'] ?? -1);
        return $this->mutateRows($configJson, $address, static function (array $rows) use ($from, $to) {
            $n = count($rows);
            if ($from < 0 || $from >= $n || $to < 0 || $to >= $n) {
                return null;
            }
            $moved = array_splice($rows, $from, 1);
            array_splice($rows, $to, 0, $moved);
            return $rows;
        });
    }

    /**
     * Общая мутация массива строк поля-списка. $fn(array $rows): ?array — возвращает
     * новый массив строк или null (ошибка индекса). PURE.
     *
     * Вложенные списки: address.path — путь СПУСКА к строке-контейнеру [{field,idx},…]
     * (НЕ включает целевой список), parentField — имя целевого списка в той строке.
     * Для top-level list path пуст. parentField+idx как путь для row-ops НЕ
     * используем (idx здесь — операнд $fn, а не сегмент спуска).
     */
    private function mutateRows(string $configJson, array $address, callable $fn): array
    {
        $config = json_decode($configJson, true);
        if (!is_array($config)) {
            return $this->err('invalid mpc_config JSON');
        }
        $section     = (string)($address['section'] ?? '');
        $parentField = (string)($address['parentField'] ?? '');
        if ($section === '' || $parentField === '') {
            return $this->err('section and parentField required');
        }
        $key = $this->findSectionKey($config, $section);
        if ($key === null) {
            return $this->err('section not found: ' . $section);
        }

        $descent = $this->descentPath($address);
        if ($descent) {
            $failed = false;
            $self = $this;
            $ok = $this->mutateAtPath($config[$key], $descent, 0, function (array &$row) use ($parentField, $fn, $self, &$failed) {
                $rows = $self->decodeRows($row[$parentField] ?? '');
                $newRows = $fn($rows);
                if ($newRows === null) {
                    $failed = true;
                    return false;
                }
                $row[$parentField] = json_encode(array_values($newRows), JSON_UNESCAPED_UNICODE);
                return true;
            });
            if (!$ok || $failed) {
                return $this->err('invalid nested row path/index for ' . $parentField);
            }
        } else {
            $rows = $this->decodeRows($config[$key][$parentField] ?? '');
            $newRows = $fn($rows);
            if ($newRows === null) {
                return $this->err('invalid row index for ' . $parentField);
            }
            $config[$key][$parentField] = json_encode(array_values($newRows), JSON_UNESCAPED_UNICODE);
        }

        return [
            'success' => true,
            'message' => 'ok',
            'data'    => ['json' => json_encode($config, JSON_UNESCAPED_UNICODE)],
        ];
    }

    /**
     * Путь СПУСКА к строке-контейнеру для row-операций над вложенным списком —
     * ТОЛЬКО из address.path (parentField+idx тут не путь: idx — операнд). Пуст
     * для top-level списков.
     */
    private function descentPath(array $address): array
    {
        $out = [];
        if (!empty($address['path']) && is_array($address['path'])) {
            foreach ($address['path'] as $seg) {
                if (is_array($seg) && isset($seg['field'], $seg['idx']) && $seg['field'] !== '') {
                    $out[] = ['field' => (string)$seg['field'], 'idx' => (int)$seg['idx']];
                }
            }
        }
        return $out;
    }

    private function findSectionKey(array $config, string $section): ?string
    {
        foreach ($config as $k => $s) {
            if (!is_array($s)) {
                continue;
            }
            if (($s['section_name'] ?? null) === $section || ($s['MIGX_formname'] ?? null) === $section) {
                return (string)$k;
            }
        }
        return null;
    }

    private function decodeRows($raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }

    private function err(string $message): array
    {
        return ['success' => false, 'message' => $message, 'data' => []];
    }
}
