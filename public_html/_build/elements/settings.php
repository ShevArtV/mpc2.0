<?php

return [
    'mpc_debug' => [
        'xtype' => 'combo-boolean',
        'value' => true,
        'area' => 'mpc_general',
    ],
    'mpc_log_level' => [
        'xtype' => 'textfield',
        'value' => 'error',
        'area' => 'mpc_general',
    ],
    'mpc_base_section_name' => [
        'xtype' => 'textfield',
        'value' => 'mpc_base',
        'area' => 'mpc_general',
    ],
    // Категория-владелец автосоздаваемых из шаблона TV (data-mpc-tv). Пусто →
    // категория самого пакета mpc. Чужие (в др. категориях) TV нарезка не трогает.
    'mpc_tv_category' => [
        'xtype' => 'textfield',
        'value' => '',
        'area' => 'mpc_general',
    ],
    'mpc_editable_resource_fields' => [
        'xtype' => 'textfield',
        'value' => 'longtitle,description,introtext,content,menutitle',
        'area' => 'mpc_resource',
    ],
    'mpc_protected_resource_fields' => [
        'xtype' => 'textfield',
        'value' => 'id,class_key,context_key,parent,uri_override,alias,uri,template,pagetitle',
        'area' => 'mpc_resource',
    ],
    'mpc_edit_mode' => [
        'xtype' => 'combo-boolean',
        'value' => false,
        'area' => 'mpc_general',
    ],
    'mpc_media_source' => [
        'xtype' => 'modx-combo-source',
        'value' => '',
        'area' => 'mpc_media',
    ],
    'mpc_common_config_name' => [
        'xtype' => 'textfield',
        'value' => 'mpc_config',
        'area' => 'mpc_general',
    ],
    'mpc_config_tv_id' => [
        'xtype' => 'textfield',
        'value' => '',
        'area' => 'mpc_general',
    ],
    'mpc_contacts_page_id' => [
        'xtype' => 'textfield',
        'value' => '',
        'area' => 'mpc_contacts',
    ],
    'mpc_contacts_tv_id' => [
        'xtype' => 'textfield',
        'value' => '',
        'area' => 'mpc_contacts',
    ],
    'mpc_contacts_tv_name' => [
        'xtype' => 'textfield',
        'value' => 'contacts',
        'area' => 'mpc_contacts',
    ],
    // Псевдоним ресурса контактов. Пусто → при установке создаётся ресурс с
    // псевдонимом 'contacts' на шаблоне «Контакты». Указан существующий →
    // работаем с ним (шаблон не меняем). Указан несуществующий → создаём ресурс
    // на шаблоне «Контакты». См. resolvers/4systemsettings.php.
    'mpc_contacts_page_alias' => [
        'xtype' => 'textfield',
        'value' => '',
        'area' => 'mpc_contacts',
    ],
    // Какие под-поля контакта переводятся (лексиконятся): caption/value/fvalue/
    // attributes через запятую. Пусто → контакты не лексиконятся. value/fvalue
    // переводятся БЕЗ привязки к плейсменту (один перевод на все места).
    'mpc_contact_lexicon_fields' => [
        'xtype' => 'textfield',
        'value' => 'caption',
        'area' => 'mpc_contacts',
    ],
    'mpc_dev_mode' => [
        'xtype' => 'combo-boolean',
        'value' => '0',
        'area' => 'mpc_general',
    ],
    'mpc_fake_img_path' => [
        'xtype' => 'textfield',
        'value' => 'assets/components/migxpageconfigurator/images/fake-img.png',
        'area' => 'mpc_media',
    ],
    'mpc_lazyload_attr' => [
        'xtype' => 'textfield',
        'value' => 'data-lazy',
        'area' => 'mpc_media',
    ],
    'mpc_path_to_chunks' => [
        'xtype' => 'textfield',
        'value' => 'chunks/',
        'area' => 'mpc_paths',
    ],
    'mpc_path_to_dist' => [
        'xtype' => 'textfield',
        'value' => 'parsed/',
        'area' => 'mpc_paths',
    ],
    'mpc_path_to_presets' => [
        'xtype' => 'textfield',
        'value' => 'presets/',
        'area' => 'mpc_paths',
    ],
    'mpc_path_to_sections' => [
        'xtype' => 'textfield',
        'value' => 'sections/',
        'area' => 'mpc_paths',
    ],
    // --- Темы оформления (оверрайд-слой вёрстки секций) --------------------
    // Тема = подпапка-зеркало внутри mpc_path_to_sections: для секции ищется
    // sections/<mpc_themes_subdir>/<тема>/<секция>.tpl, нет файла → базовая
    // вёрстка sections/<секция>.tpl. Контент (mpc_config/лексиконы/медиа) темой
    // НЕ затрагивается. Смена настройки чистит parsed/ через OnCacheUpdate.
    // Активная тема на весь сайт. Пусто → базовая вёрстка (легаси-поведение).
    'mpc_theme' => [
        'xtype' => 'textfield',
        'value' => '',
        'area' => 'mpc_paths',
    ],
    // Переопределение темы по шаблонам, JSON {"templateId":"тема"}. Приоритетнее
    // mpc_theme. Пример: {"5":"dark","12":"summer"}. Пусто → действует mpc_theme.
    'mpc_theme_templates' => [
        'xtype' => 'textarea',
        'value' => '',
        'area' => 'mpc_paths',
    ],
    // Подпапка внутри mpc_path_to_sections, где лежат темы.
    'mpc_themes_subdir' => [
        'xtype' => 'textfield',
        'value' => '_themes/',
        'area' => 'mpc_paths',
    ],
    'mpc_path_to_src' => [
        'xtype' => 'textfield',
        'value' => 'templates/',
        'area' => 'mpc_paths',
    ],
    'mpc_phone_format' => [
        'xtype' => 'textfield',
        'value' => '8 (\2) \3-\4-\5',
        'area' => 'mpc_contacts',
    ],
    'mpc_phone_regexp' => [
        'xtype' => 'textfield',
        'value' => '/(\d)(\d{3})(\d{3})(\d{2})(\d{2})$/',
        'area' => 'mpc_contacts',
    ],
    'mpc_static_block_page_id' => [
        'xtype' => 'textfield',
        'value' => '',
        'area' => 'mpc_general',
    ],
    'mpc_tmplvar_ids' => [
        'xtype' => 'textfield',
        'value' => '',
        'area' => 'mpc_general',
    ],
    'mpc_tpl_file_extension' => [
        'xtype' => 'textfield',
        'value' => '.tpl',
        'area' => 'mpc_paths',
    ],
    'mpc_common_thumb_params' => [
        'xtype' => 'textfield',
        'value' => 'q=90&zc=1&f=webp',
        'area' => 'mpc_media',
    ],
    'mpc_thumb_snippet' => [
        'xtype' => 'textfield',
        'value' => 'mpcThumb',
        'area' => 'mpc_media',
    ],
    'mpc_expand_attr' => [
        'xtype' => 'textfield',
        'value' => 'data-svg',
        'area' => 'mpc_media',
    ],
    'mpc_download_paths' => [
        'xtype' => 'textfield',
        'value' => '{"images":"","videos":"","audios":"","others":""}',
        'area' => 'mpc_media',
    ],
    'mpc_download_extensions' => [
        'xtype' => 'textfield',
        'value' => 'jpg,jpeg,png,webp,mp4,mp3,txt',
        'area' => 'mpc_media',
    ],
    'mpc_mime_to_ext_path' => [
        'xtype' => 'textfield',
        'value' => 'components/migxpageconfigurator/elements/media/mime_to_ext.json',
        'area' => 'mpc_media',
    ],
    'mpc_lazyload_enabled' => [
        'xtype' => 'combo-boolean',
        'value' => '1',
        'area' => 'mpc_media',
    ],
    'mpc_expand_enabled' => [
        'xtype' => 'combo-boolean',
        'value' => '1',
        'area' => 'mpc_media',
    ],
    'mpc_use_lexicons' => [
        'xtype' => 'combo-boolean',
        'value' => '0',
        'area' => 'mpc_lexicons',
    ],
    'mpc_exclude_lexicons_filename' => [
        'xtype' => 'textarea',
        'value' => 'components/migxpageconfigurator/services/exclude_lexicons.inc.php',
        'area' => 'mpc_lexicons',
    ],
    'mpc_exclude_fields_path' => [
        'xtype' => 'textfield',
        'value' => 'components/migxpageconfigurator/elements/fields/exclude_fields.json',
        'area' => 'mpc_general',
    ],
    'mpc_translated_content' => [
        'xtype' => 'textarea',
        'value' => 'text,contact',
        'area' => 'mpc_lexicons',
    ],
    'mpc_lexicon_filename_field' => [
        'xtype' => 'textfield',
        'value' => 'alias',
        'area' => 'mpc_lexicons',
    ],
    'mpc_cmp_resource_label_field' => [
        'xtype' => 'textfield',
        'value' => 'pagetitle',
        'area' => 'mpc_lexicons',
    ],
    'mpc_lexicon_path' => [
        'xtype' => 'textfield',
        'value' => 'components/migxpageconfigurator/lexicon/',
        'area' => 'mpc_lexicons',
    ],
    'mpc_lexicons_namespace' => [
        'xtype' => 'textfield',
        'value' => 'migxpageconfigurator',
        'area' => 'mpc_lexicons',
    ],
    'mpc_available_languages' => [
        'xtype' => 'textfield',
        'value' => '',
        'area' => 'mpc_lexicons',
    ],
    'mpc_default_language' => [
        'xtype' => 'textfield',
        'value' => 'ru',
        'area' => 'mpc_lexicons',
    ],
    'mpc_arbitrary_lexicon_topics' => [
        'xtype' => 'textfield',
        'value' => '',
        'area' => 'mpc_lexicons',
    ],
    'mpc_lang_cookie_name' => [
        'xtype' => 'textfield',
        'value' => 'mpc_lang',
        'area' => 'mpc_lexicons',
    ],
    'mpc_lang_cookie_domain' => [
        'xtype' => 'textfield',
        'value' => '',
        'area' => 'mpc_lexicons',
    ],
    // Выставлять язык (cultureKey + cookie) на OnHandleRequest силами пакета.
    // Вкл (по умолчанию) — пакетный плагин зовёт setLanguageSettings рано. Выкл —
    // пакет язык не трогает; проект делает это сам (например, после switchContext).
    // Тонкая настройка/skip без полного выключения — через событие
    // mpcOnBeforeSetLanguageSettings (см. setLanguageSettings).
    'mpc_set_language_on_request' => [
        'xtype' => 'combo-boolean',
        'value' => true,
        'area' => 'mpc_lexicons',
    ],
    // База манифестов mpc CLI (относительно папки core/ или абсолют). Читается
    // ManifestLoader::baseDir (env MPC_MANIFESTS_PATH > эта настройка > дефолт).
    'mpc_manifests_path' => [
        'xtype' => 'textfield',
        'value' => 'components/migxpageconfigurator/console/manifests/',
        'area' => 'mpc_paths',
    ],
    'mpc_allowed_tags' => [
        'xtype' => 'textarea',
        'value' => 'b,p,span,h1,h2,h3,h4,h5,h6,small,i,blockquote,mark,ul,li,ol,img,button,a,u,s',
        'area' => 'mpc_general',
    ],
    'mpc_allow_modx_tags' =>  [
        'xtype' => 'combo-boolean',
        'value' => '0',
        'area' => 'mpc_general',
    ],
    'mpc_wrapper_name' => [
        'xtype' => 'textfield',
        'value' => 'wrapper',
        'area' => 'mpc_paths',
    ],
    'mpc_path_to_samples' => [
        'xtype' => 'textfield',
        'value' => 'components/migxpageconfigurator/elements/samples/',
        'area' => 'mpc_paths',
    ],
];
