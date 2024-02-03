<?php

return [
    'test_sbl' => [
        'type' => 'superboxselect',
        'caption' => 'Связанные статьи',
        'description' => '',
        'category' => 'Связанные ресурсы',
        'input_properties' => [
            'selectType' => 'resources',
            'where' => '[{"template:=":"4"}]'
        ],
        'elements' => '',
        'templates' => [
            'Главная'
        ],
    ],
    'test_img' => [
        'type' => 'image',
        'caption' => 'Картинка',
        'description' => '',
        'category' => 'MigxPageConfigurator',
        'templates' => [
            'Пустой шаблон'
        ],
        'resources' => [
            'page-types' => 'assets/img.jpg'
        ]
    ],
    'test_migx' => [
        'type' => 'migx',
        'caption' => 'Конфигурация страницы',
        'description' => '',
        'category' => 'MigxPageConfigurator',
        'input_properties' => [
            'configs' => 'config',
        ],
        'templates' => [
            'Вывод содержимого',
            'Пустой шаблон'
        ],
    ],
    'test_migx2' => [
        'type' => 'migx',
        'caption' => 'Блоки',
        'description' => 'Описание ТВ',
        'category' => 'Блоки на главной',
        'input_properties' => [
            'formtabs' => [
                [
                    'caption' => 'Блоки',
                    'fields' => [
                        [
                            'field' => 'block_title',
                            'caption' => 'Заголовок'
                        ],
                        [
                            'field' => 'block_description',
                            'caption' => 'Описание'
                        ],
                        [
                            'field' => 'block_image',
                            'caption' => 'Картинка',
                            'inputTVtype' => 'image'
                        ]
                    ]
                ]
            ],
            'columns' => [
                [
                    'header' => 'Картинка',
                    'dataIndex' => 'block_image',
                    'renderer' => 'this.renderImage'
                ],
                [
                    'header' => 'Заголовок',
                    'dataIndex' => 'block_title'
                ],
                [
                    'header' => 'Описание',
                    'dataIndex' => 'block_description'
                ]
            ]
        ],
        'templates' => [
            'Вывод содержимого'
        ],
    ],
];