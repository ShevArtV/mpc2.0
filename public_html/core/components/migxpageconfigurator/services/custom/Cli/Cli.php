<?php

namespace MpcServices\Cli;

use MpcServices\Cli\Apply\SettingsApply;
use MpcServices\Cli\Apply\SettingsList;
use MpcServices\Cli\Apply\ClientConfigApply;
use MpcServices\Cli\Apply\ClientConfigList;
use MpcServices\Cli\Apply\PluginsApply;
use MpcServices\Cli\Apply\ResourcesApply;
use MpcServices\Cli\Apply\PackagesApply;

/**
 * Диспетчер mpc-CLI. Декларативный подход: проектный манифест (PHP return array)
 * приводит состояние админки к желаемому одной командой (idempotent apply).
 *
 *   php console/mpc.php <группа> apply <файл> [--dry-run] [--force] [--only=ref] [--json]
 *
 * Группы: resources, settings, plugins, packages, lexicon, help.
 */
class Cli
{
    private \modX $modx;
    /** @var \MpcServices\Mpc|null */
    private $mpc = null;

    public function __construct(\modX $modx)
    {
        $this->modx = $modx;
    }

    /** Ленивый сервис Mpc (нарезка/элементы/кэш/render). */
    private function mpc(): \MpcServices\Mpc
    {
        if ($this->mpc === null) {
            $this->mpc = new \MpcServices\Mpc($this->modx);
        }
        return $this->mpc;
    }

    /**
     * Переключение активного контекста перед web-операциями (нарезка/элементы/
     * кэш). Бутстрап инициализирует mgr (нужен sudo/процессорам ресурсов), но
     * Mpc::process/render опираются на $modx->context — как старый mgr_tpl,
     * который шёл в web. Вызывать ДО первого mpc().
     */
    private function useContext(array $opts): bool
    {
        $ctx = (string)($opts['ctx'] ?? 'web');
        return $this->modx->switchContext($ctx);
    }

