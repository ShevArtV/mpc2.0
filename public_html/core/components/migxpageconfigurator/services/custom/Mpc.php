<?php
/**
 * Сервис для работы с модулем MigxPageConfigurator
 */

namespace CustomServices;

use CustomServices\Handlers\Grabber;
use CustomServices\Helpers\Logging;

/**
 * @author Arthur Shevchenko (https://t.me/ShevArtV)
 */
class Mpc
{
    /**
     * @var Logging
     */
    private Logging $logging;

    /**
     * @var Grabber
     */
    private Grabber $grabber;
    /**
     * @var \modX
     */
    private \modX $modx;
    /**
     * @var array
     */
    protected array $properties;

    /**
     * @param \modX $modx
     */
    public function __construct(\modX $modx)
    {
        $this->modx = $modx;
        $this->initialize();
    }

    /**
     * @return void
     */
    private function initialize()
    {
        $this->logging = new Logging();
        $logFileName = str_replace('\\', '-', self::class) . '.txt';
        $this->logging->setPath($logFileName);

        $this->properties = [
            'corePath' => $this->modx->getOption('core_path', null, ''),
            'pdotoolsElementsPath' => $this->modx->getOption('pdotools_elements_path', null, '{core_path}elements/'),
            'pathToSrc' => $this->modx->getOption('mpc_path_to_src', null, 'elements/templates/')
        ];
        $this->properties['pdotoolsElementsPath'] = str_replace($this->properties['corePath'], '', $this->properties['pdotoolsElementsPath']);

        $this->grabber = new Grabber($this->modx, $this->properties);
    }


    /**
     * @param string|null $fileName
     * @param bool|null $updContent
     * @return void
     */
    public function process(?string $fileName, ?bool $updContent = false)
    {
        $this->grabber->updContent = $updContent;
        if (!$fileName) {
            $fileNames = scandir($this->properties['corePath'] . $this->properties['pathToSrc']);
            unset($fileNames[0], $fileNames[1]);
            foreach ($fileNames as $fileName) {
                $this->handleFile($fileName);
            }
        } else {
            $this->handleFile($fileName);
        }
    }

    /**
     * @param $fileName
     * @return void
     */
    private function handleFile($fileName)
    {
        $this->grabber->handle($fileName);
    }

}
