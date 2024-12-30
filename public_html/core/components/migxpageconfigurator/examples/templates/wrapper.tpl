<!doctype html>
<html lang="ru">
<head>
    {* php7.4 -d display_errors -d error_reporting=E_ALL public_html/core/components/migxpageconfigurator/console/slice_tpl.php web wrapper.tpl *}
    {* общие поля сайта *}
    {set $site_url = $_modx->config.site_url}
    {set $site_name = $_modx->config.site_name}

    {* основные поля ресурса *}
    {set $rid = $_modx->resource.id}
    {set $pagetitle = $_modx->resource.pagetitle}
    {set $longtitle = $_modx->resource.longtitle}
    {set $menutitle = $_modx->resource.menutitle}
    {set $description = $_modx->resource.description}
    {set $introtext = $_modx->resource.introtext}
    {set $content = $_modx->resource.content}
    {set $template = $_modx->resource.template}

    {* символика *}
    {set $logo = $_modx->config.logo}
    {set $logo_alt = $_modx->config.logo_alt}
    {set $favicon = $_modx->config.favicon}
    {set $favicon_apple = $_modx->config.favicon_apple}

    {*контакты *}
    {set $contacts = 'getContacts' | snippet:[]}

    {* метрики *}
    {set $metrics = $_modx->config.metrics | replace: '{' : '{ '}

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, user-scalable=no, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <meta name="description" content="{$description}">
    <base href="{$site_url}">
    <title>{$pagetitle}</title>

    <!-- favicons -->
    {if $favicon}
        <link rel="icon" href="{$favicon}">
    {/if}
    {if $favicon_apple}
        <link rel="apple-touch-icon" sizes="180x180" href="{$favicon_apple}">
    {/if}

    {$metrics?:''}
</head>
<body>
<div data-mpc-section="wrapper" data-mpc-unwrap="1">
    <header class="main-header"></header>

    <main class="position-relative">
        {block 'content'}
            <section></section>
        {/block}
    </main>

    <footer class="footer"></footer>
</div>
</body>
</html>

