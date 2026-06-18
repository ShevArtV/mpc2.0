<?php

use MpcServices\Mpc;

/**
 * mpcContacts — выборка контактов (ресурс «Контакты», TV mpc_contacts) с
 * фильтрацией по типу, плейсменту и ключу и рендером через чанк.
 *
 * Параметры:
 *   &type            — фильтр по типу контакта (phone/email/social/…); пусто = любые
 *   &placement       — фильтр по плейсменту (footer/header/…); пусто = любые
 *   &key             — фильтр по ключу контакта (data-mpc-key, напр. instagram); пусто = любые
 *   &tpl             — чанк рендера одного контакта (имя чанка или @INLINE …); пусто = JSON-массив
 *   &outputSeparator — разделитель между отрендеренными контактами (по умолчанию '')
 *   &limit           — максимум контактов (0 = без лимита)
 *   &toPlaceholder   — имя плейсхолдера для результата (вместо возврата)
 *
 * Плейсхолдеры в &tpl: type, value, fvalue, caption, attributes, icon, placement, key.
 *
 * @author Arthur Shevchenko (https://t.me/ShevArtV)
 * @var \modX $modx
 * @var array $scriptProperties
 */
$corePath = $modx->getOption('core_path', '', MODX_CORE_PATH);
require_once $corePath . 'components/migxpageconfigurator/services/vendor/autoload.php';

$type            = trim((string)($scriptProperties['type'] ?? ''));
$placement       = trim((string)($scriptProperties['placement'] ?? ''));
$key             = trim((string)($scriptProperties['key'] ?? ''));
$tpl             = (string)($scriptProperties['tpl'] ?? '');
$outputSeparator = (string)($scriptProperties['outputSeparator'] ?? '');
$limit           = (int)($scriptProperties['limit'] ?? 0);
$toPlaceholder   = trim((string)($scriptProperties['toPlaceholder'] ?? ''));

$Mpc = new Mpc($modx);
// [placement][ckey] => {type,value,fvalue,caption,attributes,icon,placement}
$all = $Mpc->render->getContacts();

$rows = [];
foreach ($all as $pl => $byKey) {
    if ($placement !== '' && (string)$pl !== $placement) {
        continue;
    }
    foreach ($byKey as $ckey => $contact) {
        if ($type !== '' && (string)($contact['type'] ?? '') !== $type) {
            continue;
        }
        if ($key !== '' && (string)$ckey !== $key) {
            continue;
        }
        $contact['key']       = (string)$ckey;
        $contact['placement'] = $contact['placement'] ?? $pl;
        $rows[] = $contact;
        if ($limit > 0 && count($rows) >= $limit) {
            break 2;
        }
    }
}

if ($tpl === '') {
    $output = json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} else {
    /** @var \pdoTools|null $pdo */
    $pdo   = $modx->getService('pdoTools');
    $parts = [];
    foreach ($rows as $row) {
        $parts[] = $pdo ? $pdo->getChunk($tpl, $row) : $modx->getChunk($tpl, $row);
    }
    $output = implode($outputSeparator, $parts);
}

if ($toPlaceholder !== '') {
    $modx->setPlaceholder($toPlaceholder, $output);
    return '';
}

return $output;
