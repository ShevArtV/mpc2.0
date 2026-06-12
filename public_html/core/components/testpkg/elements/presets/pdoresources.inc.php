<?php

return [
    'default' => [
        'parents' => 1,
        'tpl' => '#/pdoresources/default/item.tpl',
    ],
    'extended' => [
        'extends' => 'default',
        'limit' => 2,
    ],
    'placeholder' => [
        'tpl' => '#/pdoresources/default/item.tpl',
        'resources' => 1
    ],
    'main_categories' => [
        'parents' => 1,
        'showUnpublished' => 1,
        'tpl' => '#/pdoresources/main_categories/item.tpl',
        'includeTVs' => 'img,is_hit',
        'tvPrefix' => '',
        'sortby' => ['menuindex' => 'ASC'],
        'limit' => 5
    ]
];
