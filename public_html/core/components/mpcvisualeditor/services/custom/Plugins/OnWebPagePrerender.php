<?php
/**
 * OnWebPagePrerender — bootstrap mpcVisualEditor.
 * Если edit-режим включён и у пользователя есть права — инжектит overlay-UI
 * (CSS + JS + window.mpcVEConfig) перед </body> в выхлоп страницы.
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

        // edit-режим включается query-параметром (?mpcedit=1) или cookie mpcve_on
        $param = (string)$mpcve->getConfig('editParam');
        $enabled = !empty($_GET[$param]) || !empty($_COOKIE['mpcve_on']);
        if (!$enabled) {
            return;
        }

        $checker = new PermissionChecker($this->modx, (string)$mpcve->getConfig('permission'));
        if (!$checker->userCanEdit()) {
            return;
        }

        $resource = $this->modx->resource;
        if (!$resource) {
            return;
        }

        // Edit-mode рендер mpc: страница с сохранёнными data-mpc-* маркерами
        // (из _edit-чанков, в памяти). Подменяем выхлоп — фронт-редактору нужны
        // маркеры. Требует mpc_edit_mode + перенарезку (генерация _edit-чанков).
        if (class_exists('\\MpcServices\\Mpc')) {
            try {
                $Mpc = new \MpcServices\Mpc($this->modx);
                $editHtml = $Mpc->render->renderEditMode($resource->toArray());
                if (is_string($editHtml) && $editHtml !== '') {
                    $resource->_output = $editHtml;
                }
            } catch (\Throwable $e) {
                $this->modx->log(\modX::LOG_LEVEL_ERROR, '[mpcVE] editMode render failed: ' . $e->getMessage());
            }
        }

        $assetsUrl = $mpcve->getConfig('assetsUrl');
        $clientCfg = json_encode($mpcve->getClientConfig(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $inject = '<link rel="stylesheet" href="' . $assetsUrl . 'css/overlay.css">' . PHP_EOL
            . '<script>window.mpcVEConfig=' . $clientCfg . ';</script>' . PHP_EOL
            . '<script defer src="' . $assetsUrl . 'js/mpcve.js"></script>' . PHP_EOL;

        if (is_string($resource->_output) && stripos($resource->_output, '</body>') !== false) {
            $resource->_output = str_ireplace('</body>', $inject . '</body>', $resource->_output);
        }
    }
}
