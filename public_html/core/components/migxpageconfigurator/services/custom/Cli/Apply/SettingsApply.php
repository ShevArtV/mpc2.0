<?php

namespace MpcServices\Cli\Apply;

/**
 * Идемпотентное применение системных настроек из манифеста (декларативно).
 * Формат записи: 'key' => 'value' ЛИБО 'key' => ['value'=>..,'xtype'=>..,
 * 'namespace'=>..,'area'=>..]. Сопоставление по key: есть → обновляем при
 * отличии значения, нет → создаём. Удаление настроек НЕ делаем (аддитивно).
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

        foreach ($manifest as $key => $spec) {
            $key = (string)$key;
            if ($key === '') {
                continue;
            }
            $spec = is_array($spec) ? $spec : ['value' => $spec];
            $value = self::normalizeValue($spec['value'] ?? '');

            $setting = $this->modx->getObject('modSystemSetting', ['key' => $key]);
            if ($setting) {
                if ((string)$setting->get('value') === $value) {
                    $plan[] = ['action' => 'skip', 'ref' => $key];
                    continue;
                }
                $plan[] = ['action' => 'update', 'ref' => $key];
                if (!$dryRun) {
                    $setting->set('value', $value);
                    $setting->save();
                    $changed++;
                }
            } else {
                $plan[] = ['action' => 'create', 'ref' => $key];
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
                    $changed++;
                }
            }
        }

        if (!$dryRun && $changed > 0) {
            $this->modx->getCacheManager()->refresh(['system_settings' => []]);
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
