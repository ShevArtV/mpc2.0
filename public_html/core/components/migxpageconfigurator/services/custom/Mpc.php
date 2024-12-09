<?php
/**
 * Сервис для работы с модулем MigxPageConfigurator
 */

namespace CustomServices;

use CustomServices\Handlers\Grabber;
use CustomServices\Handlers\Cutter;
use CustomServices\Handlers\Render;
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
     * @var Cutter
     */
    private Cutter $cutter;
    /**
     * @var \modX
     */
    private \modX $modx;
    /**
     * @var array
     */
    protected array $properties;
    private Render $render;

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
            'pathToDist' => $this->modx->getOption('mpc_path_to_dist', null, 'parsed/'),
            'extension' => $this->modx->getOption('mpc_tpl_file_extension', null, '.tpl'),
        ];

        $this->properties['pdotoolsElementsPath'] = str_replace('{core_path}', '', $this->properties['pdotoolsElementsPath']);
        $this->properties['pdotoolsElementsPath'] = str_replace('\\', '/', $this->properties['pdotoolsElementsPath']);
        $this->properties['corePath'] = str_replace('\\', '/', $this->properties['corePath']);
        if (strpos($this->properties['pdotoolsElementsPath'], $this->properties['corePath']) === false) {
            $this->properties['pdotoolsElementsPath'] = $this->properties['corePath'] . $this->properties['pdotoolsElementsPath'];
        }

        $this->grabber = new Grabber($this->modx, $this->properties);
        $this->cutter = new Cutter($this->modx, $this->properties);
        $this->render = new Render($this->modx, $this->properties);
    }


    /**
     * @param string|null $fileName
     * @param bool|null $updContent
     * @return void
     */
    public function process(?string $fileName, ?bool $updContent)
    {
        $this->grabber->updContent = $updContent ?? false;
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
        $result = $this->grabber->handle($fileName);
        $this->cutter->handle($fileName);
        if ($result['data']['resource']) {
            $this->render->handle($result['data']['resource']);
        }
    }

    public function getParsedConfigPath(\modResource $resource)
    {
        $parsedPath = $this->properties['pdotoolsElementsPath'];
        $rid = $resource->get('id');
        $langKey = $this->modx->getPlaceholder('+lang');
        $langKeyDefault = $this->modx->getOption('polylang_visitor_default_language');
        if ($langKey && $langKey !== $langKeyDefault) {
            $path = $this->properties['pathToDist'] . $rid . $langKey . $this->properties['extension'];
            if (!file_exists($parsedPath . $path)) {
                $this->render->handlePolylangConfig($rid, $langKey);
            }
        } else {
            $path = $this->properties['pathToDist'] . $rid . $this->properties['extension'];
            if (!file_exists($parsedPath . $path)) {
                $this->render->handle($resource);
            }
        }

        if (file_exists($parsedPath . $path)) {
            return 'file:' . $path;
        }
        return '';
    }
}
