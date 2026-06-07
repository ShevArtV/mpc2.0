<?php

namespace MpcServices\Cli\Apply;

/**
 * Список настроек MODX: системных (modSystemSetting) или контекстных
 * (modContextSetting при заданном context), с опциональным фильтром по namespace.
 */
class SettingsList
{
    private \modX $modx;

    public function __construct(\modX $modx)
    {
        $this->modx = $modx;
    }

    /**
     * @param string $namespace фильтр по namespace (пусто = все)
     * @param string $context   контекст; пусто = системные настройки
     * @param string $keyLike   фильтр по части ключа (LIKE %...%; пусто = все)
     */
    public function list(string $namespace = '', string $context = '', string $keyLike = ''): array
    {
        $class = $context !== '' ? 'modContextSetting' : 'modSystemSetting';

        $criteria = [];
        if ($context !== '') {
            $criteria['context_key'] = $context;
        }
        if ($namespace !== '') {
            $criteria['namespace'] = $namespace;
        }
        if ($keyLike !== '') {
            $criteria['key:LIKE'] = '%' . $keyLike . '%';
        }

        $q = $this->modx->newQuery($class, $criteria);
        $q->sortby($this->modx->escape('key'), 'ASC'); // key — зарезервированное слово

        $rows = [];
        foreach ($this->modx->getCollection($class, $q) as $s) {
            $row = [
                'key'       => $s->get('key'),
                'value'     => $s->get('value'),
                'namespace' => $s->get('namespace'),
                'area'      => $s->get('area'),
            ];
            if ($context !== '') {
                $row['context'] = $s->get('context_key');
            }
            $rows[] = $row;
        }

        return [
            'success' => true,
            'message' => sprintf(
                'Найдено настроек: %d%s%s%s',
                count($rows),
                $context !== '' ? " (контекст $context)" : ' (системные)',
                $namespace !== '' ? " [namespace $namespace]" : '',
                $keyLike !== '' ? " [ключ ~ $keyLike]" : ''
            ),
            'data' => ['settings' => $rows],
        ];
    }
}
