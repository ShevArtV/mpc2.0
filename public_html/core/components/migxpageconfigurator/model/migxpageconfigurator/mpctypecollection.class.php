<?php
/**
 * mpcTypeCollection — контейнер типов страниц. Наследует modResource без
 * дополнительных полей; служит папкой-группировкой для ресурсов mpcType.
 */
class mpcTypeCollection extends modResource
{
    public $showInTree = true;

    public function __construct(xPDO & $xpdo)
    {
        parent::__construct($xpdo);
        $this->set('class_key', 'mpcTypeCollection');
        $this->set('isfolder', true);
    }

    public static function getControllerPath(xPDO & $modx)
    {
        return $modx->getOption('core_path', null, MODX_CORE_PATH)
            . 'components/migxpageconfigurator/controllers/res/mpctypecollection/';
    }

    public function getResourceTypeName()
    {
        $this->xpdo->lexicon->load('migxpageconfigurator:default');
        $name = $this->xpdo->lexicon('mpctypecollection');
        return $name ?: 'mpcTypeCollection';
    }
}
