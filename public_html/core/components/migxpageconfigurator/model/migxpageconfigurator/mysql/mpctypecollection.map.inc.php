<?php
$xpdo_meta_map['mpcTypeCollection']= array (
  'package' => 'migxpageconfigurator',
  'version' => '1.1',
  'extends' => 'modResource',
  'tableMeta' => 
  array (
    'engine' => 'InnoDB',
  ),
  'fields' => 
  array (
    'class_key' => 'mpcTypeCollection',
  ),
  'fieldMeta' => 
  array (
    'class_key' => 
    array (
      'dbtype' => 'varchar',
      'precision' => '100',
      'phptype' => 'string',
      'null' => false,
      'default' => 'mpcTypeCollection',
    ),
  ),
  'composites' => 
  array (
    'OwnTypes' => 
    array (
      'class' => 'mpcType',
      'local' => 'id',
      'foreign' => 'parent',
      'cardinality' => 'many',
      'owner' => 'local',
    ),
  ),
);
