<?php
/**
 * Манифест плагинов. Применение:
 *   php console/mpc.php plugins apply путь/к/plugins.php [--dry-run]
 *
 * ДЕКЛАРАТИВНО. Для каждого плагина:
 *   - если задан 'file' — плагин создаётся/обновляется (код из
 *     core/elements/plugins/<file>.php, категория, static);
 *   - набор событий приводится РОВНО к указанному (недостающие — привязываются,
 *     лишние — отвязываются). Плагины вне манифеста не трогаются.
 *
 * Перед боевым запуском прогоните --dry-run (events — это ПОЛНЫЙ желаемый набор!).
 */
return [
    // Полная спека: создаём/обновляем плагин и синхронизируем события.
    'MyPlugin' => [
        'file'         => 'plugin.myplugin',   // core/elements/plugins/plugin.myplugin.php
        'description'  => 'Описание плагина',
        'categoryName' => 'Мои плагины',        // опц. (создастся, если нет)
        'static'       => 1,                    // опц. (привязка к файлу-исходнику)
        'events'       => [
            'OnDocFormSave',
            'OnLoadWebDocument',
        ],
    ],

    // Короткая форма: только синк событий у уже существующего плагина.
    'ExistingPlugin' => [
        'OnDocFormDelete',
        'OnHandleRequest',
    ],
];
