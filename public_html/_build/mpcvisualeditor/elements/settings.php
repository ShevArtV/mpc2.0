<?php

return [
    // Логирование через mxLogger (вкл/выкл). Включено из коробки. mxLogger — единственный
    // приёмник; стандартный журнал MODX не используется (кроме разового «установите mxLogger»).
    'mpcve_debug' => [
        'xtype' => 'combo-boolean',
        'value' => true,
        'area'  => 'mpcve_general',
    ],
    // Минимальный уровень логирования mxLogger: debug|info|warning|error. По умолчанию error.
    'mpcve_log_level' => [
        'xtype' => 'textfield',
        'value' => 'error',
        'area'  => 'mpcve_general',
    ],
    // Мастер-выключатель пакета
    'mpcve_active' => [
        'xtype' => 'combo-boolean',
        'value' => true,
        'area'  => 'mpcve_general',
    ],
    // Положение панели редактора: top (вверху, по умолчанию) или bottom (внизу).
    'mpcve_toolbar_position' => [
        'xtype' => 'textfield',
        'value' => 'top',
        'area'  => 'mpcve_editor',
    ],
    // Query-параметр включения edit-режима (?mpcedit=1)
    'mpcve_edit_param' => [
        'xtype' => 'textfield',
        'value' => 'mpcedit',
        'area'  => 'mpcve_general',
    ],
    // Имя permission, требуемого для редактирования
    'mpcve_permission' => [
        'xtype' => 'textfield',
        'value' => 'mpcve_edit',
        'area'  => 'mpcve_general',
    ],
    // Лимит размера загружаемого изображения (байт), 0 — без лимита
    'mpcve_max_upload' => [
        'xtype' => 'numberfield',
        'value' => 10485760,
        'area'  => 'mpcve_editor',
    ],
    // «Вставил ссылку → скачалось»: загрузка медиа по внешнему URL из редактора.
    'mpcve_url_download_enabled' => [
        'xtype' => 'combo-boolean',
        'value' => true,
        'area'  => 'mpcve_editor',
    ],
    // Таймаут скачивания по ссылке (сек). Держать ЗАВЕДОМО меньше серверного
    // max_execution_time / fastcgi_read_timeout — иначе фронт упрётся в обрыв
    // соединения вместо понятной ошибки «загрузите вручную». Размер ≤ mpcve_max_upload.
    'mpcve_url_download_timeout' => [
        'xtype' => 'numberfield',
        'value' => 20,
        'area'  => 'mpcve_editor',
    ],
    // Блокировка ресурса: TTL лока (сек) = idle-таймаут. Heartbeat продлевает,
    // пока идёт правка; без активности лок протухает и режим авто-завершается.
    'mpcve_lock_ttl' => [
        'xtype' => 'numberfield',
        'value' => 300,
        'area'  => 'mpcve_general',
    ],
    // Белый список HTML-атрибутов для sanitizeHtml редактора (csv). Применяется
    // ТОЛЬКО к содержимому редактируемого поля (richtext/text/textarea), не ко
    // всей странице. Пусто → fallback DEFAULT_ALLOWED_ATTRS в rte.js. on*/
    // javascript:/опасный style режутся всегда (жёстко в коде, не через настройку).
    'mpcve_allowed_attrs' => [
        'xtype' => 'textfield',
        'value' => 'class,href,src,alt,title',
        'area'  => 'mpcve_editor',
    ],
    // Символы палитры «Ω» в тулбаре RTE: csv имён HTML-сущностей (принимаются
    // «mdash», «&mdash;», числовые «#8594»/«#x2192»). Пусто → дефолтный набор
    // DEFAULT_ENTITIES в rte.js. Неизвестное имя молча пропускается.
    'mpcve_rte_entities' => [
        'xtype' => 'textfield',
        'value' => '',
        'area'  => 'mpcve_editor',
    ],
    // Селекторы «призрачных» строк списка: копии слайдов, которые вставляют в
    // разметку слайдеры в режиме бесконечной прокрутки (Swiper loop, slick).
    // Они несут те же data-mpc-* и сбивают нумерацию строк. Пусто → дефолт
    // GHOST_ROW_SEL в address.js ('.swiper-slide-duplicate, .slick-cloned').
    'mpcve_row_ignore_selectors' => [
        'xtype' => 'textfield',
        'value' => '',
        'area'  => 'mpcve_editor',
    ],
];
