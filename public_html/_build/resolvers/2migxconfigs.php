<?php
/** @var xPDOTransport $transport */
/** @var array $options */
/** @var modX $modx */
if ($transport->xpdo) {
    $modx =& $transport->xpdo;

    switch ($options[xPDOTransport::PACKAGE_ACTION]) {
        case xPDOTransport::ACTION_INSTALL:
        case xPDOTransport::ACTION_UPGRADE:
            $configs = file_get_contents(MODX_CORE_PATH . 'components/migxpageconfigurator/elements/configs/migx_configs.json');
            $modx->addPackage('migx', MODX_CORE_PATH . 'components/migx/model/');
            if($configs){
                $configs = json_decode($configs, 1);
                foreach ($configs as $config){
                    if(!$migx = $modx->getObject('migxConfig', array('name' => $config['name']))){
                        $migx = $modx->newObject('migxConfig');
                    }
                    unset($config['id']);
                    $migx->fromArray($config, '', true);
                    $migx->save();
                }
            }
            break;

        case xPDOTransport::ACTION_UNINSTALL:
            break;
    }
}

return true;