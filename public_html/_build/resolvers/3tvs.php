<?php
/** @var xPDOTransport $transport */
/** @var array $options */
/** @var modX $modx */
if ($transport->xpdo) {
    function _getTemplateId($templateName, $modx, $full = false)
    {
        if (!$templateName) {
            return 0;
        }

        $template = $modx->getObject('modTemplate', ['templatename' => $templateName]);
        if ($templateName == null) return 0;
        if ($full !== false) {
            return array_merge($template->toArray(), ['access' => true]);
        }

        return is_object($template) ? $template->get('id') : 0;
    }

    function _getCategoryId($categoryName, $modx)
    {
        $obCategory = $modx->getObject('modCategory', ['category' => $categoryName]);
        if (!is_object($obCategory)) {
            $response = $modx->runProcessor('element/category/create', [
                'parent' => 0,
                'category' => $categoryName,
                'rank' => 0
            ]);

            if ($response->isError()) {
                return false;
            }
            return $response->response['object']['id'];
        }

        $id = $obCategory->get('id');
        return $id;
    }

    $tvs = [
        'img' => [
            'type' => 'image',
            'caption' => 'Картинка',
            'description' => '',
            'category' => 'MigxPageConfigurator',
            'templates' => [
                'Вывод содержимого'
            ],
            'resources' => ''
        ],
        'copy_sections' => [
            'type' => 'checkbox',
            'caption' => 'Копировать секции из шаблона?',
            'description' => '',
            'category' => 'MigxPageConfigurator',
            'elements' => 'Да==1',
            'templates' => [
                'Вывод содержимого'
            ],
            'resources' => ''
        ],
        'config' => [
            'type' => 'migx',
            'caption' => 'Конфигурация страницы',
            'description' => '',
            'category' => 'MigxPageConfigurator',
            'inputProperties' => [
                'configs' => 'config',
            ],
            'templates' => [
                'Вывод содержимого',
                'Пустой шаблон'
            ],
            'resources' => ''
        ],
        'contacts' => [
            'type' => 'migx',
            'caption' => 'Способы связи и адреса',
            'description' => '',
            'category' => 'MigxPageConfigurator',
            'inputProperties' => [
                'configs' => 'contacts',
            ],
            'templates' => [
                'Вывод содержимого'
            ],
            'resources' => ''
        ],
        'form_list' => [
            'type' => 'migx',
            'caption' => 'Список форм',
            'description' => '',
            'category' => 'MigxPageConfigurator',
            'inputProperties' => [
                'configs' => 'form_list',
            ],
            'templates' => [
                'Вывод содержимого'
            ],
            'resources' => ''
        ]
    ];

    $modx =& $transport->xpdo;
    $logTarget = $transport->xpdo->getLogTarget();
    $logLevel = $transport->xpdo->getLogLevel();
    //$modx_t = new $transport->xpdo;

    switch ($options[xPDOTransport::PACKAGE_ACTION]) {
        case xPDOTransport::ACTION_INSTALL:
        case xPDOTransport::ACTION_UPGRADE:
            foreach ($tvs as $name => $data) {
                if ($data['templates'] && is_array($data['templates'])) {
                    $templates = [];
                    foreach ($data['templates'] as $template) {
                        $temp = _getTemplateId($template, $modx, true);
                        $templates['templates'][$temp['id']] = $temp;
                    }
                }
                $data = array_merge(
                    $data,
                    $templates,
                    ['name' => $name, 'category' => _getCategoryId($data['category'], $modx)]
                );
                if ($data['type'] == 'migx' && $data['inputProperties']) {
                    foreach ($data['inputProperties'] as $key => $val) {
                        if ($key !== 'configs') {
                            $data['inopt_' . $key] = json_encode($val);
                        } else {
                            $data['inopt_' . $key] = $val;
                        }
                    }
                }

                $obTv = $modx->getObject('modTemplateVar', ['name' => $name]);

                if (is_object($obTv)) {
                    $data = array_merge(
                        $obTv->toArray(),
                        $data
                    );
                    $response = $modx->runProcessor('element/tv/update', $data);
                } else {
                    $response = $modx->runProcessor('element/tv/create', $data);
                }

                $transport->xpdo->setLogTarget($logTarget);
                $transport->xpdo->setLogLevel($logLevel);

                $resp = $response->getResponse();
                if ($resp['success']) {
                    if ($data['resources'] && is_array($data['resources'])) {
                        foreach ($data['resources'] as $key => $val) {
                            if($resource = $modx->getObject('modResource', ['alias' => $key])){
                                $resource->setTVValue($data['name'], $val);
                            }
                        }
                    }
                }
            }
            break;

        case xPDOTransport::ACTION_UNINSTALL:
            break;
    }
}

return true;