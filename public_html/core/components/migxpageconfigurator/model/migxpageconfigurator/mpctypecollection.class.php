<?php
/**
 * mpcTypeCollection — контейнер типов страниц. Наследует modResource без
 * дополнительных полей (по образцу minishop2 msCategory). Группирует ресурсы
 * mpcType (composite OwnTypes по parent).
 */
class mpcTypeCollection extends modResource
{
    public $showInContextMenu = true;
    public $allowChildrenResources = true;

    public function __construct(xPDO & $xpdo)
    {
        parent::__construct($xpdo);
        parent::set('class_key', 'mpcTypeCollection');
        parent::set('isfolder', true);
    }

    public static function getControllerPath(xPDO & $modx)
    {
        return $modx->getOption('core_path', null, MODX_CORE_PATH)
            . 'components/migxpageconfigurator/controllers/res/mpctypecollection/';
    }

    public function getContextMenuText()
    {
        $this->xpdo->lexicon->load('migxpageconfigurator:default');

        return array(
            'text_create' => $this->xpdo->lexicon('mpctypecollection') ?: 'mpcTypeCollection',
            'text_create_here' => $this->xpdo->lexicon('mpctypecollection_create_here') ?: 'mpcTypeCollection',
        );
    }

    public function getResourceTypeName()
    {
        $this->xpdo->lexicon->load('migxpageconfigurator:default');

        return $this->xpdo->lexicon('mpctypecollection') ?: 'mpcTypeCollection';
    }
}
