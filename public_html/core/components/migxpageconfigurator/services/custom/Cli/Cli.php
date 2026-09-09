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
                case 'theme':
                    return $this->theme($action, $args, $opts, $out);
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
        // --theme=<name>: нарезать ВЁРСТКУ темы (исходник из templates/<subdir>/<name>/,
        // выхлоп в sections/<subdir>/<name>/), не трогая контент. Grabber/Render не
        // запускаются; --upd в этом режиме неприменим (контент не пишется).
        $theme = trim((string)($opts['theme'] ?? ''));
        if ($theme !== '' && (strpbrk($theme, '/\\') !== false || strpos($theme, '..') !== false)) {
            return $out->result(['success' => false, 'message' => 'Имя темы (--theme) без / \\ и ..']);
        }
        $ctxKey = $this->modx->context ? $this->modx->context->get('key') : '?';
        if (!empty($opts['dry-run'])) {
            return $out->result(['success' => true, 'message' => sprintf(
                'dry-run: нарезал бы «%s» в контексте %s%s (без --dry-run выполнится)',
                $isAll ? 'all' : $target, $ctxKey,
                $theme !== '' ? ', вёрстку темы «' . $theme . '» (контент не трогается)'
                    : ($upd ? ', с обновлением контента' : '')
            )]);
        }
        $fileName = $isAll ? null : $target;
        $res = $this->mpc()->process($fileName, $upd, $theme);
        if (empty($res['success'])) {
            // Резать было нечего: файл не найден/пуст (или для all нет шаблонов).
            $why = !empty($res['messages']) ? ' — ' . implode('; ', $res['messages'])
                : ($isAll ? ' — не найдено файлов шаблонов' : ' (файл не найден или пуст)');
            return $out->result(['success' => false, 'message' =>
                'Нарезка не выполнена: ' . ($isAll ? 'all' : $target) . $why . ' [контекст ' . $ctxKey . ']']);
        }
        return $out->result(['success' => true, 'message' => sprintf(
            'Нарезка выполнена: %s (файлов: %d%s)%s [контекст %s]',
            $isAll ? 'all' : $target,
            (int)$res['ok'],
            !empty($res['failed']) ? '; с ошибками: ' . (int)$res['failed'] : '',
            $theme !== '' ? ' (вёрстка темы «' . $theme . '», контент не тронут)'
                : ($upd ? ' (с обновлением контента)' : ''),
            $ctxKey
        )]);
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

    /**
     * Переключение темы оформления + инвалидация parsed/:
     *   mpc theme set <name> [--template=ID]   — включить тему (на весь сайт или
     *                                            для шаблона ID; приоритет шаблона)
     *   mpc theme clear [--template=ID]         — сбросить на базовую вёрстку
     *   mpc theme status                        — показать текущее состояние
     * Смена настройки чистит parsed (для --template — только ресурсов шаблона).
     */
    private function theme(string $action, array $args, array $opts, Output $out): int
    {
        $this->useContext($opts);
        $tpl = isset($opts['template']) ? (int)$opts['template'] : 0;

        if ($action === 'status') {
            $map = json_decode($this->getSetting('mpc_theme_templates') ?: '{}', true);
            return $out->result(['success' => true, 'message' => sprintf(
                'Тема (mpc_theme): «%s»; по шаблонам: %s; папка тем: %s',
                $this->getSetting('mpc_theme') ?: '— базовая',
                is_array($map) && $map ? json_encode($map, JSON_UNESCAPED_UNICODE) : '—',
                $this->getSetting('mpc_themes_subdir') ?: '_themes/'
            ), 'data' => ['theme' => $this->getSetting('mpc_theme'), 'templates' => $map]]);
        }

        if ($action === 'set') {
            $name = trim((string)($args[0] ?? ''));
            if ($name === '' || strpbrk($name, '/\\') !== false || strpos($name, '..') !== false) {
                return $out->result(['success' => false, 'message' =>
                    'theme set <name> [--template=ID] — имя темы без / \\ и .. (папка внутри ' .
                    ($this->getSetting('mpc_themes_subdir') ?: '_themes/') . ')']);
            }
            if ($tpl > 0) {
                $this->setTemplateTheme($tpl, $name);
                $msg = "Шаблону $tpl назначена тема «$name»";
            } else {
                $this->setSetting('mpc_theme', $name);
                $msg = "Тема на весь сайт: «$name»";
            }
            $this->afterThemeChange($tpl);
            return $out->result(['success' => true, 'message' => $msg . ' (parsed очищены)']);
        }

        if ($action === 'clear') {
            if ($tpl > 0) {
                $this->setTemplateTheme($tpl, '');
                $msg = "Шаблон $tpl сброшен на базовую вёрстку";
            } else {
                $this->setSetting('mpc_theme', '');
                $msg = 'Тема на весь сайт сброшена (базовая вёрстка)';
            }
            $this->afterThemeChange($tpl);
            return $out->result(['success' => true, 'message' => $msg . ' (parsed очищены)']);
        }

        return $out->result(['success' => false, 'message' =>
            'theme set <name> [--template=ID] | theme clear [--template=ID] | theme status']);
    }

    /** Значение системной настройки (пусто, если её нет). */
    private function getSetting(string $key): string
    {
        $s = $this->modx->getObject('modSystemSetting', ['key' => $key]);
        return $s ? (string)$s->get('value') : '';
    }

    /** Записать системную настройку (создать, если отсутствует). */
    private function setSetting(string $key, string $value): void
    {
        $s = $this->modx->getObject('modSystemSetting', ['key' => $key]);
        if (!$s) {
            $s = $this->modx->newObject('modSystemSetting');
            $s->fromArray(['key' => $key, 'namespace' => 'migxpageconfigurator', 'area' => 'mpc_paths', 'xtype' => 'textfield'], '', true, true);
        }
        $s->set('value', $value);
        $s->save();
    }

    /** Установить/снять тему шаблона в карте mpc_theme_templates (пустая тема — удалить ключ). */
    private function setTemplateTheme(int $tpl, string $theme): void
    {
        $map = json_decode($this->getSetting('mpc_theme_templates') ?: '{}', true);
        if (!is_array($map)) {
            $map = [];
        }
        if ($theme === '') {
            unset($map[(string)$tpl]);
        } else {
            $map[(string)$tpl] = $theme;
        }
        $this->setSetting('mpc_theme_templates', $map ? json_encode($map, JSON_UNESCAPED_UNICODE) : '');
    }

    /** Рефреш кэша настроек + чистка parsed (для --template — только ресурсов шаблона). */
    private function afterThemeChange(int $tpl): void
    {
        if ($cm = $this->modx->getCacheManager()) {
            $cm->refresh(['system_settings' => []]);
        }
        if ($tpl > 0) {
            $q = $this->modx->newQuery('modResource', ['template' => $tpl]);
            $q->select('id');
            $q->prepare();
            $q->stmt->execute();
            $ids = $q->stmt->fetchAll(\PDO::FETCH_COLUMN);
            if (!$ids) {
                return; // у шаблона нет ресурсов — чистить нечего (не сносим всё)
            }
            $this->mpc()->render->clearCache(implode(',', $ids));
            return;
        }
        $this->mpc()->render->clearCache();
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
            case 'plan':
                return $this->lexiconPlan((string)($args[0] ?? ''), $opts, false, $out);
            case 'apply':
                return $this->lexiconPlan((string)($args[0] ?? ''), $opts, true, $out);
            case 'release-plan':
                return $this->lexiconRelease((string)($args[0] ?? ''), $opts, false, $out);
            case 'release-apply':
                return $this->lexiconRelease((string)($args[0] ?? ''), $opts, true, $out);
            case 'snapshot-take':
                return $this->lexiconSnapshotTake($opts, $out);
            case 'release-new':
                return $this->lexiconReleaseNew($opts, $out);
            case 'prune':
                return $this->lexiconPrune($opts, $out);
            default:
                return $out->result([
                    'success' => false,
                    'message' => 'lexicon: export-all | export-untranslated [filename] | list'
                        . ' | plan <файл.xlsx|zip> | apply <файл.xlsx|zip> --force'
                        . ' | snapshot-take [--langs=de,fi] [--rids=aula]'
                        . ' | release-new (--snapshot=id|--base=каталог) [--out=manifest.json]'
                        . ' | release-plan <manifest.json> | release-apply <manifest.json> --force'
                        . ' | prune --lang=ru',
                ]);
        }
    }

    /**
     * Импорт книги из CI/CD. Тот же трёхсторонний план, что в админке
     * (LexiconBatchService), поэтому релиз и менеджер получают одинаковый
     * результат. Без --force идёт только план: запись — явное действие.
     */
    private function lexiconPlan(string $path, array $opts, bool $write, Output $out): int
    {
        if ($path === '' || !is_file($path)) {
            return $out->result(['success' => false, 'message' => 'нужен путь к файлу книги: mpc lexicon plan <файл.xlsx|zip>']);
        }
        $corePath = $this->modx->getOption('migxpageconfigurator_core_path', null,
            $this->modx->getOption('core_path') . 'components/migxpageconfigurator/');
        require_once $corePath . 'services/vendor/autoload.php';

        $service = \MpcServices\Handlers\Lexicon\LexiconBatchService::fromModx($this->modx);
        $sheets  = \MpcServices\Handlers\Lexicon\WorkbookReader::read($path, sys_get_temp_dir());
        if (empty($sheets)) {
            return $out->result(['success' => false, 'message' => 'книга не прочитана или пуста']);
        }

        $snapshotId = \MpcServices\Handlers\Lexicon\LexiconBatchService::snapshotIdFrom($sheets);
        $plan       = $service->planImport($service->desiredFromPlan($service->sheetPlan($sheets)), $snapshotId);
        if ($plan['error'] !== '') {
            return $out->result([
                'success' => false,
                'message' => \MpcServices\Handlers\Lexicon\LexiconBatchService::snapshotErrorText($plan['error']),
            ]);
        }

        $data = ['summary' => $plan['summary'], 'conflicts' => $plan['conflicts'], 'snapshot' => $plan['snapshot']];
        if (!$write || empty($opts['force'])) {
            return $out->result([
                'success' => true,
                'message' => $write ? 'план построен; запись требует --force' : 'план построен',
                'data'    => $data,
            ]);
        }

        // Конфликты по умолчанию НЕ решаются автоматически: молчаливый выбор
        // стороны в релизе — это ровно тот сценарий, из-за которого правки
        // менеджера и терялись. Разрешить одну сторону можно только явно.
        $decision   = (string)($opts['conflicts'] ?? '');
        $resolutions = [];
        if ($decision === 'mine' || $decision === 'server') {
            foreach ($plan['conflicts'] as $op) {
                $resolutions[\MpcServices\Handlers\Lexicon\LexiconBatchService::address($op)] = $decision;
            }
        }
        $res = $service->apply($plan['ops'], $resolutions, ['tag' => 'cli-import']);

        $this->modx->getCacheManager()->refresh(['lexicon_topics' => []]);
        return $out->result([
            'success' => empty($res['failed']),
            'message' => 'записано: ' . (int)$res['applied'] . ', очищено: ' . (int)$res['cleared']
                . ', конфликтов: ' . count($res['conflicts']) . ', устаревших: ' . count($res['stale']),
            'data'    => $res + $data,
        ]);
    }

    /**
     * Применение task-manifest в CI. Формат намеренно содержит только
     * `expected` и `desired`: отсутствие адреса ничего не удаляет.
     */
    private function lexiconRelease(string $path, array $opts, bool $write, Output $out): int
    {
        if ($path === '' || !is_file($path)) {
            return $out->result(['success' => false, 'message' => 'нужен release manifest: mpc lexicon release-plan <manifest.json>']);
        }
        $manifest = json_decode((string)file_get_contents($path), true);
        if (!is_array($manifest) || !is_array($manifest['expected'] ?? null) || !is_array($manifest['desired'] ?? null)) {
            return $out->result(['success' => false, 'message' => 'release manifest должен содержать expected и desired']);
        }

        $service = \MpcServices\Handlers\Lexicon\LexiconBatchService::fromModx($this->modx);
        $apply   = $write && !empty($opts['force']);
        $release = $service->release($manifest, basename($path), $apply);

        // Манифест из прошлого релиза: его база уже перекрыта следующей
        // принятой правкой. Повтор деплоя не обязан на этом останавливаться.
        if (!empty($release['skipped'])) {
            return $out->result([
                'success' => true,
                'message' => 'release уже доставлен ' . (string)($release['ledger']['applied_at'] ?? '') . ', пропущен',
                'data'    => ['skipped' => true, 'fingerprint' => $release['fingerprint'], 'ledger' => $release['ledger']],
            ]);
        }

        $plan = $release['plan'];
        $data = [
            'summary'     => $plan['summary'],
            'conflicts'   => $plan['conflicts'],
            'fingerprint' => $release['fingerprint'],
        ];
        if (!empty($plan['conflicts'])) {
            return $out->result([
                'success' => false,
                'message' => $apply ? 'release остановлен: есть конфликты' : 'release-план построен: есть конфликты',
                'data'    => $data,
            ]);
        }
        if (!$apply) {
            return $out->result(['success' => true, 'message' => 'release-план построен', 'data' => $data]);
        }

        $result = $release['result'];
        $this->modx->getCacheManager()->refresh(['lexicon_topics' => []]);
        $ok = empty($result['failed']) && empty($result['stale']) && empty($result['aborted']);
        return $out->result([
            'success' => $ok,
            'message' => $ok
                ? 'release применён: ' . (int)$result['applied']
                : 'release не применён: устаревших ' . count((array)$result['stale'])
                    . ', сбоев записи ' . count((array)$result['failed'])
                    . ', откачено файлов ' . count((array)$result['rolledBack']),
            'data' => $result + $data,
        ]);
    }

    /**
     * Зафиксировать базу перед работой над задачей. Снимок — то состояние, от
     * которого потом отсчитывается правка: без него `release-new` не отличит
     * «я изменил текст» от «текст на сервере и так был другим», и в `expected`
     * уехало бы текущее значение, а вместе с ним затёрлась бы правка менеджера.
     */
    private function lexiconSnapshotTake(array $opts, Output $out): int
    {
        $service = $this->lexiconService();
        $store   = $service->store();

        $langs = self::listOpt($opts['langs'] ?? '') ?: $store->languages();
        $rids  = self::listOpt($opts['rids'] ?? '');
        if (!$rids) {
            $seen = [];
            foreach ($langs as $lang) {
                foreach ($store->existingRids((string)$lang) as $rid) {
                    $seen[$rid] = true;
                }
            }
            $rids = array_keys($seen);
            sort($rids, SORT_STRING);
        }
        if (!$langs || !$rids) {
            return $out->result(['success' => false, 'message' => 'нечего снимать: не найдено ни языков, ни файлов словаря']);
        }

        $snapshot = $service->snapshot($rids, $langs, 'cli-release');

        return $out->result([
            'success' => true,
            'message' => 'снимок ' . $snapshot['id'] . ': языков ' . count($langs) . ', файлов ' . count($rids),
            'data'    => ['snapshot' => $snapshot['id'], 'langs' => $langs, 'rids' => count($rids)],
        ]);
    }

    /**
     * Сборка релизного манифеста из базы и текущего состояния словаря.
     * Раньше `expected`/`desired` писали руками, и опечатка в базе не портила
     * словарь, а роняла выкладку конфликтом.
     *
     * База — снимок (`--snapshot=<id>`) или каталог словарей на момент начала
     * работ (`--base=<путь>`, например выложенное git-состояние). Текущее
     * состояние — словари инстанса или `--head=<путь>`.
     */
    private function lexiconReleaseNew(array $opts, Output $out): int
    {
        $service = $this->lexiconService();

        $langs = self::listOpt($opts['langs'] ?? '');
        $rids  = self::listOpt($opts['rids'] ?? '');
        $keys  = self::listOpt($opts['keys'] ?? '');

        $snapshotId = (string)($opts['snapshot'] ?? '');
        $baseDir    = (string)($opts['base'] ?? '');
        if (($snapshotId === '') === ($baseDir === '')) {
            return $out->result([
                'success' => false,
                'message' => 'нужна ровно одна база: --snapshot=<id> или --base=<каталог словарей>',
            ]);
        }

        if ($snapshotId !== '') {
            $snapshot = $service->snapshots()->load($snapshotId);
            if ($snapshot === null) {
                return $out->result(['success' => false, 'message' => 'снимок не найден: ' . $snapshotId]);
            }
            $base = \MpcServices\Handlers\Lexicon\LexiconBatchService::baseFromSnapshot($snapshot);
        } else {
            if (!is_dir($baseDir)) {
                return $out->result(['success' => false, 'message' => 'каталог базы не найден: ' . $baseDir]);
            }
            $base = $this->lexiconState(new \MpcServices\Handlers\Lexicon\LexiconStore($baseDir), $langs, $rids);
        }

        $headDir = (string)($opts['head'] ?? '');
        if ($headDir !== '' && !is_dir($headDir)) {
            return $out->result(['success' => false, 'message' => 'каталог текущего состояния не найден: ' . $headDir]);
        }
        $headStore = $headDir !== ''
            ? new \MpcServices\Handlers\Lexicon\LexiconStore($headDir)
            : $service->store();

        // Под блокировкой писателей: иначе в срез попадёт файл в момент записи
        // админкой, и в манифест уедет наполовину сохранённое значение.
        $head = $headStore->withLock(function (\MpcServices\Handlers\Lexicon\LexiconStore $s) use ($langs, $rids): array {
            return $this->lexiconState($s, $langs, $rids);
        });

        $built = \MpcServices\Handlers\Lexicon\ReleaseManifestBuilder::build($base, $head, [
            'withClears' => !empty($opts['with-clears']),
            'langs'      => $langs,
            'rids'       => $rids,
            'keys'       => $keys,
        ]);
        $summary = $built['summary'];
        if ((int)$summary['total'] === 0) {
            return $out->result([
                'success' => true,
                'message' => 'расхождений с базой нет — доставлять нечего',
                'data'    => ['summary' => $summary],
            ]);
        }

        $counts = 'адресов ' . (int)$summary['total']
            . ' (новых ' . (int)$summary['added']
            . ', изменённых ' . (int)$summary['changed']
            . ', очисток ' . (int)$summary['cleared'] . ')';
        $json = \MpcServices\Handlers\Lexicon\ReleaseManifestBuilder::encode($built['manifest']);
        $data = ['summary' => $summary, 'addresses' => $built['addresses'], 'manifest' => $built['manifest']];

        $path = (string)($opts['out'] ?? '');
        if ($path === '') {
            $out->line($json);
            return $out->result(['success' => true, 'message' => 'манифест построен: ' . $counts, 'data' => $data]);
        }

        // Перезапись манифеста — не мелочь: журнал релизов считает отпечаток по
        // содержимому, поэтому изменённый файл станет НОВЫМ релизом и поедет
        // на сервер ещё раз.
        if (is_file($path) && empty($opts['force'])) {
            return $out->result([
                'success' => false,
                'message' => 'манифест уже существует, перезапись только с --force: ' . $path,
            ]);
        }
        if (!is_dir(dirname($path))) {
            return $out->result(['success' => false, 'message' => 'каталог для манифеста не найден: ' . dirname($path)]);
        }
        if (@file_put_contents($path, $json) === false) {
            return $out->result(['success' => false, 'message' => 'не удалось записать манифест: ' . $path]);
        }

        return $out->result([
            'success' => true,
            'message' => 'манифест записан: ' . $path . '; ' . $counts,
            'data'    => $data,
        ]);
    }

    /** Пакетный фасад словаря с поднятым автозагрузчиком пакета. */
    private function lexiconService(): \MpcServices\Handlers\Lexicon\LexiconBatchService
    {
        $corePath = $this->modx->getOption(
            'migxpageconfigurator_core_path',
            null,
            $this->modx->getOption('core_path') . 'components/migxpageconfigurator/'
        );
        require_once rtrim($corePath, '/') . '/services/vendor/autoload.php';

        return \MpcServices\Handlers\Lexicon\LexiconBatchService::fromModx($this->modx);
    }

    /**
     * Срез хранилища: lang => rid => key => value. Несуществующий файл в срез
     * НЕ попадает — иначе он был бы неотличим от пустого словаря, и сборщик
     * манифеста счёл бы все его ключи удалёнными.
     *
     * @param string[] $langs пусто — все языки хранилища
     * @param string[] $rids  пусто — все файлы языка
     */
    private function lexiconState(\MpcServices\Handlers\Lexicon\LexiconStore $store, array $langs, array $rids): array
    {
        $state = [];
        foreach ($langs ?: $store->languages() as $lang) {
            foreach ($rids ?: $store->existingRids((string)$lang) as $rid) {
                if (!is_file($store->path((string)$lang, (string)$rid))) {
                    continue;
                }
                $state[(string)$lang][(string)$rid] = $store->read((string)$lang, (string)$rid);
            }
        }

        return $state;
    }

    /**
     * Список из опции `--langs=de,fi`.
     *
     * @param mixed $value
     *
     * @return string[]
     */
    private static function listOpt($value): array
    {
        $out = [];
        foreach (is_array($value) ? $value : explode(',', (string)$value) as $item) {
            $item = trim((string)$item);
            if ($item !== '') {
                $out[] = $item;
            }
        }

        return $out;
    }

    /**
     * Чистка мёртвых ключей по реестру кандидатов (.orphan). Dry-run всегда,
     * пока не передан --force: удаление словарных ключей необратимо для
     * менеджера, поэтому список сначала показывается.
     */
    private function lexiconPrune(array $opts, Output $out): int
    {
        $lang = (string)($opts['lang'] ?? '');
        if ($lang === '' || !preg_match('/^[a-z]{2,8}$/', $lang)) {
            return $out->result(['success' => false, 'message' => 'нужен язык: mpc lexicon prune --lang=ru']);
        }
        $corePath = $this->modx->getOption('migxpageconfigurator_core_path', null,
            $this->modx->getOption('core_path') . 'components/migxpageconfigurator/');
        require_once $corePath . 'services/vendor/autoload.php';

        $service = \MpcServices\Handlers\Lexicon\LexiconBatchService::fromModx($this->modx);
        $res     = $service->prune($lang, [], empty($opts['force']), (int)($opts['older-than'] ?? 0));

        $count = empty($opts['force']) ? count($res['candidates']) : (int)$res['cleared'];
        return $out->result([
            'success' => true,
            'message' => empty($opts['force'])
                ? 'кандидатов на удаление: ' . $count . ' (удаление — с --force)'
                : 'удалено ключей: ' . $count . ', бэкап: ' . (string)($res['backup'] ?? ''),
            'data'    => $res,
        ]);
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
            '  cut <файл.tpl|all> --theme=<имя>   — нарезать ТОЛЬКО вёрстку темы (контент не трогается)',
            '  configs sync             — применить сид MIGX-конфигов (merge)',
            '  cache clear [id,…]       — очистить запечённые parsed/ (без id — все)',
            '  theme set <name> [--template=ID] | clear [--template=ID] | status   — переключение темы оформления',
            '  lexicon   export-all | export-untranslated <filename> | list',
            '  lexicon   plan <файл.xlsx|zip>   — трёхсторонний план импорта книги (ничего не пишет)',
            '  lexicon   apply <файл.xlsx|zip> --force [--conflicts=mine|server]   — применить план',
            '  lexicon   prune --lang=ru [--older-than=N] [--force]   — чистка мёртвых ключей по реестру',
            '  lexicon   snapshot-take [--langs=de,fi] [--rids=aula]   — зафиксировать базу перед правками, печатает id снимка',
            '  lexicon   release-new (--snapshot=<id>|--base=<каталог>) [--head=<каталог>] [--langs=] [--rids=] [--keys=] [--with-clears] [--out=<файл>]',
            '                                          — собрать релизный манифест из базы и текущего состояния',
            '  lexicon   release-plan <manifest.json>   — план доставки релизного манифеста (ничего не пишет)',
            '  lexicon   release-apply <manifest.json> --force   — доставить манифест: всё или ничего, повтор пропускается',
            '',
            'Флаги: --dry-run (только план), --force (деструктив), --only=ref (точечно), --json',
        ]);
    }
}
