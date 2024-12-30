<?php
require_once MODX_CORE_PATH . 'components/migxpageconfigurator/model/migxpageconfigurator/migxpageconfigurator.class.php';

if ($resource) {
    $modx->switchContext($resource->get('context_key'));
}
$events = [
    'OnDocFormDelete',
    'OnCacheUpdate',
    'OnResourceUndelete',
    'OnDocFormSave',
    'OnDocFormPrerender',
    'OnLoadWebDocument',
    'OnContextSave',
    'OnSavePolylangContent'
];
if(in_array($modx->event->name, $events)){
    $mpc = new MigxPageConfigurator($modx);
}

// EVENTS
switch ($modx->event->name) {
    case 'OnSavePolylangContent':
        $lang_key = $_POST['polylangcontent_culture_key'];
        $urlParts = explode('?', urldecode($_SERVER['HTTP_REFERER']));
        $paramsRaw = explode('amp;', $urlParts[1]);
        $params = [];
        foreach($paramsRaw as $pair){
            $p = explode('=', $pair);
            $params[$p[0]] = $p[1];
        }
        $mpc->copyPolylangConfig($params['id'], $lang_key);
        $mpc->prepareToParsePolylangConfig($params['id'], $lang_key);
        break;

    case 'OnDocFormDelete':
        $mpc->deleteParsedConfigFile($id); // удаляем файл с распарсенный файл с разметкой
        break;

    case 'OnCacheUpdate':
        $mpc->clearCache(); // удаляем все распарсенные файлы
        break;

    case 'OnResourceUndelete':
    case 'OnDocFormSave':
        if ($modx->event->name === 'OnDocFormSave') {
            $mpc->copyConfig($resource);
        }

        $mpc->prepareToParseConfig($resource); // запускаем процесс обработки ресурсов

        if ($id === $mpc->cp_id) {
            $defautlFormParams = $mpc->prepareDefaultFormParams();
            $form_list = json_decode($resource->getTVValue('form_list'), 1);
            if(!empty($form_list)){
                foreach ($form_list as $formData) {
                    $mpc->getAjaxFormCall($formData, $defautlFormParams);
                }
            }
        }
        break;

    case 'OnDocFormPrerender':
        if ($id === $mpc->cp_id) {
            $mpc->updateFormList();
        }
        break;

    case 'OnLoadWebDocument':
        $assetsUrl = $modx->getOption('site_url') . 'assets/components/migxpageconfigurator/';
        $lazy_attr = $modx->getOption('mpc_lazyload_attr');
        if ($lazy_attr) {
            $modx->regClientScript("<script type=\"module\">
            import * as functions from \"{$assetsUrl}js/web/functions.js\";
            
            document.addEventListener('DOMContentLoaded', () => {
                window.addEventListener('scroll', () => {
                    functions.lazyLoad(\"{$lazy_attr}\");
                });
                functions.lazyLoad(\"{$lazy_attr}\");
                
                if(typeof jQuery !== 'undefined'){        
                    $(document).on('mse2_load', function(e, data) {
                        functions.lazyLoad(\"{$lazy_attr}\");
                    });
                    $(document).on('pdopage_load', function(e, config, response) {
                        functions.lazyLoad(\"{$lazy_attr}\");
                    });
                } 
            });           
            </script>", 1);
        }
        break;

    case 'OnContextSave':
        if($mode === 'new'){
            $settings = $modx->getIterator('modSystemSetting', array('namespace' => 'migxpageconfigurator'));
            foreach($settings as $setting){
                $setting = $setting->toArray();
                $setting['context_key'] = $context->get('key');
                $ctxSetting = $modx->newObject('modContextSetting');
                $ctxSetting->fromArray($setting, '', true);
                $ctxSetting->save();
            }
        }
        break;
}