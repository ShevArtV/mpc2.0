<?php

namespace MpcServices\Cli\Apply;

/**
 * Декларативное применение плагинов из манифеста: создание/обновление самого
 * плагина (код из файла, категория, static) + синхронизация его привязок к
 * системным событиям. Заменил прежний `events apply` (был только синк событий)
 * и плагинную ветку legacy `manageElement`.
 *
 * Формат узла манифеста — 'ИмяПлагина' => spec, где spec:
 *   - полная спека плагина:
 *       [
 *         'file'         => 'plugin.myplugin', // файл в core/elements/plugins/<file>.php
 *         'description'  => '',
 *         'categoryName' => 'Моя категория',   // опц.
 *         'static'       => 1,                 // опц. (привязка к файлу)
 *         'events'       => ['OnDocFormSave', 'OnLoadWebDocument'],
 *       ]
 *   - ЛИБО просто список событий ['Event1','Event2'] — тогда плагин только
 *     синхронизируется по событиям (должен уже существовать; обратная
 *     совместимость со старым форматом events-манифеста).
 *
 * События приводятся РОВНО к указанному набору: недостающие привязываются,
 * лишние отвязываются. Плагины вне манифеста не трогаются.
 */
class PluginsApply
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

        foreach ($manifest as $pluginName => $spec) {
            $pluginName = (string)$pluginName;

            // Список событий ['Ev1','Ev2'] → только синк (плагин должен быть).
            if (is_array($spec) && $spec !== [] && array_keys($spec) === range(0, count($spec) - 1)) {
                $spec = ['events' => $spec];
            } elseif (!is_array($spec)) {
                $spec = [];
            }

            $events = $spec['events'] ?? [];
            // события могут быть списком имён ИЛИ assoc имя=>override — берём имена.
            $eventNames = ($events === [] || array_keys($events) === range(0, count($events) - 1))
                ? array_map('strval', $events)
                : array_map('strval', array_keys($events));

            $plugin = $this->modx->getObject('modPlugin', ['name' => $pluginName]);

            // --- создание/обновление плагина (если задан file) ---
            if (!empty($spec['file'])) {
                $codePath = $this->corePath() . 'elements/plugins/' . $spec['file'] . '.php';
                if (!is_file($codePath)) {
                    $errors[] = 'Файл плагина не найден: ' . $codePath . ' (' . $pluginName . ')';
                    continue;
                }
                $plan[] = ['action' => $plugin ? 'update' : 'create', 'ref' => 'plugin ' . $pluginName];
                if (!$dryRun) {
                    if (!$plugin) {
                        $plugin = $this->modx->newObject('modPlugin');
                    }
                    $fields = [
                        'name'        => $pluginName,
                        'description' => (string)($spec['description'] ?? ''),
                        'plugincode'  => file_get_contents($codePath),
                        'source'      => 1,
                    ];
                    if (!empty($spec['categoryName'])) {
                        $fields['category'] = $this->categoryId((string)$spec['categoryName']);
                    }
                    if (!empty($spec['static'])) {
                        $fields['static'] = 1;
                        $fields['static_file'] = 'core/elements/plugins/' . $spec['file'] . '.php';
                    }
                    $plugin->fromArray($fields, '', true, true);
                    if (!$plugin->save()) {
                        $errors[] = 'Не удалось сохранить плагин: ' . $pluginName;
                        continue;
                    }
                }
            } elseif (!$plugin) {
                $errors[] = 'Плагин не найден: ' . $pluginName . ' (укажите "file" для создания)';
                continue;
            }

            // --- синк событий ---
            // dry-run + плагина ещё нет → показать все события как bind, без id.
            if ($dryRun && !$plugin) {
                foreach ($eventNames as $ev) {
                    $plan[] = ['action' => 'bind', 'ref' => $pluginName . ' → ' . $ev];
                }
                continue;
            }

            $pluginId = (int)$plugin->get('id');
            $current = [];
            foreach ($this->modx->getCollection('modPluginEvent', ['pluginid' => $pluginId]) as $pe) {
                $current[] = (string)$pe->get('event');
            }
            $d = self::diff($eventNames, $current);

            foreach ($d['bind'] as $ev) {
                $plan[] = ['action' => 'bind', 'ref' => $pluginName . ' → ' . $ev];
                if (!$dryRun) {
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

        $c = ['create' => 0, 'update' => 0, 'bind' => 0, 'unbind' => 0];
        foreach ($plan as $a) {
            if (isset($c[$a['action']])) {
                $c[$a['action']]++;
            }
        }
        $msg = ($dryRun ? 'План: ' : 'Готово: ') . sprintf(
            'плагинов создать %d, обновить %d; событий привязать %d, отвязать %d',
            $c['create'],
            $c['update'],
            $c['bind'],
            $c['unbind']
        );
        if ($errors) {
            $msg .= '. Предупреждения: ' . implode('; ', $errors);
        }

        return ['success' => empty($errors), 'message' => $msg, 'data' => ['plan' => $plan, 'errors' => $errors]];
    }

    /** core_path с завершающим слэшем. */
    private function corePath(): string
    {
        return rtrim((string)$this->modx->getOption('core_path'), '/\\') . '/';
    }

    /** id категории по имени (создаёт, если нет). */
    private function categoryId(string $categoryName): int
    {
        $cat = $this->modx->getObject('modCategory', ['category' => $categoryName]);
        if ($cat) {
            return (int)$cat->get('id');
        }
        $response = $this->modx->runProcessor('element/category/create', [
            'parent'   => 0,
            'category' => $categoryName,
            'rank'     => 0,
        ]);
        if ($response->isError()) {
            return 0;
        }
        return (int)$response->response['object']['id'];
    }
}
