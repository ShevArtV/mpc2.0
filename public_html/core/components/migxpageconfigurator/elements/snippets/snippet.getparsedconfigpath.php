<?php
use CustomServices\Mpc;

require_once MODX_CORE_PATH . 'components/migxpageconfigurator/services/vendor/autoload.php';
/**
 * @var \Modx $modx
 * @var int $rid
 */
$mpc = new Mpc($modx);
$resource = $rid ? $modx->getObject('modResource', $rid) : $modx->resource;
return $mpc->getParsedConfigPath($resource);