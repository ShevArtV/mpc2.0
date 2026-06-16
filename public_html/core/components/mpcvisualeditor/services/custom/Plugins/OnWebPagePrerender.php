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

        $inject = '<link rel="stylesheet" href="' . $assetsAttr . 'css/overlay.css">' . PHP_EOL
            . '<script>window.mpcVEConfig=' . $clientCfg . ';</script>' . PHP_EOL
            . '<script type="module" src="' . $assetsAttr . 'js/mpcve.js"></script>' . PHP_EOL;

        $resource->_output = str_ireplace('</body>', $inject . '</body>', $resource->_output);
    }
}
