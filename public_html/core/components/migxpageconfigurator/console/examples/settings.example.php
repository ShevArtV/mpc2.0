<?php
/**
 * Манифест настроек MODX (системные + контекстные). Применение:
 *   ./console/mpc settings apply [--dry-run]   # файл settings.php из базы манифестов
 *
 * Upsert по ключу: есть → обновляется при отличии значения; нет → создаётся.
 * Удаление настроек НЕ выполняется. Формы записи:
 *   'key' => value                                    — краткая (системная);
 *   'key' => ['value'=>.., 'xtype'=>.., 'namespace'=>.., 'area'=>..]   — полная;
 *   'key' => ['value'=>.., 'context'=>'web']          — КОНТЕКСТНАЯ (modContextSetting).
 *
 * Список: ./console/mpc settings list [--namespace=myproject] [--context=web]
 */
return [
    // краткая форма (системная настройка, обновление существующих)
    'mpc_use_lexicons'   => true,
    'mpc_default_language' => 'ru',

    // база манифестов mpc CLI (относительно папки core/ или абсолютный путь).
    // Можно переопределить на лету переменной окружения MPC_MANIFESTS_PATH.
    'mpc_manifests_path' => 'components/migxpageconfigurator/console/manifests/',

    // полная форма (для создания новых системных настроек)
    'my_project_api_key' => [
        'value'     => '',
        'xtype'     => 'textfield',
        'namespace' => 'myproject',
        'area'      => 'myproject:main',
    ],

    // контекстная настройка (переопределение для контекста web)
    'mpc_available_languages' => [
        'value'   => 'ru,en',
        'context' => 'web',
    ],
];
