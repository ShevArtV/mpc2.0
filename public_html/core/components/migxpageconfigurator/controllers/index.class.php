<?php
/**
 * MPC Lexicons CMP controller
 */
class MigxpageconfiguratorIndexManagerController extends modExtraManagerController
{
    public function process(array $scriptProperties = [])
    {
        $assetsUrl = $this->modx->getOption('assets_url') . 'components/migxpageconfigurator/';

        $this->addHtml('<style>
            #mpc-lexicons-container .x-btn-text-icon .x-btn-text { padding-left: 26px !important; }
        </style>');

        $this->addHtml('<script>var MPC = MPC || {}; MPC.config = {
            connector_url: "' . $assetsUrl . 'connector.php"
        };</script>');

        $this->addJavascript($assetsUrl . 'js/mgr/lexicons.js');

        $this->addHtml('<script>Ext.onReady(function() {
            var ct = Ext.get("mpc-lexicons-container");
            if (!ct) return;
            ct.setHeight(Ext.getBody().getViewSize().height - 100);
            new MPC.page.Lexicons({ renderTo: "mpc-lexicons-container", height: ct.getHeight() });
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
        return $this->modx->hasPermission('mgr');
    }
}
