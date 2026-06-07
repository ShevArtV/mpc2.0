<?php

namespace MpcServices\Cli\Apply;

/**
 * Идемпотентное применение настроек ClientConfig (cgSetting / cgContextValue)
 * из манифеста. Формат: 'key' => 'value' ЛИБО 'key' => ['value'=>..,'context'=>..,
 * 'xtype'=>..,'namespace'=>..,'area'=>..,'group'=>..].
 *   - без 'context' — базовое значение cgSetting.value;
 *   - с 'context'   — контекстное переопределение cgContextValue (нужна базовая
 *     cgSetting; создаётся при отсутствии);
 *   - 'group' (id или label/name) — группа для НОВОЙ cgSetting.
 * ClientConfig — extension package; если не установлен, возвращаем понятную ошибку.
 */
class ClientConfigApply
{
    private \modX $modx;

    public function __construct(\modX $modx)
    {
        $this->modx = $modx;
    }

    /** Доступен ли ClientConfig (классы cg*). Пытаемся подгрузить пакет. */
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

    public function apply(array $manifest, bool $dryRun = false): array
    {
        if (!$this->available()) {
            return ['success' => false, 'message' => 'ClientConfig не установлен (классы cgSetting недоступны)'];
        }

        $plan = [];
        $changed = 0;

        foreach ($manifest as $key => $spec) {
            $key = (string)$key;
            if ($key === '') {
                continue;
            }
            $spec = is_array($spec) ? $spec : ['value' => $spec];
            $value = SettingsApply::normalizeValue($spec['value'] ?? '');
            $context = (string)($spec['context'] ?? '');

            $action = $this->upsert($key, $value, $context, $spec, $dryRun);
            $plan[] = ['action' => $action, 'ref' => $context !== '' ? $context . '/' . $key : $key];
            if ($action !== 'skip' && !$dryRun) {
                $changed++;
            }
        }

        if (!$dryRun && $changed > 0) {
            $this->modx->getCacheManager()->refresh();
        }

        $counts = SettingsApply::summarize($plan);
        return [
            'success' => true,
            'message' => $dryRun
                ? sprintf('План: создать %d, обновить %d, без изменений %d', $counts['create'], $counts['update'], $counts['skip'])
                : sprintf('Готово: создано %d, обновлено %d, без изменений %d', $counts['create'], $counts['update'], $counts['skip']),
            'data'    => ['plan' => $plan],
        ];
    }

    /** @return string action (create|update|skip) */
    private function upsert(string $key, string $value, string $context, array $spec, bool $dryRun): string
    {
        $cg = $this->modx->getObject('cgSetting', ['key' => $key]);

        if ($context !== '') {
            if (!$cg) {
                // нет базовой настройки — создаём (контекстное значение ссылается на неё)
                if ($dryRun) {
                    return 'create';
                }
                $cg = $this->createSetting($key, $value, $spec);
            }
            $sid = (int)$cg->get('id');
            $cv = $this->modx->getObject('cgContextValue', ['setting_id' => $sid, 'context' => $context]);
            if ($cv) {
                if ((string)$cv->get('value') === $value) {
                    return 'skip';
                }
                if (!$dryRun) {
                    $cv->set('value', $value);
                    $cv->save();
                }
                return 'update';
            }
            if (!$dryRun) {
                $cv = $this->modx->newObject('cgContextValue');
                $cv->fromArray(['setting_id' => $sid, 'context' => $context, 'value' => $value], '', true, true);
                $cv->save();
            }
            return 'create';
        }

        // базовое значение cgSetting
        if ($cg) {
            if ((string)$cg->get('value') === $value) {
                return 'skip';
            }
            if (!$dryRun) {
                $cg->set('value', $value);
                $cg->save();
            }
            return 'update';
        }
        if (!$dryRun) {
            $this->createSetting($key, $value, $spec);
        }
        return 'create';
    }

    private function createSetting(string $key, string $value, array $spec)
    {
        $data = [
            'key'       => $key,
            'value'     => $value,
            'xtype'     => (string)($spec['xtype'] ?? 'textfield'),
            'namespace' => (string)($spec['namespace'] ?? 'clientconfig'),
            'area'      => (string)($spec['area'] ?? ''),
        ];
        if (isset($spec['group']) && ($gid = $this->resolveGroupId($spec['group'])) > 0) {
            $data['group_id'] = $gid;
        }
        $cg = $this->modx->newObject('cgSetting');
        $cg->fromArray($data, '', true, true);
        $cg->save();
        return $cg;
    }

    /** Группа по id или label/name → id (0 если не найдена). */
    private function resolveGroupId($group): int
    {
        if (is_numeric($group)) {
            return (int)$group;
        }
        $g = $this->modx->getObject('cgGroup', ['label' => (string)$group])
            ?: $this->modx->getObject('cgGroup', ['name' => (string)$group]);
        return $g ? (int)$g->get('id') : 0;
    }
}
