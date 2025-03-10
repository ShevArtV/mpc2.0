<?php

return [
    'mpc_base_section_name' => [
        'xtype' => 'textfield',
        'value' => 'mpc_base',
        'area' => 'default',
    ],
    'mpc_common_config_name' => [
        'xtype' => 'textfield',
        'value' => 'mpc_config',
        'area' => 'default',
    ],
    'mpc_config_tv_id' => [
        'xtype' => 'textfield',
        'value' => '',
        'area' => 'default',
    ],
    'mpc_contacts_page_id' => [
        'xtype' => 'textfield',
        'value' => '',
        'area' => 'default',
    ],
    'mpc_contacts_tv_id' => [
        'xtype' => 'textfield',
        'value' => '',
        'area' => 'default',
    ],
    'mpc_contacts_tv_name' => [
        'xtype' => 'textfield',
        'value' => 'contacts',
        'area' => 'default',
    ],
    'mpc_copy_config_tv_name' => [
        'xtype' => 'textfield',
        'value' => 'copy_sections',
        'area' => 'default',
    ],
    'mpc_dev_mode' => [
        'xtype' => 'combo-boolean',
        'value' => '0',
        'area' => 'default',
    ],
    'mpc_fake_img_path' => [
        'xtype' => 'textfield',
        'value' => 'assets/components/migxpageconfigurator/images/fake-img.png',
        'area' => 'default',
    ],
    'mpc_lazyload_attr' => [
        'xtype' => 'textfield',
        'value' => 'data-lazy',
        'area' => 'default',
    ],
    'mpc_path_to_chunks' => [
        'xtype' => 'textfield',
        'value' => 'chunks/',
        'area' => 'default',
    ],
    'mpc_path_to_create' => [
        'xtype' => 'textfield',
        'value' => 'create/',
        'area' => 'default',
    ],
    'mpc_path_to_dist' => [
        'xtype' => 'textfield',
        'value' => 'parsed/',
        'area' => 'default',
    ],
    'mpc_path_to_presets' => [
        'xtype' => 'textfield',
        'value' => 'presets/',
        'area' => 'default',
    ],
    'mpc_path_to_sections' => [
        'xtype' => 'textfield',
        'value' => 'sections/',
        'area' => 'default',
    ],
    'mpc_path_to_src' => [
        'xtype' => 'textfield',
        'value' => 'templates/',
        'area' => 'default',
    ],
    'mpc_phone_format' => [
        'xtype' => 'textfield',
        'value' => '8 (\2) \3-\4-\5',
        'area' => 'default',
    ],
    'mpc_phone_regexp' => [
        'xtype' => 'textfield',
        'value' => '/(\d)(\d{3})(\d{3})(\d{2})(\d{2})$/',
        'area' => 'default',
    ],
    'mpc_static_block_page_id' => [
        'xtype' => 'textfield',
        'value' => '',
        'area' => 'default',
    ],
    'mpc_thumb_format' => [
        'xtype' => 'textfield',
        'value' => 'webp',
        'area' => 'default',
    ],
    'mpc_tmplvar_ids' => [
        'xtype' => 'textfield',
        'value' => '',
        'area' => 'default',
    ],
    'mpc_tpl_file_extension' => [
        'xtype' => 'textfield',
        'value' => '.tpl',
        'area' => 'default',
    ],
    'mpc_common_thumb_params' => [
        'xtype' => 'textfield',
        'value' => 'q=90&zc=1&f=webp',
        'area' => 'default',
    ],
    'mpc_thumb_snippet' => [
        'xtype' => 'textfield',
        'value' => 'pThumb',
        'area' => 'default',
    ],
    'mpc_expand_attr' => [
        'xtype' => 'textfield',
        'value' => 'data-svg',
        'area' => 'default',
    ],
    'mpc_images_path' => [
        'xtype' => 'textfield',
        'value' => '',
        'area' => 'default',
    ],
    'mpc_lazyload_enabled' => [
        'xtype' => 'combo-boolean',
        'value' => '1',
        'area' => 'default',
    ],
    'mpc_expand_enabled' => [
        'xtype' => 'combo-boolean',
        'value' => '1',
        'area' => 'default',
    ],
    'mpc_use_lexicons' => [
        'xtype' => 'combo-boolean',
        'value' => '0',
        'area' => 'default',
    ],
    'mpc_exclude_lexicons_filename' => [
        'xtype' => 'textarea',
        'value' => 'components/migxpageconfigurator/services/exclude_lexicons.inc.php',
        'area' => 'default',
    ],
    'mpc_translated_content' => [
        'xtype' => 'textarea',
        'value' => 'text,image,poster,video,audio',
        'area' => 'default',
    ],
    'mpc_lexicon_filename_field' => [
        'xtype' => 'textfield',
        'value' => 'alias',
        'area' => 'default',
    ],
    'mpc_lexicon_path' => [
        'xtype' => 'textfield',
        'value' => 'components/migxpageconfigurator/lexicon/',
        'area' => 'default',
    ],
    'mpc_lexicons_namespace' => [
        'xtype' => 'textfield',
        'value' => 'migxpageconfigurator',
        'area' => 'default',
    ],
    'mpc_available_languages' => [
        'xtype' => 'textfield',
        'value' => '',
        'area' => 'default',
    ],
    'mpc_default_language' => [
        'xtype' => 'textfield',
        'value' => 'ru',
        'area' => 'default',
    ],
    'mpc_allowed_tags' => [
        'xtype' => 'textarea',
        'value' => '',
        'area' => 'default',
    ],
    'mpc_allow_modx_tags' =>  [
        'xtype' => 'combo-boolean',
        'value' => '0',
        'area' => 'default',
    ],
];
