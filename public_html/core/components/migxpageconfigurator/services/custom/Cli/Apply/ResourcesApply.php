<?php

namespace MpcServices\Cli\Apply;

/**
 * ЕДИНЫЙ движок идемпотентного применения дерева ресурсов. Используется и
 * проектным CLI (`resources apply`), и self-seed пакета (`Mpc::runResourceProcessor`
 * делегирует сюда) — одна логика, без дублей.
 *
 * Манифест: ['context'=>'web', 'resources'=>[ node, … ]]; дети узла — в ключе
 * 'children' ЛИБО 'resources' (совместимость со старым seed-форматом). Узел:
 * pagetitle (обязателен), alias (иначе из pagetitle), template (имя или id),
 * любые поля ресурса, 'tvs'=>[name=>val], 'groups'=>[name,…].
 *
 * Идентичность — context_key + pagetitle (как прежний manageResource, плюс
 * контекст). Найден → обновляем при отличии; нет → создаём. Удаление НЕ делаем.
 * После сохранения — render->copyConfig (наследование mpc_config типа), если
 * render передан. dry-run отдаёт план без записи.
 */
class ResourcesApply
{
    /** Контрольные ключи узла (не поля ресурса). */
    private const CONTROL_KEYS = ['children', 'resources', 'tvs', 'groups'];

    private \modX $modx;
    /** @var object|null Render для copyConfig (наследование конфига типа). */
    private $render;

    public function __construct(\modX $modx, $render = null)
    {
        $this->modx   = $modx;
        $this->render = $render;
    }

    public function apply(array $manifest, bool $dryRun = false, string $only = ''): array
    {
        $context = (string)($manifest['context'] ?? 'web');
        $tree    = $manifest['resources'] ?? [];
        if (!is_array($tree)) {
            return ['success' => false, 'message' => 'Манифест ресурсов: "resources" должен быть массивом'];
        }

        $plan = [];
        $errors = [];
        $this->walk($tree, 0, '', $context, $dryRun, $only, $plan, $errors);

        if (!$dryRun && !empty($plan)) {
            $this->modx->getCacheManager()->refresh(['resource' => ['contexts' => [$context]]]);
        }

        $c = $this->summarize($plan);
        $msg = ($dryRun ? 'План: ' : 'Готово: ')
            . sprintf('создать %d, обновить %d, без изменений %d', $c['create'], $c['update'], $c['skip']);
        if ($errors) {
            $msg .= '. Ошибки: ' . implode('; ', $errors);
        }
        return ['success' => empty($errors), 'message' => $msg, 'data' => ['plan' => $plan, 'errors' => $errors]];
    }

    private function childrenOf(array $node): array
    {
        if (!empty($node['children']) && is_array($node['children'])) {
            return $node['children'];
        }
        if (!empty($node['resources']) && is_array($node['resources'])) {
            return $node['resources'];
        }
        return [];
    }

    private function walk(array $nodes, int $parentId, string $parentUri, string $context, bool $dryRun, string $only, array &$plan, array &$errors): void
    {
        $menuindex = 0;
        foreach ($nodes as $node) {
            if (!is_array($node) || empty($node['pagetitle'])) {
                continue;
            }
            [$id, $uri] = $this->upsert($node, $parentId, $parentUri, $context, $menuindex++, $dryRun, $only, $plan, $errors);
            $children = $this->childrenOf($node);
            if ($children) {
                $this->walk($children, (int)$id, $uri . '/', $context, $dryRun, $only, $plan, $errors);
            }
        }
    }

