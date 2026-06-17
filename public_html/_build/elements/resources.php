<?php

return [
    // Ресурс контактов больше не пакуется как vehicle — он создаётся/резолвится
    // в resolvers/4systemsettings.php (зависит от настройки mpc_contacts_page_alias:
    // дефолтный 'contacts', привязка к существующему ресурсу или создание нового).
    'web' => [
        'page-types' => [
            'pagetitle' => 'Типы страниц',
            'template' => 0,
            'hidemenu' => true,
            'published' => false,
            'richtext' => false
        ],
    ],
];
