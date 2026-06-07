<?php
/**
 * Манифест системных настроек. Применение:
 *   ./console/mpc settings apply [--dry-run]   # файл settings.php из базы манифестов
 *
 * Upsert по key: есть → обновляется при отличии значения; нет → создаётся.
 * Удаление настроек НЕ выполняется. Краткая форма 'key' => value или полная
 * 'key' => ['value'=>.., 'xtype'=>.., 'namespace'=>.., 'area'=>..].
 */
return [
    // краткая форма (обновление существующих)
    'mpc_use_lexicons'   => true,
    'mpc_default_language' => 'ru',

    // база манифестов mpc CLI (относительно папки core/ или абсолютный путь).
    // Можно переопределить на лету переменной окружения MPC_MANIFESTS_PATH.
    'mpc_manifests_path' => 'components/migxpageconfigurator/console/manifests/',

    // полная форма (для создания новых настроек)
    'my_project_api_key' => [
        'value'     => '',
        'xtype'     => 'textfield',
        'namespace' => 'myproject',
        'area'      => 'myproject:main',
    ],
];
