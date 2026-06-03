<?php

namespace MpcVEServices\Handlers;

use MpcVEServices\Mpcve;

/**
 * Экшен section/op: структурные операции над СЕКЦИЯМИ (порядок / видимость /
 * статичность). Пишет ВЕСЬ массив секций уровня RAW (Mpcve::saveConfig) — НЕ
 * через field/save: служебные поля position/hide_section/is_static нельзя гонять
 * через лексикон-aware writeConfigField (он бы превратил значение в лексикон-ключ).
 *
 *  - move       — переупорядочивание: новый порядок секций (order = имена) →
 *                 переставляем массив + переназначаем position 1..N.
 *  - visibility — hide_section = 0|1 на секции.
 *  - static     — is_static = 0|1 + МИГРАЦИЯ контента: при включении копия секции
 *                 кладётся в конфиг staticBlocksPage (рендер берёт контент оттуда);
 *                 при выключении — убирается из staticBlocksPage (контент остаётся
 *                 в ресурсе). Уровень global = staticBlocksPage.
 */
class SectionOpHandler
{
    private \modX $modx;
    private Mpcve $mpcve;

    public function __construct(\modX $modx, Mpcve $mpcve)
    {
        $this->modx  = $modx;
        $this->mpcve = $mpcve;
    }

    public function handle(array $request): array
    {
        $op = (string)($request['op'] ?? '');
        $resourceId = (int)($request['resourceId'] ?? 0);
        if ($resourceId <= 0 && $this->modx->resource) {
            $resourceId = (int)$this->modx->resource->get('id');
        }
        if ($resourceId <= 0) {
            return $this->err('resourceId required');
        }

        $read = $this->mpcve->readConfig('resource', $resourceId);
        if (empty($read['success'])) {
            return $this->err($read['message'] ?? 'cannot read config');
        }
        $config = array_values((array)($read['data']['config'] ?? []));

        switch ($op) {
            case 'move':
                $res = $this->move($config, $resourceId, $request);
                break;
            case 'visibility':
                $res = $this->setField($config, $resourceId, $request, 'hide_section');
                break;
            case 'static':
                $res = $this->setStatic($config, $resourceId, $request);
                break;
            default:
                return $this->err('unknown section op: ' . $op);
        }

        if (!empty($res['success'])) {
            // Аудит секционной операции (откат не поддержан → revertable=0).
            (new ChangeLog($this->modx))->add([
                'resource_id' => $resourceId,
                'user_id'     => (int)($this->modx->user ? $this->modx->user->get('id') : 0),
                'username'    => (string)($this->modx->user ? $this->modx->user->get('username') : ''),
                'action'      => 'section',
                'section'     => (string)($request['section'] ?? ''),
                'field'       => $op,
                'new_value'   => 'секции: ' . $op . (isset($request['value']) ? ('=' . $request['value']) : ''),
                'revertable'  => 0,
            ]);
        }
        return $res;
    }

    /** Индекс секции по имени (section_name или MIGX_formname). -1 — нет. */
    private function indexOf(array $config, string $name): int
    {
        foreach ($config as $i => $s) {
            if (is_array($s) && (($s['section_name'] ?? null) === $name || ($s['MIGX_formname'] ?? null) === $name)) {
                return (int)$i;
            }
        }
        return -1;
    }

    private function move(array $config, int $rid, array $request): array
    {
        $order = $request['order'] ?? '';
        if (is_string($order)) {
            $order = json_decode($order, true);
        }
        if (!is_array($order) || !$order) {
            return $this->err('order required');
        }
        $byName = [];
        foreach ($config as $s) {
            if (is_array($s) && isset($s['section_name'])) {
                $byName[$s['section_name']] = $s;
            }
        }
        $new = [];
        $used = [];
        foreach ($order as $name) {
            if (isset($byName[$name])) {
                $new[] = $byName[$name];
                $used[$name] = true;
            }
        }
        // Не упомянутые в order (или без section_name) — в конец, в прежнем порядке.
        foreach ($config as $s) {
            if (!is_array($s)) {
                continue;
            }
            $nm = $s['section_name'] ?? null;
            if ($nm === null || empty($used[$nm])) {
                $new[] = $s;
            }
        }
        foreach ($new as $i => &$s) {
            if (is_array($s)) {
                $s['position'] = $i + 1;
            }
        }
        unset($s);
        return $this->save($rid, $new);
    }

    private function setField(array $config, int $rid, array $request, string $field): array
    {
        $name = (string)($request['section'] ?? '');
        $idx = $this->indexOf($config, $name);
        if ($idx < 0) {
            return $this->err('section not found: ' . $name);
        }
        $config[$idx][$field] = (int)($request['value'] ?? 0);
        return $this->save($rid, $config);
    }

    private function setStatic(array $config, int $rid, array $request): array
    {
        $name = (string)($request['section'] ?? '');
        $value = (int)($request['value'] ?? 0);
        $idx = $this->indexOf($config, $name);
        if ($idx < 0) {
            return $this->err('section not found: ' . $name);
        }
        $config[$idx]['is_static'] = $value;

        // global = staticBlocksPage. Миграция контента секции.
        $gread = $this->mpcve->readConfig('global', $rid);
        $global = array_values((array)($gread['data']['config'] ?? []));
        $gidx = $this->indexOf($global, $name);

        if ($value === 1) {
            if ($gidx < 0) {
                $global[] = $config[$idx]; // копия контента в статик-базу
            }
        } elseif ($gidx >= 0) {
            array_splice($global, $gidx, 1); // убрать из статик-базы
        }

        $gsave = $this->mpcve->saveConfig('global', $rid, $global);
        if (empty($gsave['success'])) {
            return $this->err($gsave['message'] ?? 'static-base save failed');
        }
        return $this->save($rid, $config);
    }

    private function save(int $rid, array $config): array
    {
        $r = $this->mpcve->saveConfig('resource', $rid, $config);
        return empty($r['success'])
            ? $this->err($r['message'] ?? 'save failed')
            : ['success' => true, 'message' => 'ok', 'data' => []];
    }

    private function err(string $message): array
    {
        return ['success' => false, 'message' => $message, 'data' => []];
    }
}
