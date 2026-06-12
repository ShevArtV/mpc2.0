<?php

namespace MpcVEServices\Handlers;

/**
 * Экшен settings/list: список служебных настроек (data-mpc-info), управляемых со
 * страницы. Сканирует ИСХОДНЫЕ шаблоны (mpc_path_to_src) на data-mpc-info — поэтому
 * видит и настройки с data-mpc-remove (которых нет в DOM). Тип (xtype) и значение
 * берутся из БД (InformationUpdater::settingMeta) — единый источник типа, не вёрстка.
 * Требует право mpcve_edit_global.
 *
 * @author Arthur Shevchenko (https://t.me/ShevArtV)
 */
class SettingsListHandler extends BaseHandler
{
    public function handle(array $request): array
    {
        if (!(new PermissionChecker($this->modx))->canEditGlobal()) {
            return $this->err($this->modx->lexicon('mpcve_err_permission'));
        }
        if (!class_exists('\\MpcServices\\Handlers\\Grabber\\InformationUpdater')) {
            return $this->err('migxpageconfigurator (mpc) required');
        }
        $dir = $this->templatesDir();
        if ($dir === '' || !is_dir($dir)) {
            return $this->ok('', ['settings' => []]);
        }

        $parser = new \MpcServices\Handlers\Parser();
        $iu = new \MpcServices\Handlers\Grabber\InformationUpdater($this->modx, [], $parser);

        $seen = [];
        $list = [];
        foreach ($this->tplFiles($dir) as $file) {
            $html = (string)@file_get_contents($file);
            if ($html === '' || strpos($html, 'data-mpc-info') === false) {
                continue;
            }
            foreach ($parser->findByAttribute($html, '[data-mpc-info]') as $el) {
                $key = (string)$el->getAttribute('data-mpc-info');
                if ($key === '') {
                    continue;
                }
                $hasCtx = $el->hasAttribute('data-mpc-ctx');
                $ctxAttr = $hasCtx ? (string)$el->getAttribute('data-mpc-ctx') : null;
                $dedup = $key . '|' . ($hasCtx ? ($ctxAttr ?? '') : '#sys');
                if (isset($seen[$dedup])) {
                    continue;
                }
                $seen[$dedup] = true;

                $ctx = $hasCtx
                    ? ($ctxAttr === '' ? (string)($this->modx->context ? $this->modx->context->get('key') : 'web') : $ctxAttr)
                    : null;
                $meta = $iu->settingMeta($key, $ctx);
                if ($meta === null) {
                    continue; // настройки нет в БД — править нечего
                }
                $list[] = [
                    'key'       => $key,
                    'ctx'       => $hasCtx ? ($ctxAttr ?? '') : null,
                    'value'     => $meta['value'],
                    'xtype'     => $meta['xtype'],
                    'protected' => $meta['protected'],
                ];
            }
        }
        usort($list, static function ($a, $b) {
            return strcmp($a['key'], $b['key']);
        });
        return $this->ok('', ['settings' => $list]);
    }

    /** Папка исходных шаблонов: pdotools_elements_path + mpc_path_to_src. */
    private function templatesDir(): string
    {
        $core = (string)$this->modx->getOption('core_path');
        $elements = (string)$this->modx->getOption('pdotools_elements_path', null, '{core_path}elements/');
        $elements = str_replace(['{core_path}', '//'], [$core, '/'], $elements);
        $src = trim((string)$this->modx->getOption('mpc_path_to_src', null, 'templates/'), '/');
        return rtrim($elements, '/') . '/' . $src . '/';
    }

    /** Все файлы-шаблоны (по mpc_tpl_file_extension) рекурсивно. */
    private function tplFiles(string $dir): array
    {
        $ext = (string)$this->modx->getOption('mpc_tpl_file_extension', null, '.tpl');
        $files = [];
        try {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
            );
            foreach ($it as $f) {
                if ($f->isFile() && $ext !== '' && substr($f->getFilename(), -strlen($ext)) === $ext) {
                    $files[] = $f->getPathname();
                }
            }
        } catch (\Throwable $e) {
            // папка недоступна → пустой список
        }
        return $files;
    }
}
