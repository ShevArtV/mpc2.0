<?php
/**
 * Резолвер контактов и привязки системных настроек к ID объектов.
 *
 * Ресурс контактов больше не пакуется как vehicle (см. elements/resources.php) —
 * он резолвится/создаётся здесь в зависимости от настройки mpc_contacts_page_alias:
 *   - пусто  → ресурс с псевдонимом 'contacts' на шаблоне «Контакты»;
 *   - указан существующий ресурс → работаем с ним, шаблон НЕ меняем (если шаблон
 *     не задан — создаём/берём «Контакты» и назначаем);
 *   - указан несуществующий → создаём ресурс на шаблоне «Контакты».
 * TV `contacts` привязывается к шаблону полученного ресурса (идемпотентно).
 *
 * @var xPDOTransport $transport
 * @var array $options
 * @var modX $modx
 */
if (!$transport->xpdo) {
    return true;
}
$modx =& $transport->xpdo;

$action = $options[xPDOTransport::PACKAGE_ACTION] ?? null;
if ($action !== xPDOTransport::ACTION_INSTALL && $action !== xPDOTransport::ACTION_UPGRADE) {
    return true;
}

// --- helpers -------------------------------------------------------------
// Создаёт (или возвращает существующий) шаблон «Контакты».
$ensureContactsTemplate = function () use ($modx) {
    $name = 'Контакты';
    $t = $modx->getObject('modTemplate', ['templatename' => $name]);
    if (!$t) {
        $t = $modx->newObject('modTemplate');
        $t->fromArray([
            'templatename' => $name,
            'description'  => 'Шаблон страницы контактов (MigxPageConfigurator)',
            'content'      => '[[*content]]',
            'icon'         => 'icon-address-book',
        ]);
        $t->save();
    }
    return (int)$t->get('id');
};
// Идемпотентная привязка TV к шаблону (чужие привязки не трогаем).
$bindTvToTemplate = function ($tvId, $templateId) use ($modx) {
    $tvId = (int)$tvId;
    $templateId = (int)$templateId;
    if (!$tvId || !$templateId) {
        return;
    }
    $exists = $modx->getObject('modTemplateVarTemplate', [
        'tmplvarid'  => $tvId,
        'templateid' => $templateId,
    ]);
    if (!$exists) {
        $tvt = $modx->newObject('modTemplateVarTemplate');
        $tvt->fromArray(['tmplvarid' => $tvId, 'templateid' => $templateId], '', true, true);
        $tvt->save();
    }
};

// --- 1. псевдоним ресурса контактов -------------------------------------
// На INSTALL берём значение из setup-модалки ($options), на UPGRADE — из уже
// сохранённой настройки (старые installs → пусто → дефолтное поведение).
if ($action === xPDOTransport::ACTION_INSTALL) {
    $alias = isset($options['mpc_contacts_page_alias'])
        ? trim((string)$options['mpc_contacts_page_alias'])
        : '';
} else {
    $alias = trim((string)$modx->getOption('mpc_contacts_page_alias', null, ''));
}
if ($alias === '') {
    $alias = 'contacts';
}

// --- 2. резолвим/создаём ресурс контактов и определяем целевой шаблон ----
$contacts = $modx->getObject('modResource', ['alias' => $alias]);
$bindTemplateId = 0;

if ($contacts) {
    // Существующий ресурс: шаблон НЕ меняем.
    $tpl = (int)$contacts->get('template');
    if ($tpl > 0) {
        $bindTemplateId = $tpl;
    } else {
        // Шаблона нет → создаём «Контакты» и назначаем.
        $bindTemplateId = $ensureContactsTemplate();
        $contacts->set('template', $bindTemplateId);
        $contacts->save();
    }
} else {
    // Ресурса нет → создаём на шаблоне «Контакты» (и для дефолтного 'contacts',
    // и для кастомного псевдонима).
    $bindTemplateId = $ensureContactsTemplate();
    $contacts = $modx->newObject('modResource');
    $contacts->fromArray([
        'pagetitle'    => 'Контакты',
        'alias'        => $alias,
        'context_key'  => 'web',
        'parent'       => 0,
        'template'     => $bindTemplateId,
        'published'    => false,
        'deleted'      => false,
        'hidemenu'     => true,
        'richtext'     => true,
        'searchable'   => true,
        'uri'          => $alias,
        'uri_override' => false,
        'content'      => '',
        'createdon'    => time(),
    ], '', true, true);
    $contacts->save();
}

// --- 3. page-types: шаблон «Пустой шаблон» (как раньше) ------------------
$page_types = $modx->getObject('modResource', ['alias' => 'page-types']);
$tmpl_empty = $modx->getObject('modTemplate', ['templatename' => 'Пустой шаблон']);
if ($page_types && $tmpl_empty) {
    $page_types->set('template', $tmpl_empty->get('id'));
    $page_types->save();
}

// --- 4. ID TV + привязка contacts к целевому шаблону --------------------
$q = $modx->newQuery('modTemplateVar');
$q->select('name, id');
// img и copy_sections больше не создаются (см. 3tvs.php) → исключены
// из mpc_tmplvar_ids (список TV, привязываемых к новым mpc-шаблонам).
$q->where(['name:IN' => ['mpc_config', 'contacts']]);
$q->prepare();
$q->stmt->execute();
$tvs = $q->stmt->fetchAll(PDO::FETCH_KEY_PAIR);

if (!empty($tvs['contacts'])) {
    $bindTvToTemplate($tvs['contacts'], $bindTemplateId);
}

$appendedTvs = [];
foreach ($tvs as $name => $id) {
    if ($name !== 'contacts') {
        $appendedTvs[] = $id;
    }
}

// --- 5. заполняем системные настройки ID (если пусто) -------------------
$settings = $modx->getIterator('modSystemSetting', [
    'key:IN' => [
        'mpc_tmplvar_ids',
        'mpc_config_tv_id',
        'mpc_contacts_tv_id',
        'mpc_static_block_page_id',
        'mpc_contacts_page_id',
    ]
]);
foreach ($settings as $setting) {
    if ($setting->get('value')) {
        continue;
    }
    switch ($setting->get('key')) {
        case 'mpc_tmplvar_ids':
            $value = implode(',', $appendedTvs);
            break;
        case 'mpc_config_tv_id':
            $value = $tvs['mpc_config'] ?? '';
            break;
        case 'mpc_contacts_tv_id':
            $value = $tvs['contacts'] ?? '';
            break;
        case 'mpc_static_block_page_id':
            $value = $page_types ? $page_types->get('id') : '';
            break;
        case 'mpc_contacts_page_id':
            $value = $contacts->get('id');
            break;
        default:
            continue 2;
    }
    $setting->set('value', $value);
    $setting->save();
}

if ($cm = $modx->getCacheManager()) {
    $cm->refresh([
        'system_settings' => [],
        'resource'        => [],
    ]);
}

return true;
