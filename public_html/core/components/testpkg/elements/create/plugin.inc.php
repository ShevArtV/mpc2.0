<?php

return [
    'testPlugin' => [
        'file' => 'plugin.test',
        'description' => '',
        'categoryName' => 'Категория плагинов',
        'events' => [
            'OnDocFormDelete' => [],
            'OnCacheUpdate' => [],
            'OnResourceUndelete' => [],
            'OnDocFormSave' => [],
            'OnDocFormPrerender' => [],
            'OnLoadWebDocument' => [],
            'OnPackageInstall' => [],
        ],
    ],
    'testStaticPlugin' => [
        'file' => 'plugin.static_test',
        'description' => '',
        'static' => 1,
        'events' => [
            'OnLoadWebDocument' => [],
            'OnPackageInstall' => [],
        ],
    ],
];