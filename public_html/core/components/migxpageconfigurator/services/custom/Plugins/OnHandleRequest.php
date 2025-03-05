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
            $this->setLanguageSettings();
        }
    }

    public function setLanguageSettings(){
        $defaultLang = $this->modx->getOption('mpc_default_language');
        if(!isset($_COOKIE['mpc_lang'])){
            setcookie('mpc_lang', $defaultLang, 0, '/');
        }
        if(!empty($_COOKIE['mpc_lang'])){
            $this->modx->setOption('cultureKey', $_COOKIE['mpc_lang']);
        }
    }
}
