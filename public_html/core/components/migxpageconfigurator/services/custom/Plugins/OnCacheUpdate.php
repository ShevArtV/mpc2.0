<?php

/**
 * Сервис для обработки события OnDocFormSave
 */

namespace MpcServices\Plugins;

use MpcServices\Mpc;

/**
 * @author Arthur Shevchenko (https://t.me/ShevArtV)
 */
class OnCacheUpdate extends PluginHandler
{
    public function run()
    {
        $Mpc = new Mpc($this->modx);
        $Mpc->render->clearCache();
    }
}
