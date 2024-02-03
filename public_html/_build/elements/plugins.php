<?php

return [
    'MigxPageConfigurator' => [
        'file' => 'plugin.migxpageconfigurator',
        'description' => '',
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
];