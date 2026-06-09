<?php
/**
 * Setup-options форма пакета MigxPageConfigurator — показывается при установке.
 * Поля сабмитятся в $options резолверов (см. resolvers/Asetupoptions.php),
 * который пишет их в системные настройки.
 *
 * @var modX  $modx
 * @var array $options
 */

$get = function ($key, $default = '') use ($modx) {
    $v = $modx->getOption($key, null, null);
    return $v === null ? $default : (string)$v;
};

$useLex    = $get('mpc_use_lexicons', '0');
$langs     = $get('mpc_available_languages', '');
$defLang   = $get('mpc_default_language', 'ru');
$manifests = $get('mpc_manifests_path', 'components/migxpageconfigurator/console/manifests/');
$lexNs     = $get('mpc_lexicons_namespace', 'migxpageconfigurator');
$lexPath   = $get('mpc_lexicon_path', 'components/migxpageconfigurator/lexicon/');
$exclLex   = $get('mpc_exclude_lexicons_filename', 'components/migxpageconfigurator/services/exclude_lexicons.inc.php');
$lazyload  = $get('mpc_lazyload_enabled', '1');
$expand    = $get('mpc_expand_enabled', '1');

$esc = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES); };
// boolean-select Да/Нет с предвыбором текущего значения ('1' = Да)
$boolSelect = function ($name, $value) {
    $yes = $value === '1' ? ' selected="selected"' : '';
    $no  = $value === '1' ? '' : ' selected="selected"';
    return '<select name="' . $name . '" style="width:100%">'
        . '<option value="0"' . $no . '>Нет</option>'
        . '<option value="1"' . $yes . '>Да</option>'
        . '</select>';
};

$output = '
<div style="padding:10px 14px;line-height:1.6">
  <h3 style="margin:0 0 8px">Настройки MigxPageConfigurator</h3>
  <p style="color:#666;margin:0 0 14px">Значения применятся при установке; позже их можно изменить в системных настройках.</p>

  <h4 style="margin:14px 0 8px;border-bottom:1px solid #eee;padding-bottom:4px">Лексиконы</h4>

  <label style="display:block;margin-bottom:12px">
    <b>Использовать лексиконы (мультиязычность)</b><br>
    ' . $boolSelect('mpc_use_lexicons', $useLex) . '
  </label>

  <label style="display:block;margin-bottom:12px">
    <b>Namespace лексиконов</b><br>
    <input type="text" name="mpc_lexicons_namespace" value="' . $esc($lexNs) . '" style="width:100%">
  </label>

  <label style="display:block;margin-bottom:12px">
    <b>Путь к лексиконам</b> <span style="color:#888">(относительно core/ или абсолютный)</span><br>
    <input type="text" name="mpc_lexicon_path" value="' . $esc($lexPath) . '" style="width:100%">
  </label>

  <label style="display:block;margin-bottom:12px">
    <b>Файл исключений лексиконов</b> <span style="color:#888">(относительно core/ или абсолютный)</span><br>
    <textarea name="mpc_exclude_lexicons_filename" style="width:100%" rows="2">' . $esc($exclLex) . '</textarea>
  </label>

  <label style="display:block;margin-bottom:12px">
    <b>Доступные языки</b> <span style="color:#888">(через запятую, напр. ru,en)</span><br>
    <input type="text" name="mpc_available_languages" value="' . $esc($langs) . '" style="width:100%">
  </label>

  <label style="display:block;margin-bottom:12px">
    <b>Язык по умолчанию</b><br>
    <input type="text" name="mpc_default_language" value="' . $esc($defLang) . '" style="width:100%">
  </label>

  <h4 style="margin:18px 0 8px;border-bottom:1px solid #eee;padding-bottom:4px">Медиа</h4>

  <label style="display:block;margin-bottom:12px">
    <b>Ленивая загрузка (lazyload)</b><br>
    ' . $boolSelect('mpc_lazyload_enabled', $lazyload) . '
  </label>

  <label style="display:block;margin-bottom:12px">
    <b>Разворачивание SVG (expand)</b><br>
    ' . $boolSelect('mpc_expand_enabled', $expand) . '
  </label>

  <h4 style="margin:18px 0 8px;border-bottom:1px solid #eee;padding-bottom:4px">Пути</h4>

  <label style="display:block;margin-bottom:4px">
    <b>Путь к манифестам mpc CLI</b> <span style="color:#888">(относительно core/ или абсолютный)</span><br>
    <input type="text" name="mpc_manifests_path" value="' . $esc($manifests) . '" style="width:100%">
  </label>
</div>';

return $output;
