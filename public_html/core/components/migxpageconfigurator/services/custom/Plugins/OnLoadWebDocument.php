<?php

/**
 * Сервис для обработки события OnLoadWebDocument
 */

namespace MpcServices\Plugins;

use MpcServices\Mpc;

/**
 * @author Arthur Shevchenko (https://t.me/ShevArtV)
 */
class OnLoadWebDocument extends PluginHandler
{
    public function run()
    {
        $Mpc = new Mpc($this->modx);
        $Mpc->loadLexicons($this->modx->resource->get('id'), $this->modx->resource->get('template'));
        $Mpc->loadWebScripts();
    }
}
