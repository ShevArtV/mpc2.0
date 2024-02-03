<?php

require_once dirname(__FILE__) . '/logging.class.php';
require_once dirname(__FILE__, 2) . '/processors/templateprocessor.class.php';

class ManageTemplate{

    public modX $modx;
    private Logging $logging;

    public function __construct(modX $modx)
    {
        $this->modx = $modx;
        $this->logging = new Logging($modx);
    }

    public function initialize(){

    }

    /**
     *
     * @param string $fileName
     * @param bool $updContent
     * @return false|void
     * @throws Exception
     */
    public function manage($fileName, $updContent = false)
    {

        $processor = new TemplateProcessor($this->modx);
        if ($result['error'] = $processor->run($fileName)) {

        }

        $file_names = $this->getFileNames($file_names);
        foreach ($file_names as $file_name) {
            if (!$result = $this->runTemplateProcessor($file_name)) {
                continue;
            }
            $resource = $this->createTemplateResource(array(
                'parent' => $this->sbp_id,
                'pagetitle' => $result['pagetitle'],
                'template' => $result['template_id'],
                'hidemenu' => 1));

            if (!$this->getTemplateSections($result['template_html'], $resource, $upd_content)) {
                continue;
            }

            $this->prepareToParseConfig($resource);
            if ($this->dev_mode) {
                $this->clearCache();
                $this->modx->cacheManager->refresh();
            }
        }
    }

    private function getTemplateData($fileName){

    }
}