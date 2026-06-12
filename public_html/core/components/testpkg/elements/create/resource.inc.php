<?php
// /usr/local/php/php-7.4/bin/php -d display_errors -d error_reporting=E_ALL /home/host1860015/art-sites.ru/htdocs/customfactory/core/components/migxpageconfigurator/console/mgr_elems.php resource
return [
    'web' => [
        [
            'pagetitle' => 'Товары',
            'alias' => 'tovaryi',
            'hidemenu' => false,
            'published' => true,
            'parent' => 0,
            'template' => 0,
            'file_name' => '',
            'tvs' => [],
            'content' => '',
            'resources' => [
                [
                    'pagetitle' => 'Создать новый',
                    'alias' => 'vidyi-produkczii',
                    'hidemenu' => false,
                    'published' => true,
                    'class_key' => 'msCategory',
                    'file_name' => 'category.tpl',
                    'resources' => [
                        [
                            'pagetitle' => 'Декоративные подушки',
                            'menutitle' => 'Подушка',
                            'alias' => 'dekorativnyie-podushki',
                            'hidemenu' => true,
                            'published' => true,
                            'class_key' => 'msCategory',
                            'file_name' => 'category.tpl',
                        ],
                        [
                            'pagetitle' => 'Интерьерные картины',
                            'menutitle' => 'Картина',
                            'alias' => 'interernyie-kartinyi',
                            'hidemenu' => true,
                            'published' => true,
                            'class_key' => 'msCategory',
                            'file_name' => 'category.tpl',
                        ],
                        [
                            'pagetitle' => 'Дорожки',
                            'menutitle' => 'Дорожки',
                            'alias' => 'dorozhka',
                            'hidemenu' => true,
                            'published' => true,
                            'class_key' => 'msCategory',
                            'file_name' => 'category.tpl',
                        ],
                        [
                            'pagetitle' => 'Фотообои',
                            'menutitle' => 'Фотообои',
                            'alias' => 'fotooboi',
                            'hidemenu' => true,
                            'published' => true,
                            'class_key' => 'msCategory',
                            'file_name' => 'category.tpl',
                        ],
                        [
                            'pagetitle' => 'Постеры',
                            'menutitle' => 'Постеры',
                            'alias' => 'posteryi',
                            'hidemenu' => true,
                            'published' => true,
                            'class_key' => 'msCategory',
                            'file_name' => 'category.tpl',
                        ],
                        [
                            'pagetitle' => 'Сумки-шопперы',
                            'menutitle' => 'Сумка',
                            'alias' => 'sumki-shopperyi',
                            'hidemenu' => true,
                            'published' => false,
                            'class_key' => 'msCategory',
                            'file_name' => 'category.tpl',
                        ],
                        [
                            'pagetitle' => 'Промо',
                            'menutitle' => 'Открытка',
                            'alias' => 'promo',
                            'hidemenu' => true,
                            'published' => false,
                            'class_key' => 'msCategory',
                            'file_name' => 'category.tpl',
                        ],
                    ]
                ]
            ]
        ],
    ],
];