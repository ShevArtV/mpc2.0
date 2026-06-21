<!DOCTYPE html>
<html lang="ru">
<head>
    {* ИСХОДНИК ТЕМЫ «bento» (wrapper) для mpc2. Режется ТАК ЖЕ, как базовый
       templates/wrapper.tpl, но выхлоп идёт в sections/_themes/bento/wrapper.tpl,
       контент не трогается:
         ./console/mpc cut wrapper --theme=bento
       Те же data-mpc-* маркеры/контакты/лексиконы, что в базе — отличается ТОЛЬКО
       сетка: 12-колоночный bento с плиточными секциями вместо центрированной колонки. *}

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

    ##set $accentColor = '#'~($_modx->config.accent_color ?: '0f766e')}
    ##set $accentColor2 = '#'~($_modx->config.accent_color_2 ?: 'f59e0b')}

    <style>
        :root {
            --mpc-gap: 1.1rem;
            --mpc-radius: 22px;
            --mpc-border: #e2e5f0;
            --mpc-muted: #6b7280;
            --mpc-ink: #161a2b;
            --mpc-text-color: #4b5165;
            --mpc-accent: ##$accentColor};
            --mpc-accent-2: ##$accentColor2};
            --mpc-card: #ffffff;
            --mpc-tile: #f3f4fb;
            --mpc-shadow: 0 14px 34px -24px rgba(20, 24, 50, .5);
        }
        * { box-sizing: border-box; }
        body {
            font-family: "Inter", system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif;
            color: var(--mpc-ink);
            line-height: 1.6;
            background:
                radial-gradient(1200px 600px at 100% -10%, #e8fff8 0, transparent 55%),
                radial-gradient(900px 520px at -8% 4%, #fff5e6 0, transparent 50%),
                #eef0f6;
        }

        /* ===== КАРКАС: 12-колоночный bento вместо центрированной колонки ===== */
        .wrap {
            max-width: 1280px;
            margin: 0 auto;
            padding: 2rem 1.1rem 5rem;
            display: grid;
            grid-template-columns: repeat(12, 1fr);
            gap: var(--mpc-gap);
            align-items: start;
        }
        .wrap > section {
            grid-column: span 12;
            position: relative;
            background: var(--mpc-card);
            border: 1px solid var(--mpc-border);
            border-radius: var(--mpc-radius);
            padding: clamp(1.4rem, 2.4vw, 2.5rem);
            box-shadow: var(--mpc-shadow);
            overflow: clip;
        }
        .wrap > section:nth-of-type(4) { grid-column: span 5; }
        .wrap > section:nth-of-type(5) { grid-column: span 7; }
        .wrap > section:nth-of-type(6) { grid-column: span 7; }
        .wrap > section:nth-of-type(7) { grid-column: span 5; }
        @media (max-width: 900px) {
            .wrap { grid-template-columns: 1fr; }
            .wrap > section { grid-column: auto !important; }
        }
        .wrap .container { max-width: none; padding-left: 0; padding-right: 0; }

        .wrap .hero-split {
            display: grid;
            grid-template-columns: 1.1fr .9fr;
            gap: clamp(1.2rem, 3vw, 2.4rem);
            align-items: center;
        }
        .wrap .hero-split__media { width: 100%; }
        @media (max-width: 760px) { .wrap .hero-split { grid-template-columns: 1fr; } }

        .wrap h1 {
            font-size: clamp(1.7rem, 3vw, 2.5rem);
            line-height: 1.12; font-weight: 800; letter-spacing: -.02em;
            margin: 0 0 .9rem; color: var(--mpc-ink);
        }
        .wrap h2 { font-size: 1.32rem; font-weight: 700; margin: 1.5rem 0 .7rem; }
        .wrap h5 { font-size: 1.05rem; font-weight: 700; margin: 0 0 .3rem; }
        .wrap h6 {
            font-size: .72rem; font-weight: 800; text-transform: uppercase;
            letter-spacing: .1em; color: var(--mpc-accent); margin: 0 0 .55rem;
        }
        .wrap p { color: var(--mpc-text-color); margin: 0 0 .8rem; }
        .wrap img, .wrap video, .wrap picture {
            display: block; max-width: 100%; height: auto; border-radius: 14px;
        }
        .wrap picture img { width: 100%; }

        .wrap .section-head { margin: 0 0 1.2rem; }
        .wrap .section-head h1 { margin: 0; }

        /* ===== КАРТОЧКИ ФИЧ: нумерованные bento-плитки ===== */
        .wrap .bento-cards { counter-reset: bento; }
        .wrap .bento-cards li { position: relative; padding-top: 2.6rem; }
        .wrap .bento-cards li::before {
            counter-increment: bento;
            content: counter(bento, decimal-leading-zero);
            position: absolute; top: 1.1rem; left: 1.15rem;
            font-size: .8rem; font-weight: 800; letter-spacing: .04em;
            color: var(--mpc-accent);
        }
        .wrap .bento-card__body { margin: 0; }

        .wrap .feature-extras {
            display: grid; grid-template-columns: repeat(3, 1fr);
            gap: 1.25rem; margin-top: 1.5rem;
        }
        .wrap .feature-extras h2 { margin-top: 0; }
        .wrap .feature-extras ul { grid-template-columns: 1fr; }
        @media (max-width: 820px) { .wrap .feature-extras { grid-template-columns: 1fr; } }

        /* ===== КАРТОЧКИ (общий bento 6 колонок со спанами) ===== */
        .wrap section ul {
            list-style: none; margin: 1rem 0; padding: 0;
            display: grid; grid-template-columns: repeat(6, 1fr);
            grid-auto-flow: dense; gap: .85rem;
        }
        .wrap section li {
            grid-column: span 3; margin: 0;
            background: var(--mpc-tile); border: 1px solid var(--mpc-border);
            border-radius: 16px; padding: 1.15rem;
            transition: transform .16s ease, box-shadow .16s ease;
        }
        .wrap section li:nth-child(5n+1) { grid-column: span 4; }
        .wrap section li:nth-child(5n+3) { grid-column: span 2; }
        .wrap section li:hover {
            transform: translateY(-4px);
            box-shadow: 0 22px 40px -24px rgba(15, 118, 110, .55);
        }
        .wrap section li img { margin-top: .85rem; }
        .wrap section ul ul { grid-template-columns: 1fr; margin-top: .85rem; }
        @media (max-width: 680px) {
            .wrap section ul { grid-template-columns: 1fr; }
            .wrap section li { grid-column: auto !important; }
        }

        /* ===== МЕДИА: «постер-сцена + лента» ===== */
        .wrap .media-grid {
            display: grid; grid-template-columns: repeat(3, 1fr);
            grid-auto-rows: 1fr; gap: 1rem; margin: 0 0 var(--mpc-gap);
        }
        .wrap .media-grid > * { margin: 0; min-width: 0; }
        .wrap .media-grid > :first-child { grid-column: span 2; grid-row: span 2; }
        .wrap .media-stage { margin: 0 0 1rem; }
        .wrap .media-stage video { aspect-ratio: 16 / 9; object-fit: cover; }
        .wrap .media-row {
            display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin: 0 0 1rem;
        }
        .wrap .media-row > * { margin: 0; min-width: 0; }
        .wrap .media-bar { margin: 0; }
        @media (max-width: 680px) {
            .wrap .media-grid { grid-template-columns: 1fr; }
            .wrap .media-grid > :first-child { grid-column: auto; grid-row: auto; }
            .wrap .media-row { grid-template-columns: 1fr; }
        }
        .wrap video { width: 100%; background: #000; margin: 0 0 var(--mpc-gap); }
        .wrap audio { width: 100%; margin: 0 0 var(--mpc-gap); }

        .wrap main a, .wrap > section a {
            display: inline-flex; align-items: center; gap: .4rem;
            padding: .6rem 1.2rem; border-radius: 12px;
            background: linear-gradient(115deg, var(--mpc-accent), var(--mpc-accent-2));
            color: #fff; font-weight: 700; text-decoration: none;
            box-shadow: 0 12px 22px -14px rgba(15, 118, 110, .8);
        }
        .wrap a:hover { transform: translateY(-2px); }
        .wrap a:has(img), .wrap picture a { padding: 0; background: none; box-shadow: none; }

        /* ---- Шапка ---- */
        .site-header {
            position: sticky; top: 0; z-index: 50;
            background: rgba(255, 255, 255, .85); backdrop-filter: blur(10px);
            border-bottom: 1px solid var(--mpc-border);
        }
        .site-header__inner {
            max-width: 1280px; margin: 0 auto; padding: .7rem 1.1rem;
            display: flex; align-items: center; justify-content: space-between; gap: 1rem;
        }
        .site-header__logo {
            font-weight: 900; font-size: 1.3rem; letter-spacing: -.03em;
            text-decoration: none; color: var(--mpc-accent);
        }
        .site-header__actions { display: flex; align-items: center; gap: 1rem; }
        .site-header__cap { font-size: .75rem; color: var(--mpc-muted); }
        .site-header__phone {
            font-weight: 800; color: var(--mpc-ink); text-decoration: none; white-space: nowrap;
        }
        .site-header__phone:hover { color: var(--mpc-accent); }
        .site-header__lang {
            appearance: none; -webkit-appearance: none;
            border: 1px solid var(--mpc-border); border-radius: 10px;
            padding: .35rem .9rem; background: #fff; font: inherit; font-size: .85rem;
            color: var(--mpc-ink); cursor: pointer;
        }

        /* ---- Подвал ---- */
        .site-footer { margin-top: 3rem; background: #11151f; color: #c7cde0; }
        .site-footer__inner { max-width: 1280px; margin: 0 auto; padding: 3rem 1.1rem 2rem; }
        .site-footer__top { display: flex; flex-wrap: wrap; gap: 2.5rem; justify-content: space-between; }
        .site-footer__wordmark { font-size: 1.2rem; font-weight: 800; color: #fff; }
        .site-footer__tagline { color: #9aa3c0; margin: .55rem 0 0; max-width: 34ch; }
        .site-footer__head { font-size: .95rem; font-weight: 700; color: #fff; margin: 0 0 .9rem; }
        .site-footer__contacts { list-style: none; margin: 0; padding: 0; display: grid; gap: .8rem; }
        .site-footer__contacts li { display: flex; align-items: center; gap: .7rem; }
        .site-footer__ico { width: 20px; height: 20px; filter: invert(1) opacity(.85); }
        .icon { display: inline-block; width: 20px; height: 20px; background-size: contain; background-repeat: no-repeat; background-position: center; }
        .icon-plane { background-color: #fff; -webkit-mask: url('assets/project_files/img/phone.svg') center/contain no-repeat; mask: url('assets/project_files/img/phone.svg') center/contain no-repeat; }
        .site-footer a { color: #fff; text-decoration: none; font-weight: 600; }
        .site-footer a:hover { color: #8fe9d8; }
        .site-footer__bottom {
            display: flex; flex-wrap: wrap; gap: .5rem 1.5rem; justify-content: space-between;
            align-items: center; margin-top: 2rem; padding-top: 1.5rem;
            border-top: 1px solid rgba(255, 255, 255, .12); font-size: .85rem; color: #9aa3c0;
        }
    </style>
</head>

<body>
<div data-mpc-section="wrapper" class="wrapper" data-mpc-unwrap="1">
    <span data-mpc-remove="1" data-mpc-info="accent_color">6366f3</span>
    <span data-mpc-remove="1" data-mpc-info="accent_color_2">a855f8</span>
    <span data-mpc-remove="1" data-mpc-info="site_name">MigxPageConfigurator!</span>
    <span data-mpc-remove="1" data-mpc-info="site_name" data-mpc-ctx>WEB_MigxPageConfigurator!</span>
    <!-- header -->
    <header class="site-header">
        <div class="site-header__inner">
            <a href="" class="site-header__logo">MPC+VE · bento</a>
            <div class="site-header__actions" data-mpc-contact="phone|header" data-mpc-key="mainphone">
                <span class="site-header__cap" data-mpc-cfield="caption">Горячая линия</span>
                <a href="" class="site-header__phone" data-mpc-cfield="value">
                    <span>8(999)888-77-68</span>
                </a>
                <select class="site-header__lang" name="language" data-choose-lang>
                    ##set $languages = $_modx->config.mpc_available_languages | split: ','}
                    ##foreach $languages as $lang}
                        <option data-mpc-attr="##($lang == $.cookie.mpc_lang) ? 'selected' : ''}" value="##$lang}">##$lang}</option>
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
                    <p class="site-footer__tagline">##'wrapper_tagline' | lexicon}</p>
                </div>
                <div class="site-footer__col">
                    {set $contactsLex = $cp_id | lexiconsarr}
                    <h4 class="site-footer__head">{$contactsLex['mpc_resource_longtitle']}</h4>
                    <ul class="site-footer__contacts">
                        <li data-mpc-contact="phone|footer" data-mpc-key="addphone">
                            <i class="icon icon-plane" data-mpc-cfield="attributes"></i>
                            <a href="" data-mpc-cfield="value"><span>8(999)888-77-66</span></a>
                        </li>
                        <li data-mpc-contact="email|footer" data-mpc-key="mainemail">
                            <img class="site-footer__ico" src="assets/project_files/img/envelope.svg" data-svg data-mpc-nothumb data-mpc-cfield="icon" alt="">
                            <a href="mailto:email@domain.ru" data-mpc-cfield="fvalue">
                                <span data-mpc-cfield="value">email@domain.ru</span>
                            </a>
                        </li>
                    </ul>
                </div>
            </div>
            <div class="site-footer__bottom">
                <span>© {'' | date: 'Y'} MigxPageConfigurator. ##'wrapper_rights' | lexicon}</span>
                <span>##'wrapper_built' | lexicon}</span>
            </div>
        </div>
    </footer>
</div>

</body>
</html>
