<?php

return [
    // Мастер-выключатель пакета
    'mpcve_active' => [
        'xtype' => 'combo-boolean',
        'value' => true,
        'area'  => 'main',
    ],
    // Query-параметр включения edit-режима (?mpcedit=1)
    'mpcve_edit_param' => [
        'xtype' => 'textfield',
        'value' => 'mpcedit',
        'area'  => 'main',
    ],
    // Имя permission, требуемого для редактирования
    'mpcve_permission' => [
        'xtype' => 'textfield',
        'value' => 'mpcve_edit',
        'area'  => 'main',
    ],
    // Лимит размера загружаемого изображения (байт), 0 — без лимита
    'mpcve_max_upload' => [
        'xtype' => 'numberfield',
        'value' => 10485760,
        'area'  => 'upload',
    ],
    // Блокировка ресурса: TTL лока (сек) = idle-таймаут. Heartbeat продлевает,
    // пока идёт правка; без активности лок протухает и режим авто-завершается.
    'mpcve_lock_ttl' => [
        'xtype' => 'numberfield',
        'value' => 300,
        'area'  => 'main',
    ],
    // Белый список HTML-атрибутов для sanitizeHtml редактора (csv). Предзаполнен
    // безопасным набором — разработчику не нужно искать дефолты в коде. Пусто →
    // fallback DEFAULT_ALLOWED_ATTRS в rte.js. on*/javascript:/опасный style
    // режутся всегда (жёстко в коде, не через эту настройку).
    'mpcve_allowed_attrs' => [
        'xtype' => 'textfield',
        'value' => 'class,id,title,dir,lang,role,style,href,target,rel,name,download,src,alt,width,height,srcset,sizes,loading,decoding,type,media,start,colspan,rowspan,scope,datetime',
        'area'  => 'editor',
    ],
];
