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
            if (!$configs) {
                break;
            }

            $mergerFile = MODX_CORE_PATH . 'components/migxpageconfigurator/services/custom/Helpers/MigxConfigMerger.php';
            if (!class_exists('MpcServices\\Helpers\\MigxConfigMerger') && is_file($mergerFile)) {
                require_once $mergerFile;
            }
            $merger = class_exists('MpcServices\\Helpers\\MigxConfigMerger')
                ? new \MpcServices\Helpers\MigxConfigMerger()
                : null;

            $configs = json_decode($configs, true);
            foreach ($configs as $config) {
                $existing = $modx->getObject('migxConfig', ['name' => $config['name']]);

                $row = $config;
                unset($row['id']);
                if ($existing && $merger !== null) {
                    $row = $merger->merge($row, $existing->toArray());
                }

                if (!$existing) {
                    $existing = $modx->newObject('migxConfig');
                }
                $existing->fromArray($row, '', true);
                $existing->save();
            }
            break;

        case xPDOTransport::ACTION_UNINSTALL:
            break;
    }
}

return true;
