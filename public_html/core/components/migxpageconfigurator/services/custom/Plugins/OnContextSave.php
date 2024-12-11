<?php

/**
 * Сервис для обработки события OnContextSave
 */

namespace MpcServices\Plugins;

use MpcServices\Mpc;

/**
 * @author Arthur Shevchenko (https://t.me/ShevArtV)
 */
class OnContextSave extends PluginHandler
{
    public function run()
    {
        $Mpc = new Mpc($this->modx);
        if($this->scriptProperties['mode'] === 'new'){
            $Mpc->copySystemSettingsToNewContext($this->scriptProperties['context']);
        }
    }
}
