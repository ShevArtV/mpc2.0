<?php
return [
  'preset' =>  [
      'extends' => '',
      'variable' => '$var',
      'array' => '["subparam" => 12]',
      'string' => 'string',
      'placeholder' => '{$pls}',
      'json' => '{ "id:!=" : "12" }',
      'placeholder_past' => '$_modx->resource.id',
      'concatString' => '"noreply@"~$_modx->config.http_host',
      'stringWithPlaceholder' => '"noreply@{$domain}"',
      'number' => 5,
      'inlineChunk' => '@INLINE <li class="breadcrumb-item active" aria-current="page">{$menutitle?:$pagetitle}</li>',
      // Вложенные условия с ЖИВЫМИ переменными шаблона. Выражение пишется
      // строкой в кавычках (в PHP-файле голый $resource.alias невозможен —
      // парсер примет точку за конкатенацию). Каттер развернёт это в Fenom
      // array-литерал, и переменные вычислятся на рендере:
      //   'where' => ['alias' => $resource.alias, 'id:!=' => $_modx->resource.id]
      'where' => [
          'alias'  => '$resource.alias',     // выражение → доедет живым
          'id:in'  => '[1,2,3]',             // список
          'published' => 1,                  // число
          'tpl'    => '#/pdoresources/item.tpl', // #/ → @FILE-чанк
      ],
      'sortby' => ['menuindex' => 'ASC'],    // литералы → в кавычках
  ]
];