    public function run(array $argv): int
    {
        $p = ArgvParser::parse($argv);
        $out = new Output(!empty($p['opts']['json']), !empty($p['opts']['quiet']));

        $group  = $p['group'];
        $action = $p['action'];
        $args   = $p['args'];
        $opts   = $p['opts'];

        if ($group === '' || $group === 'help' || !empty($opts['help'])) {
            $out->info($this->usage());
            return 0;
        }

        try {
            switch ($group) {
                case 'settings':
                    return $this->settings($action, $args, $opts, $out);
                case 'clientconfig':
                    return $this->clientConfig($action, $args, $opts, $out);
                case 'plugins':
                case 'resources':
                case 'packages':
                    return $this->applyGroup($group, $action, $args, $opts, $out);
                case 'cut':
                    return $this->cut($action, $args, $opts, $out);
                case 'configs':
                    return $this->configs($action, $args, $opts, $out);
                case 'cache':
                    return $this->cache($action, $args, $opts, $out);
                case 'lexicon':
                    return $this->lexicon($action, $args, $opts, $out);
                default:
                    $out->info('Неизвестная группа: ' . $group . PHP_EOL . $this->usage());
                    return 1;
            }
        } catch (\Throwable $e) {
            return $out->result(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    private function applyGroup(string $group, string $action, array $args, array $opts, Output $out): int
    {
        if ($action !== 'apply') {
            return $out->result(['success' => false, 'message' => "Поддерживается только: $group apply [файл|имя] [--dry-run] [--force] [--only=ref]"]);
        }
        // Путь манифеста: без аргумента — дефолт по группе ({base}/{group}.php),
        // короткое имя — профиль ({base}/{имя}.php), полный путь — как есть.
        $file = ManifestLoader::resolve($this->modx, $group, (string)($args[0] ?? ''));
        if (!is_file($file)) {
            return $out->result(['success' => false, 'message' =>
                "Манифест не найден: $file" . PHP_EOL .
                "Положите файл в базу манифестов (mpc_manifests_path) или укажите путь явно: $group apply <файл>"]);
        }
        $manifest = ManifestLoader::load($file);

        $dryRun = !empty($opts['dry-run']);
        $force  = !empty($opts['force']);
        $only   = (string)($opts['only'] ?? '');

        switch ($group) {
            case 'plugins':
                $res = (new PluginsApply($this->modx))->apply($manifest, $dryRun);
                break;
            case 'resources':
                // render → copyConfig (наследование mpc_config типа)
                $res = (new ResourcesApply($this->modx, $this->mpc()->render))->apply($manifest, $dryRun, $only);
                break;
            case 'packages':
                $res = (new PackagesApply($this->modx))->apply($manifest, $dryRun, $force);
                break;
            default:
                $res = ['success' => false, 'message' => 'неизвестная группа'];
        }

        if (!empty($res['data']['plan'])) {
            $out->plan($res['data']['plan']);
        }
        return $out->result($res);
    }

    /**
     * Настройки MODX: системные (modSystemSetting) и контекстные (modContextSetting).
     *   settings apply [файл]   — per-key 'context' в манифесте → контекстная;
     *   settings list [--namespace=ns] [--context=web] — список.
     */
    private function settings(string $action, array $args, array $opts, Output $out): int
    {
        if ($action === 'list') {
            $res = (new SettingsList($this->modx))->list(
                (string)($opts['namespace'] ?? ''),
                (string)($opts['context'] ?? ''),
                (string)($opts['key'] ?? '')
            );
            if (!empty($res['data']['settings'])) {
                $out->settings($res['data']['settings']);
            }
            return $out->result($res);
        }
        if ($action === 'apply') {
            $file = ManifestLoader::resolve($this->modx, 'settings', (string)($args[0] ?? ''));
            if (!is_file($file)) {
                return $out->result(['success' => false, 'message' =>
                    "Манифест не найден: $file" . PHP_EOL .
                    'Положите файл в базу манифестов (mpc_manifests_path) или укажите путь явно.']);
            }
            $manifest = ManifestLoader::load($file);
            $res = (new SettingsApply($this->modx))->apply($manifest, !empty($opts['dry-run']));
            if (!empty($res['data']['plan'])) {
                $out->plan($res['data']['plan']);
            }
            return $out->result($res);
        }
        return $out->result(['success' => false, 'message' =>
            'settings apply [файл] [--dry-run]  |  settings list [--namespace=ns] [--context=web] [--json]']);
    }

    /**
     * Настройки ClientConfig (cgSetting / cgContextValue).
     *   clientconfig apply [файл]  — per-key 'context'/'group' в манифесте;
     *   clientconfig list [--group=имя|id] — список настроек по группе.
     */
    private function clientConfig(string $action, array $args, array $opts, Output $out): int
    {
        if ($action === 'list') {
            $res = (new ClientConfigList($this->modx))->list(
                (string)($opts['group'] ?? ''),
                (string)($opts['key'] ?? '')
            );
            if (!empty($res['data']['settings'])) {
                $out->settings($res['data']['settings']);
            }
            return $out->result($res);
        }
        if ($action === 'apply') {
            $file = ManifestLoader::resolve($this->modx, 'clientconfig', (string)($args[0] ?? ''));
            if (!is_file($file)) {
                return $out->result(['success' => false, 'message' =>
                    "Манифест не найден: $file" . PHP_EOL .
                    'Положите файл в базу манифестов (mpc_manifests_path) или укажите путь явно.']);
            }
            $manifest = ManifestLoader::load($file);
            $res = (new ClientConfigApply($this->modx))->apply($manifest, !empty($opts['dry-run']));
            if (!empty($res['data']['plan'])) {
                $out->plan($res['data']['plan']);
            }
            return $out->result($res);
        }
        return $out->result(['success' => false, 'message' =>
            'clientconfig apply [файл] [--dry-run]  |  clientconfig list [--group=имя] [--json]']);
    }

    /** Нарезка шаблона/страницы: mpc cut <file|all> [--upd] [--force] [--ctx=web]. */
    private function cut(string $action, array $args, array $opts, Output $out): int
    {
        // действие необязательно: cut <file> ИЛИ cut all
        $target = $action !== '' ? $action : ($args[0] ?? '');
        if ($target === '') {
            return $out->result(['success' => false, 'message' => 'cut <файл.tpl|all> [--upd] [--force]']);
        }
        if (!$this->useContext($opts)) {
            return $out->result(['success' => false, 'message' => 'Не удалось переключиться в контекст ' . ($opts['ctx'] ?? 'web')]);
        }
        $isAll = $target === 'all';
        // Два режима нарезки: без --upd — нарезка + умный мерж (правки сохраняются);
        // с --upd — нарезка + полная перезапись контента/переводов из вёрстки. Сам
        // флаг --upd и есть осознанный выбор перезаписи — доп. подтверждения не нужно.
        $upd = !empty($opts['upd']) ? 1 : '';
        $ctxKey = $this->modx->context ? $this->modx->context->get('key') : '?';
        if (!empty($opts['dry-run'])) {
            return $out->result(['success' => true, 'message' => sprintf(
                'dry-run: нарезал бы «%s» в контексте %s%s (без --dry-run выполнится)',
                $isAll ? 'all' : $target, $ctxKey, $upd ? ', с обновлением контента' : ''
            )]);
        }
        $fileName = $isAll ? null : $target;
        $this->mpc()->process($fileName, $upd);
        return $out->result(['success' => true, 'message' => 'Нарезка выполнена: ' . ($isAll ? 'all' : $target)
            . ($upd ? ' (с обновлением контента)' : '') . ' [контекст ' . $ctxKey . ']']);
    }

    /** Синхронизация MIGX-конфигов из сида (только sync): mpc configs sync. */
    private function configs(string $action, array $args, array $opts, Output $out): int
    {
        if ($action !== 'sync') {
            return $out->result(['success' => false, 'message' => 'configs sync — применить сид migx_configs.json (merge: новые поля + сохранение правок)']);
        }
        $file = $this->modx->getOption('core_path')
            . 'components/migxpageconfigurator/elements/configs/migx_configs.json';
        $configs = is_file($file) ? json_decode((string)file_get_contents($file), true) : [];
        if (!$configs) {
            return $out->result(['success' => false, 'message' => 'Сид migx_configs.json пуст или не найден']);
        }
        $this->modx->addPackage('migx', $this->modx->getOption('core_path') . 'components/migx/model/');
        $merger = class_exists('MpcServices\\Helpers\\MigxConfigMerger') ? new \MpcServices\Helpers\MigxConfigMerger() : null;
        $synced = 0;
        foreach ($configs as $config) {
            $existing = $this->modx->getObject('migxConfig', ['name' => $config['name']]);
            $row = $config;
            unset($row['id']);
            if ($existing && $merger !== null) {
                $row = $merger->merge($row, $existing->toArray());
            }
            if (!$existing) {
                $existing = $this->modx->newObject('migxConfig');
            }
            $existing->fromArray($row, '', true);
            if ($existing->save()) {
                $synced++;
            }
        }
        return $out->result(['success' => true, 'message' => "Синхронизировано конфигов: $synced", 'data' => ['synced' => $synced]]);
    }

    /** Очистка запечённых mpc-шаблонов: mpc cache clear [ids] [--force]. */
    private function cache(string $action, array $args, array $opts, Output $out): int
    {
        if ($action !== 'clear') {
            return $out->result(['success' => false, 'message' => 'cache clear [id,id,…] — очистка parsed/ (без id — все)']);
        }
        $this->useContext($opts);
        // Очистка parsed/ безопасна (файлы регенерируются лениво) → force не нужен.
        $ids = (string)($args[0] ?? ($opts['ids'] ?? ''));
        $this->mpc()->render->clearCache($ids);
        return $out->result(['success' => true, 'message' => $ids !== '' ? "Очищены parsed: $ids" : 'Очищены все parsed-файлы']);
    }

    /** Лексиконы через существующие процессоры. */
    private function lexicon(string $action, array $args, array $opts, Output $out): int
    {
        $pp = ['processors_path' => $this->modx->getOption('core_path') . 'components/migxpageconfigurator/processors/'];

        switch ($action) {
            case 'export-all':
                $r = $this->modx->runProcessor('lexicons/exportallinone', [
                    'languages' => (string)($opts['languages'] ?? ''),
                ], $pp);
                return $out->result($this->fromProcessor($r, 'Экспорт готов'));
            case 'export-untranslated':
                $r = $this->modx->runProcessor('lexicons/export', [
                    'filename'     => (string)($opts['filename'] ?? ($args[0] ?? '')),
                    'languages'    => (string)($opts['languages'] ?? ''),
                    'untranslated' => 1,
                ], $pp);
                return $out->result($this->fromProcessor($r, 'Экспорт непереведённых готов'));
            case 'list':
                $r = $this->modx->runProcessor('lexicons/getlist', [], $pp);
                return $out->result($this->fromProcessor($r, ''));
            default:
                return $out->result(['success' => false, 'message' => 'lexicon: export-all | export-untranslated [filename] | list']);
        }
    }

    private function fromProcessor($resp, string $okMsg): array
    {
        if (!is_object($resp)) {
            return ['success' => false, 'message' => 'процессор не вернул ответ'];
        }
        $data = json_decode($resp->getResponse(), true);
        if (is_array($data) && array_key_exists('success', $data)) {
            return [
                'success' => (bool)$data['success'],
                'message' => $data['message'] !== '' ? $data['message'] : $okMsg,
                'data'    => $data['object'] ?? ($data['results'] ?? []),
            ];
        }
        return ['success' => !$resp->isError(), 'message' => $okMsg, 'data' => $data];
    }

    private function usage(): string
    {
        return implode(PHP_EOL, [
            'mpc CLI — декларативное управление MODX из проектных манифестов',
            '',
            'Использование:',
            '  ./console/mpc <группа> apply [файл|имя] [--dry-run] [--force] [--only=ref] [--json]',
            '',
            'Манифест: без аргумента берётся {base}/<группа>.php, короткое имя — {base}/<имя>.php,',
            'полный путь — как есть. База {base} = env MPC_MANIFESTS_PATH > настройка mpc_manifests_path',
            '> console/manifests/. Запуск: ./console/mpc (env MPC_PHP — путь к нужному php).',
            '',
            'Группы:',
            '  resources apply [файл]   — дерево ресурсов (idempotent, матч по context+pagetitle)',
            '  settings  apply [файл]   — настройки MODX: системные + контекстные (per-key "context")',
            '  settings  list [--namespace=ns] [--context=web] [--key=часть]   — список настроек',
            '  clientconfig apply [файл]   — настройки ClientConfig (cgSetting/cgContextValue)',
            '  clientconfig list [--group=имя] [--key=часть]   — список настроек ClientConfig',
            '  plugins   apply [файл]   — создать/обновить плагины (код+категория+static) и синк событий',
            '  packages  apply [файл]   — установка/удаление пакетов (нужен --force)',
            '  cut <файл.tpl|all> [--upd]   — нарезка: без --upd умный мерж, с --upd полная перезапись',
            '  configs sync             — применить сид MIGX-конфигов (merge)',
            '  cache clear [id,…]       — очистить запечённые parsed/ (без id — все)',
            '  lexicon   export-all | export-untranslated <filename> | list',
            '',
            'Флаги: --dry-run (только план), --force (деструктив), --only=ref (точечно), --json',
        ]);
    }
}
