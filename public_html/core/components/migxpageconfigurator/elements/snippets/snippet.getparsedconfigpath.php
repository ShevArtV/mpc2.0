<?php
use MpcServices\Mpc;

/**
 * @author Arthur Shevchenko (https://t.me/ShevArtV)
 * @var \modX $modx
 * @var int $rid
 */
$corePath = $modx->getOption('core_path', '', MODX_CORE_PATH);
require_once $corePath . 'components/migxpageconfigurator/services/vendor/autoload.php';

$mpc = Mpc::instance($modx);
$resource = $rid ? $modx->getObject('modResource', $rid) : $modx->resource;
return $mpc->getParsedConfigPath($resource);