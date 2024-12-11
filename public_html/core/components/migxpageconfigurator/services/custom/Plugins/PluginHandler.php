<?php
/**
 * Сервис-заготовка для обработчиков системных событий.
 */
namespace MpcServices\Plugins;

/**
 * @author Arthur Shevchenko (https://t.me/ShevArtV)
 */
class PluginHandler
{

    public \modX $modx;
    public array $scriptProperties;
    /**
     * @var string
     */
    protected string $basePath;
    /**
     * @var string
     */
    protected string $corePath;

    public function __construct(\modX $modx, array $scriptProperties)
    {
        $this->modx = $modx;
        $this->scriptProperties = $scriptProperties;
        $this->initialize();
    }

    protected function initialize()
    {
        $this->basePath = $this->modx->getOption('base_path', null, $_SERVER['DOCUMENT_ROOT'] . '/');
        $this->corePath = $this->modx->getOption('base_path', null, $this->basePath . 'core/');
    }

    public function run()
    {
    }
}
