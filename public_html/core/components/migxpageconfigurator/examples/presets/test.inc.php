<?php
return array(
  'preset' =>  array(
      'variable' => '$var',
      'array' => '["subparam" => 12]',
      'string' => 'string',
      'placeholder' => '{$pls}',
      'json' => '{ "id:!=" : "12" }',
      'placeholder_past' => '$_modx->resource.id',
      'concatString' => '"noreply@"~$_modx->config.http_host',
      'stringWithPlaceholder' => '"noreply@{$domain}"',
      'number' => 5,
      'inlineChunk' => '@INLINE <li class="breadcrumb-item active" aria-current="page">{$menutitle?:$pagetitle}</li>'
  )
);