<?php
/**
 * Процессор для работы с modTemplate.
 */

namespace MpcServices\Processors;

/**
 * @author Arthur Shevchenko (https://t.me/ShevArtV)
 */
class Template extends Base
{
    /**
     * @var string
     */
    protected string $className = 'modTemplate';
    /**
     * @var string
     */
    protected string $primaryKey = 'templatename';

    /**
     * @param int $tplId
     * @param string|null $tvIds
     * @return void
     */
    public function addTemplateVariables(int $tplId, ?string $tvIds = '')
    {
        $templateVarIds = $this->modx->getOption('mpc_tmplvar_ids', null, '');
        $templateVarIds = array_merge(explode(',', $templateVarIds), explode(',', $tvIds));
        if (!empty($templateVarIds)) {
            $templateVarIds = array_unique($templateVarIds);
            $TemplateVarTemplate = new TemplateVarTemplate($this->modx);
            foreach ($templateVarIds as $templateVarId) {
                $templateVarData = ['tmplvarid' => $templateVarId, 'templateid' => $tplId, 'rank' => 0];
                if (!$TemplateVarTemplate->update($templateVarData)) {
                    $this->response->error(__METHOD__, 'Failed to add template variable');
                }
            }
        }
    }
}
