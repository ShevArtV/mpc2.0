<?php
/**
 * Манифест настроек ClientConfig (cgSetting / cgContextValue). Применение:
 *   ./console/mpc clientconfig apply [--dry-run]   # файл clientconfig.php из базы манифестов
 *
 * Upsert по ключу cgSetting. Формы записи:
 *   'key' => value                                  — краткая (базовое значение cgSetting);
 *   'key' => ['value'=>.., 'xtype'=>.., 'group'=>.., 'namespace'=>.., 'area'=>..] — полная;
 *   'key' => ['value'=>.., 'context'=>'web']        — контекстное значение (cgContextValue).
 * 'context' НЕОБЯЗАТЕЛЕН: без него пишется базовое значение настройки.
 * 'group' (id или label/имя группы cgGroup) — только при создании НОВОЙ настройки.
 *
 * Список: ./console/mpc clientconfig list [--group=Контакты]
 *
 * Требуется установленный пакет ClientConfig (иначе команда вернёт ошибку).
 */
return [
    // краткая форма — базовое значение существующей настройки
    'phone' => '8 800 000-00-00',

    // полная форма — новая настройка в группе
    'company_name' => [
        'value' => 'ООО Ромашка',
        'xtype' => 'textfield',
        'group' => 'Контакты',
    ],

    // контекстное переопределение (для контекста web)
    'phone_secondary' => [
        'value'   => '8 800 111-11-11',
        'context' => 'web',
    ],
];
