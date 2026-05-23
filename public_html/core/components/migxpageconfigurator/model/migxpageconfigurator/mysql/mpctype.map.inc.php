<?php
$xpdo_meta_map['mpcType']= array (
  'package' => 'migxpageconfigurator',
  'version' => '1.1',
  'extends' => 'modResource',
  'tableMeta' => 
  array (
    'engine' => 'InnoDB',
  ),
  'fields' => 
  array (
    'class_key' => 'mpcType',
  ),
  'fieldMeta' => 
  array (
    'class_key' => 
    array (
      'dbtype' => 'varchar',
      'precision' => '100',
      'phptype' => 'string',
      'null' => false,
      'default' => 'mpcType',
    ),
  ),
  'composites' => 
  array (
    'Data' => 
    array (
      'class' => 'mpcTypeData',
      'local' => 'id',
      'foreign' => 'id',
      'cardinality' => 'one',
      'owner' => 'local',
    ),
  ),
);
