<?php
$excludeLexiconFields = [
    'MIGX_id', 'MIGX_formname', 'id', 'section_name', 'file_name', 'is_static', 'limit',
    'inline_styles', 'class_names', 'css_file_path',
    'resources', '*_resources',

    'picture', 'img', 'video', 'poster', 'preview', 'bg',
    '*_picture', '*_img', '*_video', '*_poster', '*_preview', '*_bg',
    'picture_*', 'img_*', 'video_*', 'poster_*', 'preview_*', 'bg_*',

    'structure_content',
    'list_triple*content*',
    'list_compare_product_*',
    'list_compare_*_product_*',
    'list_double_picture*content*',
];
