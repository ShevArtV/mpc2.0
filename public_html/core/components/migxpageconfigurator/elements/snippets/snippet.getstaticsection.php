<?php
use MpcServices\Mpc;

/**
 * @author Arthur Shevchenko (https://t.me/ShevArtV)
 * @var \modX $modx
 * @var string|null $lang_key
 * @var string $section_name
 */
$corePath = $modx->getOption('core_path', '', MODX_CORE_PATH);

require_once $corePath . 'components/migxpageconfigurator/services/vendor/autoload.php';
$mpc = new Mpc($modx);

$config = '';
if ($lang_key) {
    $config = $mpc->render->getPolylangConfig($mpc->render->properties['staticBlocksPageId'], $lang_key);
} else {
    if ($resource = $modx->getObject('modResource', $mpc->render->properties['staticBlocksPageId'])) {
        $config = $resource->getTVValue($mpc->render->properties['commonConfigTvName']);
    }
}

if ($config) {
    $config = json_decode($config, 1);
    if (!empty($config)) {
        foreach ($config as $section) {
            if ($section['MIGX_formname'] === $section_name) {
                foreach ($section as $k => $v) {
                    if (!is_array($v) && strpos($v, '[{') !== false) {
                        $section[$k] = $mpc->render->jsonDecodeValue(json_decode($v, 1));
                    }
                }
                return $section;
            }
        }
    }
}