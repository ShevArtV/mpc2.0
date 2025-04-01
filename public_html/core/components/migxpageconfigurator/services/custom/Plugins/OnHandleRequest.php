<?php

/**
 * Сервис для обработки события OnHandleRequest
 */

namespace MpcServices\Plugins;

use MpcServices\Mpc;

/**
 * @author Arthur Shevchenko (https://t.me/ShevArtV)
 */
class OnHandleRequest extends PluginHandler
{
    public function run()
    {
        if($this->modx->context->get('key') !== 'mgr'){
            $Mpc = new Mpc($this->modx);
            $Mpc->setLanguageSettings();
        }
    }
}
