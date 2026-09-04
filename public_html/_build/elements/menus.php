<?php

return [
    'MPC Лексиконы' => [
        'description' => 'Управление лексиконами ресурсов',
        'action'      => 'index',
        'icon'        => '',
        'menuindex'   => 0,
        'params'      => '',
        'handler'     => '',
        /* Гейт пункта меню. Контроллер и так требует components + mpc_view, но
           без этого поля пункт висит в меню у всех, включая тех, кому CMP
           недоступен. */
        'permissions' => 'mpc_view',
        'parent'      => 'components',
    ],
];
