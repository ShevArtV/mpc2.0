<?php

return [
    'getParsedConfigPath' => [
        'file' => 'snippet.getparsedconfigpath',
        'description' => 'получает путь к распарсенному файлу страницы, при отсутствии парсит конфигурацию',
        'properties' => []
    ],
    'getStaticSection' => [
        'file' => 'snippet.getstaticsection',
        'description' => 'получает данные статичной секции',
        'properties' => []
    ],
    'widgetHandler' => [
        'file' => 'snippet.widgethandler',
        'description' => 'обрабатывает запрос с виджетов',
        'properties' => []
    ],
    'mpcThumb' => [
        'file' => 'snippet.mpcthumb',
        'description' => 'обёртка над pThumb с проверкой существования файла',
        'properties' => []
    ],
];
