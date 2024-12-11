<?php

/**
 * Сервис для обработки события OnDocFormSave
 */

namespace MpcServices\Plugins;

use MpcServices\Mpc;

/**
 * @author Arthur Shevchenko (https://t.me/ShevArtV)
 */
class OnDocFormDelete extends PluginHandler
{
    public function run()
    {
        $Mpc = new Mpc($this->modx);
        $Mpc->render->deleteParsedConfigFile($this->scriptProperties['id']);
    }
}
