<?php

/**
 * Конфигурация для тестов — возвращается ModxStub::getOption()
 *
 * Пути:
 *  corePath            — реальный путь к ядру MODX (нужен для чтения samples/)
 *  pdotoolsElementsPath — переопределяется в тестах через $baseProperties
 */

$fixturesDir = __DIR__;
$packagePath = dirname(__DIR__, 5); // .../public_html/core/components/migxpageconfigurator
$corePath    = dirname(__DIR__, 6) . '/'; // .../public_html/core/

return [
    // Базовые пути MODX
    'core_path'   => $corePath,
    'assets_path' => '',

    // pdotools_elements_path задаётся в baseProperties теста (смотри setUp),
    // но здесь тоже нужен дефолт для getOption()
    'pdotools_elements_path' => $fixturesDir . '/',

    // ===== Base.php =====
    'mpc_translated_content'    => 'text,image,poster,video,audio',
    'mpc_common_config_name'    => 'mpc_config',
    'mpc_base_section_name'     => 'mpc_base',
    'mpc_static_block_page_id'  => 1,
    'mpc_path_to_sections'      => 'output/sections/',
    'mpc_contacts_page_id'      => 1,
    'mpc_contacts_tv_name'      => 'contacts',
    'mpc_contacts_tv_id'        => 0,
    'mpc_use_lexicons'          => false,
    'mpc_default_language'      => 'ru',

    // ===== Cutter.php =====
    'mpc_path_to_chunks'     => 'output/chunks/',
    'mpc_wrapper_name'       => 'wrapper',
    'mpc_thumb_format'       => 'png',
    'mpc_fake_img_path'      => 'assets/components/migxpageconfigurator/images/fake-img.png',
    'mpc_path_to_presets'    => 'presets-nonexistent/', // папки нет — пресеты не загружаются
    'mpc_path_to_samples'    => 'components/migxpageconfigurator/elements/samples/',
    'mpc_common_thumb_params' => '',
    'mpc_thumb_snippet'      => '', // без thumb сниппета — упрощает тест

    // lazy load и expand выключены для чистых снапшотов
    'mpc_lazyload_attr'     => '',
    'mpc_lazyload_enabled'  => false,
    'mpc_expand_attr'       => '',
    'mpc_expand_enabled'    => false,

    // ===== Grabber.php =====
    'mpc_exclude_lexicons_filename' => '',
    'mpc_download_paths'            => [],
    'mpc_allowed_tags'              => '',
    'site_start'                    => 1,
    'mpc_phone_regexp'              => '/[\s\-\(\)]/u',
    'mpc_phone_format'              => '',
    'mpc_lexicon_path'              => '',
    'mpc_resource_lexicon_keys_path' => '',
    'mpc_allow_modx_tags'           => false,
    'mpc_download_extensions'       => '',
    'mpc_lexicon_filename_field'    => 'id',
];
