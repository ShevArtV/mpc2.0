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

$selYes = $useLex === '1' ? ' selected="selected"' : '';
$selNo  = $useLex === '1' ? '' : ' selected="selected"';
$esc = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES); };

$output = '
<div style="padding:10px 14px;line-height:1.6">
  <h3 style="margin:0 0 8px">Настройки MigxPageConfigurator</h3>
  <p style="color:#666;margin:0 0 14px">Значения применятся при установке; позже их можно изменить в системных настройках.</p>

  <label style="display:block;margin-bottom:12px">
    <b>Использовать лексиконы (мультиязычность)</b><br>
    <select name="mpc_use_lexicons" style="width:100%">
      <option value="0"' . $selNo . '>Нет</option>
      <option value="1"' . $selYes . '>Да</option>
    </select>
  </label>

  <label style="display:block;margin-bottom:12px">
    <b>Доступные языки</b> <span style="color:#888">(через запятую, напр. ru,en)</span><br>
    <input type="text" name="mpc_available_languages" value="' . $esc($langs) . '" style="width:100%">
  </label>

  <label style="display:block;margin-bottom:12px">
    <b>Язык по умолчанию</b><br>
    <input type="text" name="mpc_default_language" value="' . $esc($defLang) . '" style="width:100%">
  </label>

  <label style="display:block;margin-bottom:4px">
    <b>Путь к манифестам mpc CLI</b> <span style="color:#888">(относительно core/ или абсолютный)</span><br>
    <input type="text" name="mpc_manifests_path" value="' . $esc($manifests) . '" style="width:100%">
  </label>
</div>';

return $output;
