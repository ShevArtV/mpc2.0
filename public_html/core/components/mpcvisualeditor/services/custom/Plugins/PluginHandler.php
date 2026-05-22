<?php
/**
 * Базовый обработчик системных событий mpcVisualEditor.
 */

namespace MpcVEServices\Plugins;

/**
 * @author Arthur Shevchenko (https://t.me/ShevArtV)
 */
class PluginHandler
{
    public \modX $modx;
    public array $scriptProperties;

    protected string $corePath;
    protected string $assetsUrl;

    public function __construct(\modX $modx, array $scriptProperties)
    {
        $this->modx = $modx;
        $this->scriptProperties = $scriptProperties;
        $this->initialize();
    }

    protected function initialize(): void
    {
        $this->corePath = $this->modx->getOption('core_path', null, MODX_CORE_PATH) . 'components/mpcvisualeditor/';
        $this->assetsUrl = $this->modx->getOption('assets_url', null, MODX_ASSETS_URL) . 'components/mpcvisualeditor/';
    }

    public function run(): void
    {
    }
}
