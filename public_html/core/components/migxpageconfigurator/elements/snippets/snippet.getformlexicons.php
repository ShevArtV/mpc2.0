<?php
require_once MODX_CORE_PATH . 'components/migxpageconfigurator/model/migxpageconfigurator/migxpageconfigurator.class.php';
if($fid && $lexicons){
    $mpc = new MigxPageConfigurator($modx);
    $langKey = $langKey ?: $modx->getPlaceholder('+lang');
    return $mpc->getFormLexicons($fid, $lexicons,$langKey);
}