<?php

/**
 * Сервис для обработки события OnGetFormParams
 */

namespace MpcServices\Plugins;

/**
 * @author Arthur Shevchenko (https://t.me/ShevArtV)
 */
class OnGetFormParams extends PluginHandler
{
    public function run()
    {
        $this->modx->lexicon->load('migxpageconfigurator:default');
        if($this->scriptProperties['presetName'] === 'mpc_export_lexicons'){
            $this->scriptProperties['SendIt']->pluginParams = [
                'hooks' => '',
                'method' => 'run',
                'className' => 'MpcServices\Widgets\LexiconExport',
                'snippet' => 'widgetHandler',
                'validate' => 'filename:required',
                'filename.vTextRequired' => $this->modx->lexicon('mpc_widget_err_filename'),
                'successMessage' => $this->modx->lexicon('mpc_widget_success_export'),
            ];
        }
        if($this->scriptProperties['presetName'] === 'mpc_load_sections'){
            $this->scriptProperties['SendIt']->pluginParams = [
                'hooks' => '',
                'method' => 'loadSections',
                'className' => 'MpcServices\Widgets\LexiconExport',
                'snippet' => 'widgetHandler',
                'successMessage' => '',
                'resultShowMethod' => 'insert',
                'resultBlockSelector' => '[data-si-preset="mpc_export_lexicons"] [name="section"]'
            ];
        }
        if($this->scriptProperties['presetName'] === 'mpc_upload_lexicon_file'){
            $this->scriptProperties['SendIt']->pluginParams = [
                'maxSize' => 1,
                'maxCount' => 1,
                'allowExt' => 'xlsx',
                'portion' => 0.1,
                'loadedUnit' => '%',
            ];
        }
        if($this->scriptProperties['presetName'] === 'mpc_import_lexicons'){
            $this->scriptProperties['SendIt']->pluginParams = [
                'hooks' => '',
                'method' => 'run',
                'className' => 'MpcServices\Widgets\LexiconImport',
                'snippet' => 'widgetHandler',
                'validate' => 'filelist:required',
                'filelist.vTextRequired' => $this->modx->lexicon('mpc_widget_err_filelist'),
                'successMessage' => $this->modx->lexicon('mpc_widget_success_import'),
                'clearFieldsOnSuccess' => true
            ];
        }
    }
}
