<?php
/**
 * mpcType — тип страницы. Наследует modResource и добавляет служебные поля
 * type_name / file_name, которые хранятся ОТДЕЛЬНО от modx_site_content —
 * в таблице mpc_type (1:1 по id ресурса). Так основной контент ресурса
 * (pagetitle/content/…) свободен и его можно безопасно перезаписывать.
 * В админке type_name/file_name — read-only (через CRC).
 */
class mpcType extends modResource
{
    public $showInTree = true;

    /** @var string[] виртуальные поля, проксируемые в mpcTypeData */
    protected $_mpcExtra = ['type_name', 'file_name'];

    /** @var array стэш значений доп-полей до save() */
    protected $_mpcStash = [];

    public function __construct(xPDO & $xpdo)
    {
        parent::__construct($xpdo);
        $this->set('class_key', 'mpcType');
    }

    public static function getControllerPath(xPDO & $modx)
    {
        return $modx->getOption('core_path', null, MODX_CORE_PATH)
            . 'components/migxpageconfigurator/controllers/res/mpctype/';
    }

    public function getResourceTypeName()
    {
        $this->xpdo->lexicon->load('migxpageconfigurator:default');
        $name = $this->xpdo->lexicon('mpctype');
        return $name ?: 'mpcType';
    }

    /** Регистрирует пакет с mpcTypeData (на случай, если ещё не добавлен). */
    protected function loadMpcPackage()
    {
        $this->xpdo->addPackage(
            'migxpageconfigurator',
            $this->xpdo->getOption('core_path', null, MODX_CORE_PATH) . 'components/migxpageconfigurator/model/'
        );
    }

    public function get($k, $format = null, $formatTemplate = null)
    {
        if (is_string($k) && in_array($k, $this->_mpcExtra, true)) {
            if (array_key_exists($k, $this->_mpcStash)) {
                return $this->_mpcStash[$k];
            }
            $id = (int)parent::get('id');
            if ($id) {
                $this->loadMpcPackage();
                if ($data = $this->xpdo->getObject('mpcTypeData', $id)) {
                    return $data->get($k);
                }
            }
            return '';
        }
        return parent::get($k, $format, $formatTemplate);
    }

    public function set($k, $v = null)
    {
        if (is_string($k) && in_array($k, $this->_mpcExtra, true)) {
            $this->_mpcStash[$k] = $v;
            return true;
        }
        return parent::set($k, $v);
    }

    public function save($cacheFlag = null)
    {
        $saved = parent::save($cacheFlag);
        if ($saved && !empty($this->_mpcStash)) {
            $this->loadMpcPackage();
            $id = (int)parent::get('id');
            $data = $this->xpdo->getObject('mpcTypeData', $id);
            if (!$data) {
                $data = $this->xpdo->newObject('mpcTypeData');
                $data->set('id', $id);
            }
            foreach ($this->_mpcExtra as $f) {
                if (array_key_exists($f, $this->_mpcStash)) {
                    $data->set($f, $this->_mpcStash[$f]);
                }
            }
            $data->save();
            $this->_mpcStash = [];
        }
        return $saved;
    }

    public function remove(array $ancestors = [])
    {
        $id = (int)parent::get('id');
        if ($id) {
            $this->loadMpcPackage();
            if ($data = $this->xpdo->getObject('mpcTypeData', $id)) {
                $data->remove();
            }
        }
        return parent::remove($ancestors);
    }
}
