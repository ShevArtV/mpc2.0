<?php
require_once MODX_CORE_PATH . 'components/migxpageconfigurator/model/migxpageconfigurator/migxpageconfigurator.class.php';

$mpc = new MigxPageConfigurator($modx);
$call = $mpc->getSnippetCall($value, $symbol);
return $call;