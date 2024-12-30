<?php
require_once MODX_CORE_PATH . 'components/migxpageconfigurator/model/migxpageconfigurator/migxpageconfigurator.class.php';
$mpc = new MigxPageConfigurator($modx);

$config = '';
if($lang_key){
    $config = $mpc->getPolylangConfig($mpc->sbp_id, $lang_key);
}else{
    if($resource = $modx->getObject('modResource', $mpc->sbp_id)){
        $config = $resource->getTVValue('config');
    }
}
if($config){
    $config = json_decode($config, 1);
    if(!empty($config)){
        foreach($config as $section){
            if($section['MIGX_formname'] === $section_name){
                foreach ($section as $k => $v){
                    if(!is_array($v) && strpos($v, '[{') !== false){
                        $section[$k] = $mpc->jsonDecodeValue(json_decode($v, 1));
                    }
                }
                return $section;
            }
        }
    }
}