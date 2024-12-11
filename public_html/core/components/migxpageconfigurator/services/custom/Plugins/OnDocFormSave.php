<?php

/**
 * Сервис для обработки события OnDocFormSave
 */

namespace MpcServices\Plugins;

use MpcServices\Mpc;

/**
 * @author Arthur Shevchenko (https://t.me/ShevArtV)
 */
class OnDocFormSave extends PluginHandler
{
    public function run()
    {
        $ctx = $this->scriptProperties['resource']->get('context_key');
        if($this->modx->context->get('key') !== $ctx){
            $this->modx->switchContext($ctx);
        }
        $Mpc = new Mpc($this->modx);
        $Mpc->render->copyConfig($this->scriptProperties['resource']);
        $Mpc->grabber->fromPlugin = true;
        if ($typeResource = $this->modx->getObject('modResource', [
            'template' => $this->scriptProperties['resource']->get('template'),
            'parent' => $Mpc->cutter->properties['staticBlocksPageId']
        ])) {
            $fileName = $typeResource->get('introtext');
            $Mpc->cutter->staticSectionNames = $Mpc->grabber->staticSectionNames = $Mpc->cutter->getStaticSectionNames($this->scriptProperties['resource']);
            $Mpc->handleFile($fileName);
        }
    }
}
