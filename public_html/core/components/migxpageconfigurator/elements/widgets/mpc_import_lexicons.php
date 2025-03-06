<?php

class modDashboardWidgetMpcImportLexicons extends modDashboardWidgetInterface
{
    public function render()
    {
        $pdoTools = $this->modx->getService('pdoTools');
        $corePath = $this->modx->getOption('core_path', '', MODX_CORE_PATH);
        $chunkContent = file_get_contents($corePath . 'components/migxpageconfigurator/elements/chunks/widgets/mpc_import_lexicons.tpl');
        return $pdoTools->getChunk('@INLINE '.$chunkContent);
    }
}

return 'modDashboardWidgetMpcImportLexicons';
