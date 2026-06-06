<?php
/**
 * MPC Lexicons CMP controller
 */
class MigxpageconfiguratorIndexManagerController extends modExtraManagerController
{
    public function process(array $scriptProperties = [])
    {
        $assetsUrl = $this->modx->getOption('assets_url') . 'components/migxpageconfigurator/';

        $this->addHtml('<script>var MPC = MPC || {}; MPC.config = {
            connector_url: "' . $assetsUrl . 'connector.php"
        };</script>');

        $this->addJavascript($assetsUrl . 'js/mgr/lexicons.js');

        $this->addHtml('<script>Ext.onReady(function() {
            var ct = Ext.get("mpc-lexicons-container");
            if (!ct) return;
            var vh = function () { return Ext.getBody().getViewSize().height - 100; };
            ct.setHeight(vh());
            var panel = new MPC.page.Lexicons({ renderTo: "mpc-lexicons-container", height: ct.getHeight() });

            // Контейнер расширяется при сворачивании левого сайдбара MODX (это CSS,
            // без window-resize), поэтому панель сама не релэйаутится. Следим за
            // фактической шириной/высотой и подгоняем размер панели. Поллинг не
            // зависит от внутренних id менеджера → работает при любом изменении.
            var lastW = 0, lastH = 0;
            var sync = function () {
                var w = ct.getWidth(), h = vh();
                if (w !== lastW || h !== lastH) {
                    lastW = w; lastH = h;
                    ct.setHeight(h);
                    panel.setSize(w, h);
                }
            };
            sync();
            Ext.EventManager.onWindowResize(sync);
            Ext.TaskMgr.start({ interval: 400, run: sync });
        });</script>');
    }

    public function getPageTitle()
    {
        return 'MPC: Лексиконы';
    }

    public function getTemplateFile()
    {
        return $this->modx->getOption('core_path')
            . 'components/migxpageconfigurator/templates/mgr/lexicons.tpl';
    }

    public function checkPermissions()
    {
        return $this->modx->hasPermission('components')
            && $this->modx->hasPermission('mpc_view');
    }
}
