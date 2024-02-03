<?php

require_once dirname(__FILE__) . '/mpcbasehandler.class.php';

/**
 *
 */
class MpcTemplateHandler extends MpcBaseHandler
{
    /**
     * @param $properties
     * @return void
     */
    protected function setProperties($properties)
    {
        $this->properties['corePath'] = $this->modx->getOption('core_path', null, '');
        $this->properties = array_merge($this->properties, $properties);
    }

    /**
     * @return array|object|string
     */
    public function handle()
    {
        $result = $this->getTemplateData();
        if (!$result['success']) {
            return $result;
        }

        return $this->manageTemplate($result['object']);
    }

    /**
     * @return array|object|string
     */
    private function getTemplateData()
    {
        $pathToSrc = $this->modx->getOption('mpc_path_to_src', null, 'elements/templates/');
        $baseTplId = $this->modx->getOption('mpc_base_tpl_id', null, 1);

        // получаем данные базового шаблона
        $result = $this->getObjectData('modTemplate', array('id' => $baseTplId));
        if (!$result['success']) {
            return $result;
        }
        $baseTplData = $result['object'];

        // получаем данные создаваемого или обновляемого шаблона
        $pathToFile = $this->properties['corePath'] . $pathToSrc . $this->properties['fileName'];
        $templateHtml = file_get_contents($pathToFile);
        preg_match('/<!--##(.*?)##-->/', $templateHtml, $tplDataJson);
        if (!$tplDataJson[1]) {
            $this->modx->error->addError('[MpcTemplateHandler::getTemplateData] Не удалось получить данные для создания шаблона.');
            return $this->modx->error->failure();
        }
        $tplData = json_decode($tplDataJson[1], true);
        if (!is_array($tplData) || empty($tplData)) {
            $this->modx->error->addError('[MpcTemplateHandler::getTemplateData] Данные для создания шаблона должны быть валидным  JSON.');
            return $this->modx->error->failure();
        }
        $tplData['html'] = $templateHtml;
        return $this->modx->error->success('', array_merge($baseTplData, $tplData));
    }

    /**
     * @param array $data
     * @return array|object|string
     */
    private function manageTemplate(array $data)
    {
        if (!$template = $this->modx->getObject('modTemplate', array('templatename' => $data['templatename']))) {
            $result = $this->createObject('modTemplate', $data);
            if (!$result['success']) {
                return $result;
            }
            $template = $result['object'];
        }

        $data = array_merge($template->toArray(), $data);
        $template->fromArray($data, '', 1);
        if (!$template->save()) {
            $this->modx->error->addError('[MpcTemplateHandler::manageTemplate] Не удалось сохранить шаблон.');
            return $this->modx->error->failure('', $data);
        }
        $this->addTemplateVariables($template->get('id'), $data['template_var_ids']);
        $data = array_merge($template->toArray(), $data);
        $data['template'] = $data['id'];
        unset($data['id']);
        return $this->modx->error->success('', $data);
    }

    /**
     * @param int $tplId
     * @param string $tvIds
     * @return array|object|string
     */
    private function addTemplateVariables(int $tplId, string $tvIds = '')
    {
        $templateVarIds = $this->modx->getOption('mpc_tmplvar_ids', null, '1,5');
        $templateVarIds = array_merge(explode(',', $templateVarIds), explode(',', $tvIds));
        $templateVarIds = array_unique($templateVarIds);
        if (!empty($templateVarIds)) {
            foreach ($templateVarIds as $templateVarId) {
                $templateVarData = array('tmplvarid' => $templateVarId, 'templateid' => $tplId, 'rank' => 0);
                if (!$this->modx->getCount('modTemplateVarTemplate', array('tmplvarid' => $templateVarId, 'templateid' => $tplId))) {
                    $this->createObject('modTemplateVarTemplate', $templateVarData);
                }
            }
        }
        return $this->modx->error->success();
    }
}