<?php

return [
    'getCall' => [
        'file' => 'snippet.getcall',
        'description' => 'формирует вызов сниппета с указанным пресетом',
        'properties' => []
    ],
    'getContacts' => [
        'file' => 'snippet.getcontacts',
        'description' => 'получает контакты в формате массива',
        'properties' => []
    ],
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
    'include' => [
        'file' => 'snippet.include',
        'description' => 'работает как модификатор, заменяет во включаемом чанке ## на {',
        'properties' => []
    ],
];