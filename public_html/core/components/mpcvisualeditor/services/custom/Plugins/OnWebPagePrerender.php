<?php
/**
 * OnWebPagePrerender — bootstrap mpcVisualEditor.
 * Контент НЕ подменяем: маркеры data-mpc-* уже присутствуют в ШТАТНОМ рендере
 * (при mpc_edit_mode каттер не режет их из чанков). Плагин лишь подключает
 * overlay-UI редактора (CSS+JS) перед </body> авторизованному редактору на любой
 * странице. Так edit-mode = обычный рендер + маркеры + UI, без расхождений
 * (lazyload/expand-скрипты и всё остальное работают как на проде).
 * Включение/выключение редактирования — тумблером в тулбаре (фронт).
 */

namespace MpcVEServices\Plugins;

use MpcVEServices\Mpcve;
use MpcVEServices\Handlers\PermissionChecker;

/**
 * @author Arthur Shevchenko (https://t.me/ShevArtV)
 */
class OnWebPagePrerender extends PluginHandler
{
    public function run(): void
    {
        $mpcve = new Mpcve($this->modx);
        if (!$mpcve->getConfig('active')) {
            return;
        }

        $checker = new PermissionChecker($this->modx, (string)$mpcve->getConfig('permission'));
        if (!$checker->userCanEdit()) {
            return;
        }

        // Связка с mpc_edit_mode (пакет migxpageconfigurator): без него каттер
        // вырезает data-mpc-* маркеры из чанков и редактору нечего цеплять.
        // Не подключаем бесполезный overlay, но явно сообщаем редактору почему.
        if (!(bool)$this->modx->getOption('mpc_edit_mode', null, false)) {
            $mpcve->logger->warning(
                'Редактор включён (mpcve_active=1), но mpc_edit_mode=0 — в чанках нет '
                . 'data-mpc-* маркеров. Включите системную настройку mpc_edit_mode и перенарежьте '
                . 'страницы, иначе режим редактирования работать не будет.',
                [],
                'prerender'
            );
            return;
        }

        $resource = $this->modx->resource;
        if (!$resource || !is_string($resource->_output) || stripos($resource->_output, '</body>') === false) {
            return;
        }

        $assetsUrl = $mpcve->getConfig('assetsUrl');
        $clientCfg = json_encode($mpcve->getClientConfig(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        // assetsUrl уходит в HTML-атрибуты href/src — экранируем (S13).
        $assetsAttr = htmlspecialchars((string)$assetsUrl, ENT_QUOTES);
        // Метка версии = filemtime: после выкладки браузер не подставит старый файл из кэша.
        $assetsPath = $this->modx->getOption('assets_path', null, MODX_ASSETS_PATH) . 'components/mpcvisualeditor/';

        $inject = '<link rel="stylesheet" href="' . $assetsAttr . 'css/overlay.css' . $this->version($assetsPath . 'css/overlay.css') . '">' . PHP_EOL
            . '<script>window.mpcVEConfig=' . $clientCfg . ';</script>' . PHP_EOL
            . '<script type="module" src="' . $assetsAttr . 'js/mpcve.js' . $this->version($assetsPath . 'js/mpcve.js') . '"></script>' . PHP_EOL;

        $output = str_ireplace('</body>', $inject . '</body>', $resource->_output);

        $importMap = $this->importMap((string)$assetsUrl, $assetsPath . 'js/', $output);
        if ($importMap !== '') {
            $output = preg_replace_callback('~<head\b[^>]*>~i', static function ($m) use ($importMap) {
                return $m[0] . PHP_EOL . $importMap;
            }, $output, 1);
        }

        $resource->_output = $output;
    }

    private function version(string $file): string
    {
        return is_file($file) ? '?v=' . filemtime($file) : '';
    }

    /**
     * Карта импортов с меткой версии у каждого модуля редактора: mpcve.js тянет
     * остальные модули относительными import, и метку точки входа они не наследуют.
     * Карта строится обходом js/ при каждой вставке — новый модуль попадает в неё сам.
     * Ставится сразу после <head>, раньше модулей сайта: браузер без поддержки
     * нескольких карт отбрасывает карту, пришедшую после первого модуля. Своя карта
     * у страницы или относительный assetsUrl — карту не ставим, чтобы не сломать
     * сайт; метка остаётся у точки входа и CSS.
     */
    private function importMap(string $assetsUrl, string $jsPath, string $output): string
    {
        if (!preg_match('~^(/|https?://)~i', $assetsUrl)
            || !preg_match('~<head\b~i', $output)
            || preg_match('~<script\b[^>]*\btype\s*=\s*["\']?importmap~i', $output)
            || !is_dir($jsPath)
        ) {
            return '';
        }

        $imports = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($jsPath, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'js') {
                continue;
            }
            $url = $assetsUrl . 'js/' . str_replace('\\', '/', substr($file->getPathname(), strlen($jsPath)));
            $imports[$url] = $url . '?v=' . $file->getMTime();
        }
        if (!$imports) {
            return '';
        }
        ksort($imports);

        return '<script type="importmap">'
            . json_encode(['imports' => $imports], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG)
            . '</script>';
    }
}
