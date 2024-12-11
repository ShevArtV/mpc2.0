<!DOCTYPE html>
<html lang="ru">
<head>
    {* /usr/local/php/php-7.4/bin/php -d display_errors -d error_reporting=E_ALL /home/host1860015/art-sites.ru/htdocs/customfactory/core/components/migxpageconfigurator/console/slice_tpl.php web wrapper.tpl *}
    {* php -d display_errors -d error_reporting=E_ALL /home/host1860015/art-sites.ru/htdocs/customfactory/core/components/migxpageconfigurator/console/slice_tpl.php web wrapper.tpl *}

    <title>{$pagetitle}</title>
    <meta name="description" content="{$description}">
    <meta charset="utf-8">
    <meta http-equiv="x-ua-compatible" content="ie=edge">
    <meta name="viewport" content="width=device-width, user-scalable=no, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0">

    <base href="{$site_url}">

    <link rel="icon" data-mpc-info="favicon" data-mpc-if="$favicon" href="favicon_web.ico" data-mpc-ctx>

    <link rel="apple-touch-icon" sizes="180x180" data-mpc-info="favicon_apple" data-mpc-if="" href="favicon_apple.ico">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <link rel="stylesheet" href="assets/project_files/css/landing/style.css?v={''|date: 'dmYHis'}">

    <span data-mpc-unwrap="" data-mpc-info="metrics">{Metrics}</span>
</head>

<body>
<div data-mpc-section="wrapper" class="wrapper">
    <span data-mpc-remove="1" data-mpc-info="site_name">MigxPageConfigurator</span>
    <span data-mpc-remove="1" data-mpc-info="site_name" data-mpc-ctx>WEB_MigxPageConfigurator</span>
    <!-- header -->
    <header class="header index">
        <div class="container">
            <div class="header-content">
                <a href="" class="logotype">
                    <img src="logo.png" data-mpc-info="logo" alt="" width="213" height="48">
                </a>
                <ul class="header-auth">
                    <li data-mpc-contact="phone|header">
                        <p class="footer-contact__icon" data-mpc-cfield="caption">
                            Phone
                        </p>
                        <a href="" data-mpc-cfield="value"><span>8(999)888-77-66</span></a>
                    </li>
                </ul>
            </div>
        </div>
    </header>

    <!-- wrap -->
    <main class="wrap">
        <!--CONTENT-->
        {if !$sections}
            {$content}
        {else}
            {foreach $sections as $section}
                {$section}
            {/foreach}
        {/if}
        <!--CONTENT-->

    </main>

    <!-- footer -->
    <footer class="footer">
        <div class="container">

            <div class="footer-top">
                <div class="columns">
                    <div class="column col-6 md-col-12">
                        <h3>{$cp_id | resource: 'pagetitle'}</h3>
                        <ul class="footer-contact">
                            <li data-mpc-contact="phone|footer">
                                <div class="footer-contact__icon">
                                    <img src="assets/project_files/img/phone.svg" data-mpc-cfield="attributes" alt="">
                                </div>
                                <a href="" data-mpc-cfield="value">
                                    <span>8(999)888-77-66</span>
                                </a>
                            </li>
                            <li data-mpc-contact="email|footer">
                                <div class="footer-contact__icon">
                                    <img src="assets/project_files/img/envelope.svg" data-mpc-cfield="attributes" alt="">
                                </div>
                                <a href="" data-mpc-cfield="value">
                                    email@domain.ru
                                </a>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>

            <div class="footer-bottom">
                <div class="footer-logo">
                    <img src="logo_alt.png" data-mpc-info="logo_alt" alt="" width="213" height="48">
                </div>

                <ul class="footer-bottom__list">
                    <li>© {'' | date: 'Y'} Все права защищены</li>
                </ul>
            </div>

        </div>
    </footer>
</div>

</body>
</html>
