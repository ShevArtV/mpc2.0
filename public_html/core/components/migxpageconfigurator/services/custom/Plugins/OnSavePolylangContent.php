<?php

/**
 * Сервис для обработки события OnSavePolylangContent
 */

namespace MpcServices\Plugins;

use MpcServices\Mpc;

/**
 * @author Arthur Shevchenko (https://t.me/ShevArtV)
 */
class OnSavePolylangContent extends PluginHandler
{
    public function run()
    {
        $Mpc = new Mpc($this->modx);

        $lang_key = $_POST['polylangcontent_culture_key'];
        $urlParts = explode('?', urldecode($_SERVER['HTTP_REFERER']));
        $paramsRaw = explode('amp;', $urlParts[1]);
        $params = [];
        foreach($paramsRaw as $pair){
            $p = explode('=', $pair);
            $params[$p[0]] = $p[1];
        }
        $Mpc->render->copyPolylangConfig($params['id'], $lang_key);
        $Mpc->render->handlePolylangConfig($params['id'], $lang_key);
    }
}
