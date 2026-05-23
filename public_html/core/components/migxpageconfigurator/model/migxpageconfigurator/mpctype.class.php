<?php
/**
 * mpcType — тип страницы. Наследует modResource; служебные поля type_name /
 * file_name хранятся отдельно (таблица mpc_type, объект mpcTypeData, 1:1 по id).
 * Реализация по образцу minishop2 msProduct: маршрутизация полей по
 * _originalFieldMeta (нативные поля ресурса → parent, остальные → Data),
 * ленивый loadData(), сохранение Data каскадом через composite на parent::save().
 */
class mpcType extends modResource
{
    public $showInContextMenu = true;
    public $allowChildrenResources = true;

    /** @var mpcTypeData $Data */
    protected $Data;

    /** @var array алиасы связей, принадлежащих Data (для маршрутизации getOne/addOne) */
    protected $dataRelated = array();

    /** @var array метаданные «родных» полей ресурса (modResource + class_key) */
    protected $_originalFieldMeta;

    public function __construct(xPDO & $xpdo)
    {
        parent::__construct($xpdo);
        parent::set('class_key', 'mpcType');
        $this->_originalFieldMeta = $this->_fieldMeta;

        $aggregates = $this->xpdo->getAggregates('mpcTypeData');
        $composites = $this->xpdo->getComposites('mpcTypeData');
        $this->dataRelated = array_merge(array_keys($aggregates), array_keys($composites));
    }

    public static function load(xPDO & $xpdo, $className, $criteria = null, $cacheFlag = true)
    {
        if (!is_object($criteria)) {
            $criteria = $xpdo->getCriteria($className, $criteria, $cacheFlag);
        }
        $xpdo->addDerivativeCriteria($className, $criteria);

        return parent::load($xpdo, $className, $criteria, $cacheFlag);
    }

    public static function loadCollection(xPDO & $xpdo, $className, $criteria = null, $cacheFlag = true)
    {
        if (!is_object($criteria)) {
            $criteria = $xpdo->getCriteria($className, $criteria, $cacheFlag);
        }
        $xpdo->addDerivativeCriteria($className, $criteria);

        return parent::loadCollection($xpdo, $className, $criteria, $cacheFlag);
    }

    public function getContextMenuText()
    {
        $this->xpdo->lexicon->load('migxpageconfigurator:default');

        return array(
            'text_create' => $this->xpdo->lexicon('mpctype') ?: 'mpcType',
            'text_create_here' => $this->xpdo->lexicon('mpctype_create_here') ?: 'mpcType',
        );
    }

    public function getResourceTypeName()
    {
        $this->xpdo->lexicon->load('migxpageconfigurator:default');

        return $this->xpdo->lexicon('mpctype') ?: 'mpcType';
    }

    public function set($k, $v = null, $vType = '')
    {
        return isset($this->_originalFieldMeta[$k])
            ? parent::set($k, $v, $vType)
            : $this->loadData()->set($k, $v, $vType);
    }

    protected function _setRaw($key, $val)
    {
        return isset($this->_originalFieldMeta[$key])
            ? parent::_setRaw($key, $val)
            : $this->loadData()->_setRaw($key, $val);
    }

    public function save($cacheFlag = null)
    {
        // Сменили класс ресурса на не-mpcType — чистим Data; иначе гарантируем Data.
        if (!$this->isNew() && parent::get('class_key') != 'mpcType') {
            $this->loadData()->remove();
        } else {
            $this->loadData();
        }

        return parent::save($cacheFlag);
    }

    public function get($k, $format = null, $formatTemplate = null)
    {
        if (is_array($k)) {
            $array = array();
            foreach ($k as $v) {
                $array[$v] = $this->get($v, $format, $formatTemplate);
            }

            return $array;
        } elseif (isset($this->_originalFieldMeta[$k])) {
            return parent::get($k, $format, $formatTemplate);
        } elseif (isset($this->loadData()->_fields[$k])) {
            return $this->loadData()->get($k, $format, $formatTemplate);
        }

        return parent::get($k, $format, $formatTemplate);
    }

    public function toArray($keyPrefix = '', $rawValues = false, $excludeLazy = false, $includeRelated = false)
    {
        $original = parent::toArray($keyPrefix, $rawValues, $excludeLazy, $includeRelated);
        $additional = $this->loadData()->toArray($keyPrefix, $rawValues, $excludeLazy, $includeRelated);
        $intersect = array_keys(array_intersect_key($original, $additional));
        foreach ($intersect as $key) {
            unset($additional[$key]);
        }

        return array_merge($original, $additional);
    }

    /**
     * @return mpcTypeData
     */
    public function loadData()
    {
        if (!is_object($this->Data) || !($this->Data instanceof mpcTypeData)) {
            if (!$this->Data = $this->getOne('Data')) {
                $this->Data = $this->xpdo->newObject('mpcTypeData');
                parent::addOne($this->Data);
            }
        }

        return $this->Data;
    }

    public function & getOne($alias, $criteria = null, $cacheFlag = true)
    {
        $object = in_array($alias, $this->dataRelated, true)
            ? $this->loadData()->getOne($alias, $criteria, $cacheFlag)
            : parent::getOne($alias, $criteria, $cacheFlag);

        return $object;
    }

    public function addOne(&$obj, $alias = '')
    {
        if (empty($alias)) {
            if ($obj->_alias == $obj->_class) {
                $aliases = $this->_getAliases($obj->_class, 1);
                if (!empty($aliases)) {
                    $obj->_alias = reset($aliases);
                }
            }
            $alias = $obj->_alias;
        }

        return in_array($alias, $this->dataRelated, true)
            ? $this->loadData()->addOne($obj, $alias)
            : parent::addOne($obj, $alias);
    }
}
