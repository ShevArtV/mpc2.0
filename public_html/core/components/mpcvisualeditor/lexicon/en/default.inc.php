<?php
/**
 * @package mpcvisualeditor
 * @subpackage lexicon
 */
$_lang['mpcvisualeditor'] = 'mpcVisualEditor';
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

$_lang['mpcve_fm_created'] = 'Folder created';
$_lang['mpcve_fm_renamed'] = 'Renamed';
$_lang['mpcve_fm_removed'] = 'Removed';
$_lang['mpcve_fm_err_name'] = 'Name is required.';

// --- Settings area headers ---
$_lang['area_mpcve_general'] = 'General';
$_lang['area_mpcve_editor']  = 'Editor';

// --- Settings ---
$_lang['setting_mpcve_active'] = 'Enable mpcVisualEditor';
$_lang['setting_mpcve_active_desc'] = 'Package master switch. Off — edit mode is unavailable and nothing is loaded on the frontend. It also requires the mpc_edit_mode setting (MigxPageConfigurator package) to be enabled and pages re-cut — otherwise chunks contain no data-mpc-* markers and the editor will not load (a warning is written to the system log).';
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