    /** @return array{0:int,1:string} [id, uri] */
    private function upsert(array $node, int $parentId, string $parentUri, string $context, int $menuindex, bool $dryRun, string $only, array &$plan, array &$errors): array
    {
        $pagetitle = (string)$node['pagetitle'];
        $existing  = $this->modx->getObject('modResource', ['context_key' => $context, 'pagetitle' => $pagetitle]);

        $alias = (string)($node['alias'] ?? '');
        if ($alias === '') {
            $aliasSrc = $existing ?: $this->modx->newObject('modResource');
            $alias = $aliasSrc->cleanAlias($pagetitle);
        }
        $uri = $parentUri . $alias;
        $ref = $context . ':' . $uri;

        $fields = $this->collectFields($node, $context, $parentId, $alias, $uri, $menuindex, $this->childrenOf($node) !== []);
        $tvs    = is_array($node['tvs'] ?? null) ? $node['tvs'] : [];
        $groups = is_array($node['groups'] ?? null) ? $node['groups'] : [];

        $selected = $only === '' || $only === $pagetitle || $only === $alias || $only === $ref;

        if ($existing) {
            $id = (int)$existing->get('id');
            if (!$selected || (!$this->fieldDiff($existing, $fields) && !$this->tvDiff($existing, $tvs))) {
                $plan[] = ['action' => 'skip', 'ref' => $ref];
                return [$id, $uri];
            }
            $plan[] = ['action' => 'update', 'ref' => $ref];
            if (!$dryRun) {
                $existing->fromArray($fields, '', true, true);
                if (!$existing->save()) {
                    $errors[] = $ref . ': не удалось сохранить';
                    return [$id, $uri];
                }
                $this->afterSave($existing, $tvs, $groups);
            }
            return [$id, $uri];
        }

        if (!$selected) {
            return [0, $uri];
        }
        $plan[] = ['action' => 'create', 'ref' => $ref];
        if ($dryRun) {
            return [0, $uri];
        }
        $resource = $this->modx->newObject('modResource');
        $resource->fromArray(array_merge($fields, ['createdon' => time()]), '', true, true);
        if (!$resource->save()) {
            $errors[] = $ref . ': не удалось создать';
            return [0, $uri];
        }
        $this->afterSave($resource, $tvs, $groups);
        return [(int)$resource->get('id'), $uri];
    }

    /** Поля ресурса: дефолты + узел (минус контрольные) + вычисленные. */
    private function collectFields(array $node, string $context, int $parentId, string $alias, string $uri, int $menuindex, bool $isFolder): array
    {
        $data = $node;
        foreach (self::CONTROL_KEYS as $k) {
            unset($data[$k]);
        }
        if (isset($data['template'])) {
            $data['template'] = $this->resolveTemplate($data['template']);
        }
        return array_merge([
            'published'    => true,
            'deleted'      => false,
            'hidemenu'     => false,
            'isfolder'     => !empty($node['isfolder']) || $isFolder,
            'richtext'     => false,
            'searchable'   => true,
            'uri_override' => false,
        ], $data, [
            'context_key' => $context,
            'parent'      => $parentId,
            'alias'       => $alias,
            'uri'         => $uri,
            'menuindex'   => $menuindex,
        ]);
    }

    private function afterSave($resource, array $tvs, array $groups): void
    {
        foreach ($tvs as $name => $value) {
            $resource->setTVValue($name, $value);
        }
        foreach ($groups as $group) {
            $resource->joinGroup($group);
        }
        if ($this->render && method_exists($this->render, 'copyConfig')) {
            $this->render->copyConfig($resource);
        }
    }

    private function resolveTemplate($tpl): int
    {
        if (is_numeric($tpl)) {
            return (int)$tpl;
        }
        $t = $this->modx->getObject('modTemplate', ['templatename' => (string)$tpl]);
        return $t ? (int)$t->get('id') : 0;
    }

    /** Производные/волатильные поля — не сравниваем (MODX их нормализует/ставит сам). */
    private const DIFF_IGNORE = ['createdon', 'editedon', 'publishedon', 'uri'];

    private function fieldDiff($resource, array $fields): bool
    {
        foreach ($fields as $k => $v) {
            if (in_array($k, self::DIFF_IGNORE, true)) {
                continue;
            }
            if (self::norm($resource->get($k)) !== self::norm($v)) {
                return true;
            }
        }
        return false;
    }

    private function tvDiff($resource, array $tvs): bool
    {
        foreach ($tvs as $name => $value) {
            if (self::norm($resource->getTVValue($name)) !== self::norm($value)) {
                return true;
            }
        }
        return false;
    }

    private static function norm($v): string
    {
        if (is_bool($v)) {
            return $v ? '1' : '0';
        }
        return $v === null ? '' : (string)$v;
    }

    /** @return array{create:int,update:int,skip:int} */
    private function summarize(array $plan): array
    {
        $c = ['create' => 0, 'update' => 0, 'skip' => 0];
        foreach ($plan as $a) {
            if (isset($c[$a['action']])) {
                $c[$a['action']]++;
            }
        }
        return $c;
    }
}
