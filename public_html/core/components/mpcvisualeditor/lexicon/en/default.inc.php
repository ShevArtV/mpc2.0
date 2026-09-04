<?php
/**
 * @package mpcvisualeditor
 * @subpackage lexicon
 */
$_lang['mpcvisualeditor'] = 'mpcVisualEditor';
$_lang['setting_mpcve_debug'] = 'Enable logging (mxLogger)?';
$_lang['setting_mpcve_debug_desc'] = 'Logs are written to mxLogger (the only sink). Enabled out of the box. Default: Yes.';
$_lang['setting_mpcve_log_level'] = 'Minimum log level';
$_lang['setting_mpcve_log_level_desc'] = 'Records below this level are not written to mxLogger: debug, info, warning, error. Default: error. Requires mxLogger.';
$_lang['mpcve_edit'] = 'Edit content from frontend (mpcVisualEditor)';

$_lang['mpcve_edit_mode_on'] = 'Edit mode';
$_lang['mpcve_save'] = 'Save';
$_lang['mpcve_cancel'] = 'Cancel';
$_lang['mpcve_saved'] = 'Saved';
$_lang['mpcve_save_error'] = 'Save failed';
$_lang['mpcve_err_permission'] = 'You do not have permission to edit this content.';
$_lang['mpcve_err_address'] = 'Invalid field address.';

$_lang['mpcve_uploaded'] = 'File uploaded';
$_lang['mpcve_err_upload'] = 'File upload failed.';
$_lang['mpcve_err_upload_ext'] = 'Invalid file type.';
$_lang['mpcve_err_upload_size'] = 'File is too large.';
$_lang['mpcve_err_source'] = 'Media source not found (mpc_media_source / default_media_source).';

$_lang['mpcve_url_download'] = 'Upload by URL';
$_lang['mpcve_url_placeholder'] = 'Paste a file link (http/https)';
$_lang['mpcve_url_downloading'] = 'Downloading…';
$_lang['mpcve_err_url_disabled'] = 'Upload by URL is disabled.';
$_lang['mpcve_err_url_invalid'] = 'Provide a valid http(s) link.';
$_lang['mpcve_err_url_unsafe'] = 'The link was rejected for security reasons.';
$_lang['mpcve_err_url_toolarge'] = 'The linked file is too large — download and upload it manually.';
$_lang['mpcve_err_url_http'] = 'Source unavailable (server response error).';
$_lang['mpcve_err_url_failed'] = 'Could not download the file by URL. Try uploading manually.';

$_lang['mpcve_fm_created'] = 'Folder created';
$_lang['mpcve_fm_renamed'] = 'Renamed';
$_lang['mpcve_fm_removed'] = 'Removed';
$_lang['mpcve_fm_err_name'] = 'Name is required.';
$_lang['mpcve_fm_err_type_dir'] = 'This folder does not accept files of this type.';

// --- Settings area headers ---
$_lang['area_mpcve_general'] = 'General';
$_lang['area_mpcve_editor']  = 'Editor';

// --- Settings ---
$_lang['setting_mpcve_active'] = 'Enable mpcVisualEditor';
$_lang['setting_mpcve_active_desc'] = 'Package master switch. Off — edit mode is unavailable and nothing is loaded on the frontend. It also requires the mpc_edit_mode setting (MigxPageConfigurator package) to be enabled and pages re-cut — otherwise chunks contain no data-mpc-* markers and the editor will not load (a warning is written to the system log).';
$_lang['setting_mpcve_toolbar_position'] = 'Editor panel position';
$_lang['setting_mpcve_toolbar_position_desc'] = 'Where the editor toolbar is pinned: top — at the top of the page (default), bottom — at the bottom. The panel can be collapsed with a button on it (state is remembered).';
$_lang['setting_mpcve_edit_param'] = 'Edit-mode query parameter';
$_lang['setting_mpcve_edit_param_desc'] = 'GET parameter name that turns the editor on for a page. Default mpcedit (→ ?mpcedit=1).';
$_lang['setting_mpcve_permission'] = 'Permission required to edit';
$_lang['setting_mpcve_permission_desc'] = 'Name of the MODX permission checked on entering edit mode and on save. Default mpcve_edit (registered by the package in the Administrator policy).';
$_lang['setting_mpcve_lock_ttl'] = 'Resource lock TTL (sec)';
$_lang['setting_mpcve_lock_ttl_desc'] = 'Lock lifetime = idle timeout. A heartbeat extends it while editing; with no activity the lock expires and edit mode auto-ends. Default 300.';
$_lang['setting_mpcve_max_upload'] = 'Image upload limit (bytes)';
$_lang['setting_mpcve_max_upload_desc'] = 'Maximum file size when uploading from the editor. Default 10485760 (10 MB). 0 — no limit.';
$_lang['setting_mpcve_allowed_attrs'] = 'HTML attributes whitelist';
$_lang['setting_mpcve_allowed_attrs_desc'] = 'Attributes kept when sanitizing edited field content (richtext/text/textarea), comma-separated. Empty — fallback list in rte.js. on* handlers, javascript: and unsafe style are always stripped.';
$_lang['setting_mpcve_rte_entities'] = 'Editor character palette';
$_lang['setting_mpcve_rte_entities_desc'] = 'HTML entities for the toolbar «Ω» button, comma-separated and in the desired order: nbsp, mdash, laquo. «&mdash;» and numeric «#8594» / «#x2192» are accepted too. Empty — default set from rte.js. Unknown names are skipped.';
$_lang['setting_mpcve_row_ignore_selectors'] = 'Row clone selectors (sliders)';
$_lang['setting_mpcve_row_ignore_selectors_desc'] = 'CSS selectors of elements a slider adds to a list as slide clones (Swiper loop mode, slick). Such clones are not counted as rows: otherwise an edit goes into the neighbouring row. Empty — default set: .swiper-slide-duplicate, .slick-cloned.';
