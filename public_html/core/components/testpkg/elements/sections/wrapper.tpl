<!DOCTYPE html>
<html lang="ru">
<head>
    {* ./console/mpc cut wrapper *}
    {* /usr/local/php/php-7.4/bin/php ~/art-sites.ru/htdocs/mpc-app/core/components/migxpageconfigurator/console/mgr_tpl.php web wrapper.tpl *}

    <title>{$pagetitle}</title>
    <meta name="description" content="{$description}">
    <meta charset="utf-8">
    <meta http-equiv="x-ua-compatible" content="ie=edge">
    <meta name="viewport" content="width=device-width, user-scalable=no, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0">

    <base href="##$_modx->config.site_url}">

    <link rel="icon" data-mpc-info="favicon" data-mpc-if="$favicon" href="favicon_web.ico" data-mpc-ctx>

    <link rel="apple-touch-icon" sizes="180x180" data-mpc-info="favicon_apple" data-mpc-if="" href="favicon_apple.ico">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"
          integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">

    <script data-mpc-info="metrics">
      const myFunc = () => {
        console.log('Metrics2')
      };
      myFunc();
    </script>
    {* Демо-стилизация контента. В {ignore}, чтобы Fenom не трогал CSS-скобки. *}
    {ignore}
    <style>
        :root {
            --mpc-gap: 1.5rem;
            --mpc-radius: 18px;
            --mpc-border: #e9ecf5;
            --mpc-muted: #6b7280;
            --mpc-ink: #1c2030;
            --mpc-text-color: #4b5165;
            --mpc-accent: {$_modx->config.accent_color ?: '#6366f2'};
            --mpc-accent-2: {$_modx->config.accent_color_2 ?: '#a855f7'};
            --mpc-shadow: 0 18px 40px -22px rgba(40, 40, 90, .35);
        }
        * {
            box-sizing: border-box;
        }
        body {
            font-family: "Inter", system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif;
            color: var(--mpc-ink);
            line-height: 1.65;
            background:
                radial-gradient(1100px 560px at 100% -8%, #eef1ff 0, transparent 52%),
                radial-gradient(900px 500px at -10% 6%, #fbf2ff 0, transparent 48%),
                #f6f7fb;
        }
        .wrap {
            max-width: 1120px;
            margin: 0 auto;
            padding: 2.25rem 1.25rem 5rem;
        }
        .wrap section {
            padding: 2.75rem 0;
        }
        .wrap section + section {
            border-top: 1px solid var(--mpc-border);
        }
        .wrap .container {
            max-width: none;
            padding-left: 0;
            padding-right: 0;
        }
        .wrap h1 {
            font-size: clamp(1.9rem, 3.4vw, 2.7rem);
            line-height: 1.12;
            font-weight: 800;
            letter-spacing: -.02em;
            margin: 0 0 1rem;
            background: linear-gradient(115deg, var(--mpc-accent), var(--mpc-accent-2));
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }
        .wrap h2 {
            font-size: 1.4rem;
            font-weight: 700;
            margin: 1.75rem 0 .75rem;
        }
        .wrap h5 {
            font-size: 1.12rem;
            font-weight: 700;
            margin: 0 0 .35rem;
        }
        .wrap h6 {
            font-size: .78rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .06em;
            color: var(--mpc-accent);
            margin: 0 0 .6rem;
        }
        .wrap p {
            color: var(--mpc-text-color);
            margin: 0 0 .85rem;
        }
        .wrap img,
        .wrap video,
        .wrap picture {
            display: block;
            max-width: 100%;
            height: auto;
            border-radius: var(--mpc-radius);
        }
        .wrap picture img {
            width: 100%;
        }
        .wrap video {
            width: 100%;
            background: #000;
            box-shadow: var(--mpc-shadow);
            margin: 0 0 var(--mpc-gap);
        }
        .wrap audio {
            width: 100%;
            margin: 0 0 var(--mpc-gap);
        }
        /* Картинки в ряд по 50% (2 колонки); gap — отступ между ними и между рядами. */
        .wrap .media-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: var(--mpc-gap);
            align-items: start;
            margin: 0 0 var(--mpc-gap);
        }
        .wrap .media-grid > * {
            margin: 0;
            min-width: 0;
        }
        @media (max-width: 560px) {
            .wrap .media-grid {
                grid-template-columns: 1fr;
            }
        }
        .wrap ul {
            list-style: none;
            margin: 0 0 var(--mpc-gap);
            padding: 0;
            display: grid;
            gap: var(--mpc-gap);
            grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
        }
        .wrap li {
            background: rgba(255, 255, 255, .85);
            backdrop-filter: blur(6px);
            border: 1px solid var(--mpc-border);
            border-radius: var(--mpc-radius);
            padding: 1.35rem;
            box-shadow: var(--mpc-shadow);
            transition: transform .18s ease, box-shadow .18s ease;
        }
        .wrap li:hover {
            transform: translateY(-5px);
            box-shadow: 0 26px 50px -20px rgba(99, 102, 241, .4);
        }
        .wrap li img {
            margin-top: .85rem;
        }
        .wrap ul ul {
            margin-top: var(--mpc-gap);
        }
        .wrap main a,
        .wrap > section a {
            display: inline-flex;
            align-items: center;
            gap: .4rem;
            padding: .65rem 1.25rem;
            border-radius: 999px;
            background: linear-gradient(115deg, var(--mpc-accent), var(--mpc-accent-2));
            color: #fff;
            font-weight: 600;
            text-decoration: none;
            box-shadow: 0 12px 24px -12px rgba(99, 102, 241, .7);
            transition: transform .15s ease, box-shadow .15s ease;
        }
        .wrap a:hover {
            transform: translateY(-2px);
        }
        .wrap a:has(img),
        .wrap picture a {
            padding: 0;
            background: none;
            box-shadow: none;
        }

        /* ---- Шапка ---- */
        .site-header {
            position: sticky;
            top: 0;
            z-index: 50;
            background: rgba(255, 255, 255, .82);
            backdrop-filter: blur(10px);
            border-bottom: 1px solid var(--mpc-border);
        }
        .site-header__inner {
            max-width: 1120px;
            margin: 0 auto;
            padding: .7rem 1.25rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
        }
        .site-header__logo {
            font-style: italic;
            font-weight: 800;
            font-size: 1.35rem;
            letter-spacing: -.02em;
            text-decoration: none;
            background: linear-gradient(115deg, var(--mpc-accent), var(--mpc-accent-2));
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }
        .site-header__actions {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        .site-header__cap {
            font-size: .75rem;
            color: var(--mpc-muted);
        }
        .site-header__phone {
            font-weight: 800;
            color: var(--mpc-ink);
            text-decoration: none;
            letter-spacing: -.01em;
            white-space: nowrap;
        }
        .site-header__phone:hover {
            color: var(--mpc-accent);
        }
        .site-header__lang {
            appearance: none;
            -webkit-appearance: none;
            border: 1px solid var(--mpc-border);
            border-radius: 999px;
            padding: .35rem .9rem;
            background: #fff;
            font: inherit;
            font-size: .85rem;
            color: var(--mpc-ink);
            cursor: pointer;
        }

        /* ---- Подвал ---- */
        .site-footer {
            margin-top: 4rem;
            background: linear-gradient(120deg, #161a2b 0%, #2a2350 100%);
            color: #c7cde0;
        }
        .site-footer__inner {
            max-width: 1120px;
            margin: 0 auto;
            padding: 3rem 1.25rem 2rem;
        }
        .site-footer__top {
            display: flex;
            flex-wrap: wrap;
            gap: 2.5rem;
            justify-content: space-between;
        }
        .site-footer__wordmark {
            font-size: 1.25rem;
            font-weight: 800;
            color: #fff;
            letter-spacing: -.01em;
        }
        .site-footer__tagline {
            color: #9aa3c0;
            margin: .55rem 0 0;
            max-width: 34ch;
        }
        .site-footer__head {
            font-size: .95rem;
            font-weight: 700;
            color: #fff;
            margin: 0 0 .9rem;
        }
        .site-footer__contacts {
            list-style: none;
            margin: 0;
            padding: 0;
            display: grid;
            gap: .8rem;
        }
        .site-footer__contacts li {
            display: flex;
            align-items: center;
            gap: .7rem;
        }
        .site-footer__ico {
            width: 20px;
            height: 20px;
            filter: invert(1) opacity(.85);
        }
        .site-footer a {
            color: #fff;
            text-decoration: none;
            font-weight: 600;
        }
        .site-footer a:hover {
            color: #c7b3ff;
        }
        .site-footer__bottom {
            display: flex;
            flex-wrap: wrap;
            gap: .5rem 1.5rem;
            justify-content: space-between;
            align-items: center;
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 1px solid rgba(255, 255, 255, .12);
            font-size: .85rem;
            color: #9aa3c0;
        }
    </style>
    {/ignore}
</head>

<body><div data-mpc-section="wrapper" class="wrapper" data-mpc-unwrap="1">
    
    
    
    
    <!-- header -->
    <header class="site-header">
        <div class="site-header__inner">
            <a href="" class="site-header__logo">MPC+VE</a>
            <div class="site-header__actions" data-mpc-contact="phone|header" data-mpc-key="mainphone">
                ##'{$contacts['header']['mainphone']['caption']}' | lexicon}
                <a href="tel:{$contacts['header']['mainphone']['value']}" class="site-header__phone" data-mpc-cfield="value">{$contacts['header']['mainphone']['fvalue']}</a>
                <select class="site-header__lang" name="language" data-choose-lang>
                    ##set $languages = $_modx->config.mpc_available_languages | split: ','}
                    ##foreach $languages as $lang}
                        <option ##($lang == $.cookie.mpc_lang) ? 'selected' : ''} value="##$lang}">##$lang}</option>
                    ##/foreach}
                </select>
            </div>
        </div>
    </header>

    <!-- wrap -->
    <main class="wrap">

        <!--CONTENT-->
        {if $sections}
            {foreach $sections as $section}
                {$section}
            {/foreach}
        {else}
            {$content}
        {/if}
        <!--CONTENT-->

    </main>

    <!-- footer -->
    <footer class="site-footer">
        <div class="site-footer__inner">
            <div class="site-footer__top">
                <div class="site-footer__brand">
                    <div class="site-footer__wordmark">MigxPageConfigurator + mpcVisualEditor</div>
                    <p class="site-footer__tagline">Статичная вёрстка превращается в редактируемый MODX-сайт — за одну нарезку.</p>
                </div>
                <div class="site-footer__col">
                    <h4 class="site-footer__head">{$cp_id | resource: 'pagetitle'}</h4>
                    <ul class="site-footer__contacts">
                        <li data-mpc-contact="phone|footer">
                            <img class="site-footer__ico" src="assets/components/migxpageconfigurator/images/fake-img.png" data-mpc-nothumb data-svg data-mpc-cfield="attributes" alt="" data-lazy="{$contacts['footer']['9c9c74ceb6accf3662ff270866dff8b3']['attributes']}">
                            <a href="tel:{$contacts['footer']['9c9c74ceb6accf3662ff270866dff8b3']['value']}" data-mpc-cfield="value">{$contacts['footer']['9c9c74ceb6accf3662ff270866dff8b3']['fvalue']}</a>
                        </li>
                        <li data-mpc-contact="email|footer">
                            <img class="site-footer__ico" src="assets/components/migxpageconfigurator/images/fake-img.png" data-mpc-nothumb data-svg data-mpc-cfield="attributes" alt="" data-lazy="{$contacts['footer']['f97568a29d689de9b2d25f588bc3f4aa']['attributes']}">
                            <a href="mailto:{$contacts['footer']['f97568a29d689de9b2d25f588bc3f4aa']['value']}" data-mpc-cfield="value">{$contacts['footer']['f97568a29d689de9b2d25f588bc3f4aa']['fvalue']}</a>
                        </li>
                    </ul>
                </div>
            </div>
            <div class="site-footer__bottom">
                <span>© {'' | date: 'Y'} MigxPageConfigurator. Все права защищены.</span>
                <span>Собрано на mpc + mpcVE</span>
            </div>
        </div>
    </footer>
</div></body>
</html>
