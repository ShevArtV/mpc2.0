<?php
/**
 * Манифест системных настроек. Применение:
 *   php console/mpc.php settings apply путь/к/settings.php [--dry-run]
 *
 * Upsert по key: есть → обновляется при отличии значения; нет → создаётся.
 * Удаление настроек НЕ выполняется. Краткая форма 'key' => value или полная
 * 'key' => ['value'=>.., 'xtype'=>.., 'namespace'=>.., 'area'=>..].
 */
return [
    // краткая форма (обновление существующих)
    'mpc_use_lexicons'   => true,
    'mpc_default_language' => 'ru',

    // полная форма (для создания новых настроек)
    'my_project_api_key' => [
        'value'     => '',
        'xtype'     => 'textfield',
        'namespace' => 'myproject',
        'area'      => 'myproject:main',
    ],
];
