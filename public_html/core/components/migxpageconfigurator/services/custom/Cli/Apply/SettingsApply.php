<?php

namespace MpcServices\Cli\Apply;

/**
 * Идемпотентное применение настроек MODX из манифеста (декларативно).
 * Формат записи: 'key' => 'value' ЛИБО 'key' => ['value'=>..,'context'=>..,
 * 'xtype'=>..,'namespace'=>..,'area'=>..]. Без 'context' — системная настройка
 * (modSystemSetting); с 'context' — контекстная (modContextSetting, PK
 * context_key+key). Сопоставление по ключу: есть → обновляем при отличии, нет →
 * создаём. Удаление настроек НЕ делаем (аддитивно).
 */
class SettingsApply
{
    private \modX $modx;

    public function __construct(\modX $modx)
    {
        $this->modx = $modx;
    }

    /** Нормализация значения настройки в строку (как хранит MODX). PURE. */
    public static function normalizeValue($value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE);
        }
        return (string)$value;
    }

    /**
     * @return array ['success','message','data'=>['plan'=>[...]]]
     */
    public function apply(array $manifest, bool $dryRun = false): array
    {
        $plan = [];
        $changed = 0;
        $touchedContexts = [];

        foreach ($manifest as $key => $spec) {
            $key = (string)$key;
            if ($key === '') {
                continue;
            }
            $spec = is_array($spec) ? $spec : ['value' => $spec];
            $value = self::normalizeValue($spec['value'] ?? '');
            $context = (string)($spec['context'] ?? '');

            $action = $context !== ''
                ? $this->applyContext($key, $context, $value, $spec, $dryRun)
                : $this->applySystem($key, $value, $spec, $dryRun);

            $plan[] = [
                'action' => $action,
                'ref'    => $context !== '' ? $context . '/' . $key : $key,
            ];
            if ($action !== 'skip' && !$dryRun) {
                $changed++;
                if ($context !== '') {
                    $touchedContexts[$context] = true;
                }
            }
        }

        if (!$dryRun && $changed > 0) {
            $partitions = ['system_settings' => []];
            if ($touchedContexts) {
                $partitions['context_settings'] = ['contexts' => array_keys($touchedContexts)];
            }
            $this->modx->getCacheManager()->refresh($partitions);
        }

        $counts = $this->summarize($plan);
        return [
            'success' => true,
            'message' => $dryRun
                ? sprintf('План: создать %d, обновить %d, без изменений %d', $counts['create'], $counts['update'], $counts['skip'])
                : sprintf('Готово: создано %d, обновлено %d, без изменений %d', $counts['create'], $counts['update'], $counts['skip']),
            'data'    => ['plan' => $plan],
        ];
    }

    /** Upsert системной настройки. @return string action (create|update|skip) */
    private function applySystem(string $key, string $value, array $spec, bool $dryRun): string
    {
        $setting = $this->modx->getObject('modSystemSetting', ['key' => $key]);
        if ($setting) {
            if ((string)$setting->get('value') === $value) {
                return 'skip';
            }
            if (!$dryRun) {
                $setting->set('value', $value);
                $setting->save();
            }
            return 'update';
        }
        if (!$dryRun) {
            $setting = $this->modx->newObject('modSystemSetting');
            $setting->fromArray([
                'key'       => $key,
                'value'     => $value,
                'xtype'     => (string)($spec['xtype'] ?? 'textfield'),
                'namespace' => (string)($spec['namespace'] ?? 'core'),
                'area'      => (string)($spec['area'] ?? ''),
            ], '', true, true);
            $setting->save();
        }
        return 'create';
    }

    /** Upsert контекстной настройки (modContextSetting). @return string action */
    private function applyContext(string $key, string $context, string $value, array $spec, bool $dryRun): string
    {
        $setting = $this->modx->getObject('modContextSetting', ['context_key' => $context, 'key' => $key]);
        if ($setting) {
            if ((string)$setting->get('value') === $value) {
                return 'skip';
            }
            if (!$dryRun) {
                $setting->set('value', $value);
                $setting->save();
            }
            return 'update';
        }
        if (!$dryRun) {
            $setting = $this->modx->newObject('modContextSetting');
            $setting->fromArray([
                'context_key' => $context,
                'key'         => $key,
                'value'       => $value,
                'xtype'       => (string)($spec['xtype'] ?? 'textfield'),
                'namespace'   => (string)($spec['namespace'] ?? 'core'),
                'area'        => (string)($spec['area'] ?? ''),
            ], '', true, true);
            $setting->save();
        }
        return 'create';
    }

    /** @return array{create:int,update:int,skip:int} */
    public static function summarize(array $plan): array
    {
        $c = ['create' => 0, 'update' => 0, 'skip' => 0];
        foreach ($plan as $a) {
            $verb = $a['action'] ?? '';
            if (isset($c[$verb])) {
                $c[$verb]++;
            }
        }
        return $c;
    }
}
