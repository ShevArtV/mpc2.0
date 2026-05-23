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

        $parentField = (string)($address['parentField'] ?? '');
        $idx         = $address['idx'] ?? null;

        if ($parentField !== '' && $idx !== null && $idx !== '') {
            $rows = $this->decodeRows($config[$key][$parentField] ?? '');
            $i = (int)$idx;
            if (!isset($rows[$i])) {
                return $this->err("row not found: {$parentField}[{$i}]");
            }
            $rows[$i][$fieldName] = $value;
            $config[$key][$parentField] = json_encode($rows, JSON_UNESCAPED_UNICODE);
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

        $parentField = (string)($address['parentField'] ?? '');
        $idx         = $address['idx'] ?? null;

        if ($parentField !== '' && $idx !== null && $idx !== '') {
            $rows = $this->decodeRows($config[$key][$parentField] ?? '');
            $i = (int)$idx;
            $value = $rows[$i][$fieldName] ?? null;
        } else {
            $value = $config[$key][$fieldName] ?? null;
        }

        return ['success' => true, 'message' => 'ok', 'data' => ['value' => $value]];
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
