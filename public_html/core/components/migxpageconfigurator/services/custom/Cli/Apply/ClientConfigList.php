<?php

namespace MpcServices\Cli\Apply;

/**
 * Список настроек ClientConfig (cgSetting), опционально по группе (id или
 * label/name группы cgGroup). ClientConfig — extension package; если не
 * установлен, возвращаем понятную ошибку.
 */
class ClientConfigList
{
    private \modX $modx;

    public function __construct(\modX $modx)
    {
        $this->modx = $modx;
    }

    private function available(): bool
    {
        if ($this->modx->loadClass('cgSetting') !== false) {
            return true;
        }
        $path = $this->modx->getOption('core_path') . 'components/clientconfig/model/';
        if (is_dir($path)) {
            $this->modx->addPackage('clientconfig', $path);
            return $this->modx->loadClass('cgSetting') !== false;
        }
        return false;
    }

    /**
     * @param string $group   id или label/name группы (пусто = все)
     * @param string $keyLike фильтр по части ключа (LIKE %...%; пусто = все)
     */
    public function list(string $group = '', string $keyLike = ''): array
    {
        if (!$this->available()) {
            return ['success' => false, 'message' => 'ClientConfig не установлен (классы cgSetting недоступны)'];
        }

        $criteria = [];
        $groupLabel = '';
        if ($group !== '') {
            $gid = $this->resolveGroupId($group, $groupLabel);
            if ($gid <= 0) {
                return ['success' => false, 'message' => "Группа ClientConfig не найдена: $group"];
            }
            $criteria['group_id'] = $gid;
        }
        if ($keyLike !== '') {
            $criteria['key:LIKE'] = '%' . $keyLike . '%';
        }

        $q = $this->modx->newQuery('cgSetting', $criteria);
        $q->sortby($this->modx->escape('key'), 'ASC'); // key — зарезервированное слово

        $rows = [];
        foreach ($this->modx->getCollection('cgSetting', $q) as $s) {
            $rows[] = [
                'key'      => $s->get('key'),
                'value'    => $s->get('value'),
                'group_id' => (int)$s->get('group_id'),
                'area'     => $s->get('area'),
            ];
        }

        return [
            'success' => true,
            'message' => sprintf(
                'Найдено настроек ClientConfig: %d%s%s',
                count($rows),
                $group !== '' ? " (группа $groupLabel)" : '',
                $keyLike !== '' ? " [ключ ~ $keyLike]" : ''
            ),
            'data' => ['settings' => $rows],
        ];
    }

    /** Группа по id или label/name → id (0 если не найдена); $label — для вывода. */
    private function resolveGroupId($group, string &$label): int
    {
        if (is_numeric($group)) {
            $g = $this->modx->getObject('cgGroup', (int)$group);
        } else {
            $g = $this->modx->getObject('cgGroup', ['label' => (string)$group])
                ?: $this->modx->getObject('cgGroup', ['name' => (string)$group]);
        }
        if (!$g) {
            return 0;
        }
        $label = (string)($g->get('label') ?: $g->get('name'));
        return (int)$g->get('id');
    }
}
