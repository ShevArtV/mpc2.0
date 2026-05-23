<?php
/**
 * Manager-контроллер обновления ресурса типа (mpcType).
 * Добавляет в форму read-only поля type_name / file_name (из mpc_type).
 */
class mpcTypeUpdateManagerController extends ResourceUpdateManagerController
{
    public function loadCustomCssJs()
    {
        parent::loadCustomCssJs();

        $assetsUrl = $this->modx->getOption('assets_url', null, MODX_ASSETS_URL)
            . 'components/migxpageconfigurator/';

        // Значения служебных полей прокидываем в страницу для JS (read-only).
        $data = [
            'type_name' => $this->resource ? (string)$this->resource->get('type_name') : '',
            'file_name' => $this->resource ? (string)$this->resource->get('file_name') : '',
        ];
        $this->addHtml(
            '<script>window.mpcTypeData=' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ';</script>'
        );
        $this->addLastJavascript($assetsUrl . 'js/mgr/mpctype.fields.js');
    }
}
