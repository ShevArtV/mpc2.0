<?php

require_once dirname(__FILE__, 2) . '/handlers/mpctemplatehandler.class.php';
require_once dirname(__FILE__, 2) . '/handlers/mpcresourcehandler.class.php';
require_once dirname(__FILE__, 2) . '/handlers/mpcgrabhandler.class.php';
require_once dirname(__FILE__, 2) . '/handlers/mpcfileshandler.class.php';
require_once dirname(__FILE__) . '/logging.class.php';

class MpcIde
{

    public function __construct(modX $modx)
    {
        $this->modx = $modx;
        $this->logging = new Logging($modx);
    }

    public function process($fileName, $updContent = false)
    {
        $properties = [
            'fileName' => $fileName,
            'updContent' => $updContent
        ];
        $MpcTemplateHandler = new MpcTemplateHandler($this->modx, $properties);
        $result = $MpcTemplateHandler->handle();
        if (!$result['success']) {
            $this->logging->log(implode(' ', $result['errors']), $result['object']);
            return false;
        }
        $properties['html'] = $result['object']['html'];
        $MpcResourceHandler = new MpcResourceHandler($this->modx, $result['object']);
        $result = $MpcResourceHandler->handle();
        if (!$result['success']) {
            $this->logging->log(implode(' ', $result['errors']), $result['object']);
            return false;
        }

        $properties['rid'] = $this->modx->resource ? $this->modx->resource->get('id') : $result['object']['rid'];
        $MpcGrabHandler = new MpcGrabHandler($this->modx, $properties);
        $result = $MpcGrabHandler->handle();
        if (!$result['success']) {
            $this->logging->log(implode(' ', $result['errors']), $result['object']);
            return false;
        }

       $MpcFilesHandler = new MpcFilesHandler($this->modx, $properties);
        $result = $MpcFilesHandler->handle();
        if (!$result['success']) {
            $this->logging->log(implode(' ', $result['errors']), $result['object']);
            return false;
        }
    }
}