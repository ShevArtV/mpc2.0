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
        // Пакет выставляет язык на OnHandleRequest только если включён выключатель
        // mpc_set_language_on_request (по умолчанию 1). Проект может поставить 0 и
        // делать это сам (напр. после switchContext); для тонкого контроля без
        // полного выключения есть событие mpcOnBeforeSetLanguageSettings.
        if ($this->modx->context->get('key') !== 'mgr'
            && (bool)$this->modx->getOption('mpc_set_language_on_request', null, true)) {
            $Mpc = new Mpc($this->modx);
            $Mpc->setLanguageSettings();
        }
    }
}
