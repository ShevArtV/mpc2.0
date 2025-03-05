<?php

class modDashboardWidgetMpcExportLexicons extends modDashboardWidgetInterface
{
    public function render()
    {
        $pdoTools = $this->modx->getService('pdoTools');
        $corePath = $this->modx->getOption('core_path', '', MODX_CORE_PATH);
        $chunkContent = file_get_contents($corePath . 'components/migxpageconfigurator/elements/chunks/widgets/mpc_export_lexicons.tpl');
        return $pdoTools->getChunk('@INLINE '.$chunkContent, ['filelist' => $this->getFileList()]);
    }

    private function getFileList(){
        $corePath = $this->modx->getOption('core_path', '', MODX_CORE_PATH);
        $lexiconPath = $this->modx->getOption('mpc_lexicon_path', '', 'components/migxpageconfigurator/lexicon/');
        $defaultLang = $this->modx->getOption('mpc_default_language', '', 'ru');
        $path = $corePath . $lexiconPath . $defaultLang;
        if(file_exists($path)){
            $files = scandir($path);
            unset($files[0],$files[1]);
            return array_filter($files, function ($value) {
                return $value !== 'default.inc.php';
            });
        }
        return [];
    }
}

return 'modDashboardWidgetMpcExportLexicons';
