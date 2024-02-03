<?php
require_once MODX_CORE_PATH . 'components/migxpageconfigurator/model/migxpageconfigurator/migxpageconfigurator.class.php';

$mpc = new MigxPageConfigurator($modx);
$parsedPath = MODX_CORE_PATH . $mpc->pdotools_elements_path;
$resource = $rid ? $modx->getObject('modResource', $rid) : $modx->resource;
if ($resource) {
    $rid = $resource->get('id');
    $lang_key = $modx->getPlaceholder('+lang');
    $lang_key_default = $modx->getOption('polylang_visitor_default_language');
    if ($lang_key && $lang_key !== $lang_key_default) {
        $path = $mpc->path_to_dist . $rid . $lang_key . $mpc->extension;
        if (!file_exists($parsedPath . $path) && $resource) {
            $mpc->prepareToParsePolylangConfig($rid, $lang_key);
        }
    } else {
        $path = $mpc->path_to_dist . $rid . $mpc->extension;
        if (!file_exists($parsedPath . $path) && $resource) {
            $mpc->prepareToParseConfig($resource);
        }
    }
    if(file_exists($parsedPath . $path)){
        return 'file:' . $path;
    }
}
return false;