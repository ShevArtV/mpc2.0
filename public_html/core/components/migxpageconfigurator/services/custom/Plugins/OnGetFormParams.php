<?php

/**
 * Сервис для обработки события OnHandleRequest
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
                'className' => 'MpcServices\Widgets\Export',
                'snippet' => 'WidgetHandler',
                'validate' => 'filename:required',
                'filename.vTextRequired' => $this->modx->lexicon('mpc_widget_err_filename'),
                'successMessage' => $this->modx->lexicon('mpc_widget_success_export'),
            ];
        }
    }
}
