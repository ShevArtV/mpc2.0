<?php
/**
 * @package mpcvisualeditor
 * @subpackage lexicon
 */
$_lang['mpcvisualeditor'] = 'mpcVisualEditor';
$_lang['setting_mpcve_debug'] = 'Включить логирование (mxLogger)?';
$_lang['setting_mpcve_debug_desc'] = 'Логи пишутся в mxLogger (единственный приёмник). Включено из коробки. По умолчанию: Да.';
$_lang['setting_mpcve_log_level'] = 'Минимальный уровень логирования';
$_lang['setting_mpcve_log_level_desc'] = 'Записи ниже уровня не пишутся в mxLogger: debug, info, warning, error. По умолчанию: error. Требует mxLogger.';
$_lang['mpcve_edit'] = 'Редактирование контента с фронта (mpcVisualEditor)';

$_lang['mpcve_edit_mode_on'] = 'Режим редактирования';
$_lang['mpcve_save'] = 'Сохранить';
$_lang['mpcve_cancel'] = 'Отмена';
$_lang['mpcve_saved'] = 'Сохранено';
$_lang['mpcve_save_error'] = 'Ошибка сохранения';
$_lang['mpcve_err_permission'] = 'Недостаточно прав для редактирования этого контента.';
$_lang['mpcve_err_address'] = 'Некорректный адрес поля.';

$_lang['mpcve_uploaded'] = 'Файл загружен';
$_lang['mpcve_err_upload'] = 'Не удалось загрузить файл.';
$_lang['mpcve_err_upload_ext'] = 'Недопустимый тип файла.';
$_lang['mpcve_err_upload_size'] = 'Файл слишком большой.';
$_lang['mpcve_err_source'] = 'Источник медиа не найден (mpc_media_source / default_media_source).';

$_lang['mpcve_fm_created'] = 'Папка создана';
$_lang['mpcve_fm_renamed'] = 'Переименовано';
$_lang['mpcve_fm_removed'] = 'Удалено';
$_lang['mpcve_fm_err_name'] = 'Не указано имя.';
$_lang['mpcve_fm_err_type_dir'] = 'В эту папку нельзя загружать файлы такого типа.';

// --- Заголовки групп настроек ---
$_lang['area_mpcve_general'] = 'Основные';
$_lang['area_mpcve_editor']  = 'Редактор';

// --- Настройки ---
$_lang['setting_mpcve_active'] = 'Включить mpcVisualEditor';
$_lang['setting_mpcve_active_desc'] = 'Мастер-выключатель пакета. Выкл — режим редактирования недоступен, на фронт ничего не подключается. Для работы также требуется включённая настройка mpc_edit_mode (пакет MigxPageConfigurator) и перенарезка страниц — иначе в чанках нет data-mpc-* маркеров и редактор не подключится (предупреждение пишется в системный лог).';
$_lang['setting_mpcve_toolbar_position'] = 'Положение панели редактора';
$_lang['setting_mpcve_toolbar_position_desc'] = 'Где закреплена панель (тулбар) редактора: top — вверху страницы (по умолчанию), bottom — внизу. Панель можно свернуть кнопкой на самой панели (состояние запоминается).';
$_lang['setting_mpcve_edit_param'] = 'Query-параметр входа в режим правки';
$_lang['setting_mpcve_edit_param_desc'] = 'Имя GET-параметра для включения редактора на странице. По умолчанию mpcedit (→ ?mpcedit=1).';
$_lang['setting_mpcve_permission'] = 'Permission для редактирования';
$_lang['setting_mpcve_permission_desc'] = 'Имя MODX-разрешения, проверяемого при входе в режим правки и сохранении. По умолчанию mpcve_edit (регистрируется пакетом в политике Administrator).';
$_lang['setting_mpcve_lock_ttl'] = 'TTL блокировки ресурса (сек)';
$_lang['setting_mpcve_lock_ttl_desc'] = 'Время жизни блокировки = idle-таймаут. Heartbeat продлевает её, пока идёт правка; без активности лок протухает и режим авто-завершается. По умолчанию 300.';
$_lang['setting_mpcve_max_upload'] = 'Лимит загружаемого изображения (байт)';
$_lang['setting_mpcve_max_upload_desc'] = 'Максимальный размер файла при загрузке из редактора. По умолчанию 10485760 (10 МБ). 0 — без лимита.';
$_lang['setting_mpcve_allowed_attrs'] = 'Белый список HTML-атрибутов';
$_lang['setting_mpcve_allowed_attrs_desc'] = 'Атрибуты, сохраняемые при очистке содержимого правимого поля (richtext/text/textarea), через запятую. Пусто — fallback-список в rte.js. Обработчики on*, javascript: и опасный style режутся всегда.';
