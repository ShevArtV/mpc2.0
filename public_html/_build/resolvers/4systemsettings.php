<?php
/** @var xPDOTransport $transport */

/** @var array $options */
/** @var modX $modx */
if ($transport->xpdo) {
    $modx =& $transport->xpdo;

    switch ($options[xPDOTransport::PACKAGE_ACTION]) {
        case xPDOTransport::ACTION_INSTALL:
        case xPDOTransport::ACTION_UPGRADE:
            $q = $modx->newQuery('modTemplateVar');
            $q->select('name, id');
            $q->where(['name:IN' => ['mpc_config', 'img', 'contacts', 'copy_sections']]);
            $q->prepare();
            $q->stmt->execute();
            $tvs = $q->stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            $appendedTvs = [];
            foreach ($tvs as $name => $id) {
                if ($name !== 'contacts') {
                    $appendedTvs[] = $id;
                }
            }

            $page_types = $modx->getObject('modResource', array('alias' => 'page-types'));
            $contacts = $modx->getObject('modResource', array('alias' => 'contacts'));
            $tmpl_empty = $modx->getObject('modTemplate', array('templatename' => 'Пустой шаблон', 'icon' => 'icon-anchor'));
            $tmpl_content = $modx->getObject('modTemplate', array('templatename' => 'Вывод содержимого', 'icon' => 'icon-gears'));
            $settings = $modx->getIterator('modSystemSetting', array(
                'key:IN' => array(
                    'mpc_tmplvar_ids',
                    'mpc_config_tv_id',
                    'mpc_contacts_tv_id',
                    'mpc_static_block_page_id',
                    'mpc_contacts_page_id',
                )
            ));
            if (!$tmpl_empty || !$page_types || !$tmpl_content || !$contacts) {
                return true;
            }
            $page_types->set('template', $tmpl_empty->get('id'));
            $page_types->save();
            $contacts->set('template', $tmpl_content->get('id'));
            $contacts->save();
            foreach ($settings as $setting) {
                if ($setting->get('value')) {
                    continue;
                }
                switch ($setting->get('key')) {
                    case 'mpc_tmplvar_ids':
                        $value = implode(',', $appendedTvs);
                        break;
                    case 'mpc_config_tv_id':
                        $value = $tvs['mpc_config'];
                        break;
                    case 'mpc_contacts_tv_id':
                        $value = $tvs['contacts'];
                        break;
                    case 'mpc_static_block_page_id':
                        $value = $page_types->get('id');
                        break;
                    case 'mpc_contacts_page_id':
                        $value = $contacts->get('id');
                        break;
                }
                $setting->set('value', $value);
                $setting->save();
            }
            break;

        case xPDOTransport::ACTION_UNINSTALL:
            break;
    }
}

return true;
