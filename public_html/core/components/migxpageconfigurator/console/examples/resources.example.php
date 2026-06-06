<?php
/**
 * Манифест дерева ресурсов проекта. Применение:
 *   php console/mpc.php resources apply путь/к/resources.php [--dry-run] [--only=alias]
 *
 * Идемпотентно: ресурс ищется по context_key + pagetitle (pagetitle = ключ,
 * держите его уникальным в контексте). Найден → обновляются перечисленные поля
 * и TV; нет → создаётся. Ресурсы, не описанные в манифесте, НЕ удаляются.
 * template — именем или id. Дети — в 'children' (или 'resources'). Тот же
 * движок применяет и self-seed пакета (elements/create/resource.inc.php).
 */
return [
    'context' => 'web',
    'resources' => [
        [
            'alias'     => 'home',
            'pagetitle' => 'Главная',
            'template'  => 'base',          // имя шаблона или id
            'published' => 1,
            'menuindex' => 0,
            'tvs'       => [
                // 'some_tv' => 'значение',
            ],
            'children'  => [
                [
                    'alias'     => 'about',
                    'pagetitle' => 'О компании',
                    'template'  => 'base',
                    'published' => 1,
                ],
                [
                    'alias'     => 'services',
                    'pagetitle' => 'Услуги',
                    'isfolder'  => 1,
                    'published' => 1,
                    'children'  => [
                        ['alias' => 'consulting', 'pagetitle' => 'Консалтинг', 'published' => 1],
                    ],
                ],
            ],
        ],
    ],
];
