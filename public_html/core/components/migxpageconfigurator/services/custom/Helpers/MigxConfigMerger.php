<?php

namespace MpcServices\Helpers;

/**
 * Мерджер migxConfig для апгрейда пакета.
 *
 * Правило: в formtabs.fields и columns поля с именем (`field`),
 * начинающимся на `mpc_`, перезаписываются из бандла. Остальные поля,
 * если уже есть в БД — сохраняются как есть (пользовательские правки
 * не затираются); если в БД отсутствуют — добавляются из бандла.
 * Пользовательские поля без `mpc_`-префикса и пользовательские tab'ы
 * полностью сохраняются. Прочие скалярные колонки migxConfig
 * (`category`, `permissions`, ...) берутся из бандла как раньше.
 */
class MigxConfigMerger
{
    private const PRESERVE_PREFIX = 'mpc_';

    public function merge(array $bundle, ?array $existing): array
    {
        if ($existing === null) {
            return $bundle;
        }

        $merged = $bundle;

        if (isset($bundle['formtabs']) || isset($existing['formtabs'])) {
            $merged['formtabs'] = json_encode(
                $this->mergeTabs(
                    $this->decodeArr($bundle['formtabs'] ?? '[]'),
                    $this->decodeArr($existing['formtabs'] ?? '[]')
                )
            );
        }

        if (isset($bundle['columns']) || isset($existing['columns'])) {
            $merged['columns'] = json_encode(
                $this->mergeFlatList(
                    $this->decodeArr($bundle['columns'] ?? '[]'),
                    $this->decodeArr($existing['columns'] ?? '[]'),
                    'dataIndex'
                )
            );
        }

        return $merged;
    }

    private function mergeTabs(array $bundleTabs, array $existingTabs): array
    {
        // Tab'ы с непустым caption сопоставляются по caption. Tab'ы с пустым
        // caption (а такими являются почти все конфиги — единственный безымянный
        // tab) сопоставляются позиционно: иначе пустой caption — не ключ, и
        // безымянный tab из бандла + безымянный из БД оба попадают в выдачу →
        // задвоение tab'ов при апгрейде.
        $existingByCaption = [];
        $existingByPos = [];
        foreach ($existingTabs as $tab) {
            $caption = $tab['caption'] ?? null;
            if ($caption === null || $caption === '') {
                $existingByPos[] = $tab;
            } else {
                $existingByCaption[$caption] = $tab;
            }
        }

        $merged = [];
        $consumed = [];
        $posCursor = 0;
        foreach ($bundleTabs as $bTab) {
            $caption = $bTab['caption'] ?? null;
            $eTab = null;
            if ($caption !== null && $caption !== '') {
                if (isset($existingByCaption[$caption])) {
                    $eTab = $existingByCaption[$caption];
                    $consumed[$caption] = true;
                }
            } else {
                if (array_key_exists($posCursor, $existingByPos)) {
                    $eTab = $existingByPos[$posCursor];
                    unset($existingByPos[$posCursor]);
                }
                $posCursor++;
            }

            if ($eTab !== null) {
                $mergedTab = $bTab;
                $mergedTab['fields'] = $this->mergeFlatList(
                    $bTab['fields'] ?? [],
                    $eTab['fields'] ?? [],
                    'field'
                );
                $merged[] = $mergedTab;
            } else {
                $merged[] = $bTab;
            }
        }

        foreach ($existingByCaption as $caption => $eTab) {
            if (empty($consumed[$caption])) {
                $merged[] = $eTab;
            }
        }
        /*
         * Хвост безымянных tab'ов из БД. Позиционное сопоставление выше новых
         * задвоений не создаёт, но НАКОПЛЕННЫЕ им уже не разобрать: до фикса
         * каждый апгрейд добавлял по безымянному tab'у, и на серверах, прошедших
         * через те версии, их лежит по два-три. Для редактора это не косметика:
         * безымянные tab'ы рисуются подряд, в окне появляется несколько полей с
         * одним `name`, правится верхнее, а сохраняется значение другого
         * (sleepandglow 07.08.2026 — постер видео в TV не сохранялся, 44 конфига).
         *
         * Поэтому хвост, который не несёт НИ ОДНОГО поля сверх уже собранных,
         * отбрасываем — терять в нём нечего. Безымянный tab с собственными
         * полями остаётся: это уже пользовательский, а не наследие задвоения.
         */
        $knownFields = [];
        foreach ($merged as $mTab) {
            foreach ($this->fieldNames($mTab) as $name) {
                $knownFields[$name] = true;
            }
        }
        foreach ($existingByPos as $eTab) {
            $names = $this->fieldNames($eTab);
            if ($merged && !array_diff($names, array_keys($knownFields))) {
                continue;
            }
            foreach ($names as $name) {
                $knownFields[$name] = true;
            }
            $merged[] = $eTab;
        }

        foreach ($merged as $i => &$tab) {
            if (array_key_exists('MIGX_id', $tab)) {
                $tab['MIGX_id'] = $i + 1;
            }
        }
        unset($tab);

        return $merged;
    }

    /**
     * Имена полей tab'а — по ним сверяется, несёт ли безымянный хвост что-то своё.
     *
     * @return string[]
     */
    private function fieldNames(array $tab): array
    {
        $names = [];
        foreach ($tab['fields'] ?? [] as $field) {
            $name = is_array($field) ? ($field['field'] ?? '') : '';
            if ($name !== '') {
                $names[] = (string)$name;
            }
        }

        return $names;
    }

    private function mergeFlatList(array $bundle, array $existing, string $key): array
    {
        $existingByName = [];
        $existingTail = [];
        foreach ($existing as $item) {
            $name = $item[$key] ?? null;
            if ($name === null || $name === '') {
                $existingTail[] = $item;
            } else {
                $existingByName[$name] = $item;
            }
        }

        $merged = [];
        $consumed = [];
        foreach ($bundle as $bItem) {
            $name = $bItem[$key] ?? null;
            if ($name === null || $name === '') {
                $merged[] = $bItem;
                continue;
            }
            if (strpos($name, self::PRESERVE_PREFIX) === 0) {
                $merged[] = $bItem;
                $consumed[$name] = true;
            } elseif (isset($existingByName[$name])) {
                $merged[] = $existingByName[$name];
                $consumed[$name] = true;
            } else {
                $merged[] = $bItem;
            }
        }

        foreach ($existingByName as $name => $item) {
            if (empty($consumed[$name])) {
                $merged[] = $item;
            }
        }
        foreach ($existingTail as $item) {
            $merged[] = $item;
        }

        foreach ($merged as $i => &$item) {
            if (array_key_exists('MIGX_id', $item)) {
                $item['MIGX_id'] = $i + 1;
            }
        }
        unset($item);

        return $merged;
    }

    private function decodeArr($value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || $value === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
