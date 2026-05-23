<?php
/**
 * Миграция под модель типов mpc 2.4.0:
 *   - ресурс mpc_static_block_page_id  → class_key = mpcTypeCollection;
 *   - его дети (доноры-типы)           → class_key = mpcType,
 *     служебка переносится: type_name ← pagetitle, file_name ← introtext.
 * Обычные ресурсы не трогаются.
 *
 * Использование (php 7.4):
 *   php migrate_types.php            — DRY-RUN (только отчёт, без изменений)
 *   php migrate_types.php --apply    — применить
 *
 * Безопасность: pagetitle/introtext НЕ очищаются (копируем, не двигаем) —
 * миграция обратима сменой class_key назад. Очистку старых полей сделаем
 * отдельным шагом после проверки рендера.
 */
define('MODX_API_MODE', true);
require_once dirname(__DIR__, 4) . '/index.php';

$modx->getService('error', 'error.modError');
$modx->setLogLevel(modX::LOG_LEVEL_ERROR);
$modx->setLogTarget('ECHO');

$apply = in_array('--apply', $argv ?? [], true);
$modx->addPackage('migxpageconfigurator', MODX_CORE_PATH . 'components/migxpageconfigurator/model/');

$sbpId = (int)$modx->getOption('mpc_static_block_page_id', null, 1);
$collection = $modx->getObject('modResource', $sbpId);
if (!$collection) {
    exit("Collection (staticBlocksPage id=$sbpId) не найден\n");
}

$changes = [];

// 1) Коллекция
if ($collection->get('class_key') !== 'mpcTypeCollection') {
    $changes[] = "collection #{$sbpId} '{$collection->get('pagetitle')}': class_key '{$collection->get('class_key')}' → mpcTypeCollection";
    if ($apply) {
        $collection->set('class_key', 'mpcTypeCollection');
        $collection->save();
    }
}

// 2) Типы = прямые дети коллекции
$children = $modx->getCollection('modResource', ['parent' => $sbpId]);
foreach ($children as $child) {
    $cid = (int)$child->get('id');
    $pagetitle = (string)$child->get('pagetitle');
    $introtext = (string)$child->get('introtext');

    if ($child->get('class_key') === 'mpcType') {
        continue;
    }
    $changes[] = "type #{$cid} '{$pagetitle}': class_key '{$child->get('class_key')}' → mpcType; type_name='{$pagetitle}', file_name='{$introtext}'";

    if ($apply) {
        $child->set('class_key', 'mpcType');
        $child->save();
        // перечитываем как mpcType, чтобы прокси записал в mpc_type
        if ($type = $modx->getObject('modResource', $cid)) {
            $type->set('type_name', $pagetitle);
            $type->set('file_name', $introtext);
            $type->save();
        }
    }
}

echo ($apply ? "ПРИМЕНЕНО:\n" : "DRY-RUN (изменений не внесено), будет сделано:\n");
echo $changes ? (implode("\n", $changes) . "\n") : "(нечего менять)\n";
echo count($changes) . " изменений\n";

if ($apply) {
    $modx->getCacheManager()->refresh();
    echo "Кэш сброшен.\n";
}
