<?php

namespace MpcServices\Cli\Apply;

/**
 * Декларативная синхронизация привязок плагинов к системным событиям из
 * манифеста. Формат: 'ИмяПлагина' => ['Event1','Event2', …]. Для КАЖДОГО
 * указанного плагина приводим набор привязок к перечисленному: недостающие —
 * привязываем, лишние — отвязываем. Плагины вне манифеста не трогаем.
 */
class EventsApply
{
    private \modX $modx;

    public function __construct(\modX $modx)
    {
        $this->modx = $modx;
    }

    /**
     * Чистый diff желаемого и текущего набора событий. PURE.
     * @return array{bind:string[],unbind:string[],keep:string[]}
     */
    public static function diff(array $desired, array $current): array
    {
        $desired = array_values(array_unique(array_map('strval', $desired)));
        $current = array_values(array_unique(array_map('strval', $current)));
        return [
            'bind'   => array_values(array_diff($desired, $current)),
            'unbind' => array_values(array_diff($current, $desired)),
            'keep'   => array_values(array_intersect($desired, $current)),
        ];
    }

    public function apply(array $manifest, bool $dryRun = false): array
    {
        $plan = [];
        $errors = [];

        foreach ($manifest as $pluginName => $events) {
            $pluginName = (string)$pluginName;
            $plugin = $this->modx->getObject('modPlugin', ['name' => $pluginName]);
            if (!$plugin) {
                $errors[] = 'Плагин не найден: ' . $pluginName;
                continue;
            }
            $pluginId = (int)$plugin->get('id');

            $current = [];
            foreach ($this->modx->getCollection('modPluginEvent', ['pluginid' => $pluginId]) as $pe) {
                $current[] = (string)$pe->get('event');
            }
            $d = self::diff((array)$events, $current);

            foreach ($d['bind'] as $ev) {
                $plan[] = ['action' => 'bind', 'ref' => $pluginName . ' → ' . $ev];
                if (!$dryRun) {
                    // событие должно существовать в modEvent — иначе привязка не сработает
                    if (!$this->modx->getObject('modEvent', ['name' => $ev])) {
                        $errors[] = sprintf('Событие "%s" не зарегистрировано (плагин %s) — привязка пропущена', $ev, $pluginName);
                        continue;
                    }
                    $pe = $this->modx->newObject('modPluginEvent');
                    $pe->fromArray(['pluginid' => $pluginId, 'event' => $ev, 'priority' => 0, 'propertyset' => 0], '', true, true);
                    $pe->save();
                }
            }
            foreach ($d['unbind'] as $ev) {
                $plan[] = ['action' => 'unbind', 'ref' => $pluginName . ' → ' . $ev];
                if (!$dryRun) {
                    $pe = $this->modx->getObject('modPluginEvent', ['pluginid' => $pluginId, 'event' => $ev]);
                    if ($pe) {
                        $pe->remove();
                    }
                }
            }
        }

        if (!$dryRun && !empty($plan)) {
            // полный сброс — карта событий (и заодно настройки)
            $this->modx->getCacheManager()->refresh();
        }

        $bind = $unbind = 0;
        foreach ($plan as $a) {
            if ($a['action'] === 'bind') $bind++;
            if ($a['action'] === 'unbind') $unbind++;
        }
        $msg = ($dryRun ? 'План: ' : 'Готово: ') . sprintf('привязать %d, отвязать %d', $bind, $unbind);
        if ($errors) {
            $msg .= '. Предупреждения: ' . implode('; ', $errors);
        }

        return ['success' => empty($errors), 'message' => $msg, 'data' => ['plan' => $plan, 'errors' => $errors]];
    }
}
