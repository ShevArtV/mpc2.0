<?php
/*
 * Build-конфиг пакета mpcVisualEditor (mpcVE).
 * Живёт в том же dev-сайте, что и migxpageconfigurator, но собирается отдельно:
 *
 *   php _build/build.php mpcvisualeditor
 *   /usr/local/php/php-7.4/bin/php _build/build.php mpcvisualeditor
 *
 * Сам определяет MODX_CORE_PATH (walk-up) и переопределяет elements/resolvers/build
 * на свой подкаталог `_build/mpcvisualeditor/`, чтобы не пересекаться с mpc.
 */
if (!defined('MODX_CORE_PATH')) {
    $path = dirname(__FILE__);
    while (!file_exists($path . '/core/config/config.inc.php') && (strlen($path) > 1)) {
        $path = dirname($path);
    }
    define('MODX_CORE_PATH', $path . '/core/');
}
if (!defined('PKG_NAME')) {
    define('PKG_NAME', 'mpcVisualEditor');
}

return [
    'name' => 'mpcVisualEditor',
    'name_lower' => 'mpcvisualeditor',
    'version' => '1.2.6',
    'release' => 'rc',

    // Пакетные ресурсы и сборочные каталоги — свои, не общие с mpc.
    'build' => dirname(__FILE__) . '/',
    'elements' => dirname(__FILE__) . '/elements/',
    'resolvers' => dirname(__FILE__) . '/resolvers/',

    // Install package to site right after build
    'install' => false,
    // Which elements should be updated on package upgrade
    'update' => [
        'chunks' => true,
        'menus' => false,
        'permission' => false,
        'plugins' => true,
        'policies' => false,
        'policy_templates' => false,
        'resources' => false,
        'settings' => false,
        'snippets' => true,
        'templates' => false,
        'widgets' => false,
    ],
    // Which elements should be static by default
    'static' => [
        'plugins' => false,
        'snippets' => false,
        'chunks' => false,
    ],
    // Log settings
    'log_level' => !empty($_REQUEST['download']) ? 0 : 3,
    'log_target' => php_sapi_name() == 'cli' ? 'ECHO' : 'HTML',
    // Download transport.zip after build
    'download' => !empty($_REQUEST['download']),
];
