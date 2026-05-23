<?php

/**
 * Сервис для обработки события OnMODXInit.
 * Регистрирует xPDO-модель пакета на КАЖДОМ запросе, чтобы кастомные
 * resource-классы (mpcType / mpcTypeCollection) резолвились по class_key
 * в менеджере (дерево/редактирование) и на фронте.
 */

namespace MpcServices\Plugins;

/**
 * @author Arthur Shevchenko (https://t.me/ShevArtV)
 */
class OnMODXInit extends PluginHandler
{
    public function run(): void
    {
        $this->modx->addPackage(
            'migxpageconfigurator',
            $this->corePath . 'components/migxpageconfigurator/model/'
        );
    }
}
