<!DOCTYPE html>
<html lang="ru">
<head>


    <title>Шаблон Шаблон-пример</title>
    <meta name="description" content="#">
    <meta charset="utf-8">
    <meta http-equiv="x-ua-compatible" content="ie=edge">
    <meta name="viewport" content="width=device-width, user-scalable=no, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0">

    <base href="{$_modx->config.site_url}">

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


    <style>
      :root {
        --mpc-gap: 1.5rem;
        --mpc-radius: 18px;
        --mpc-border: #e9ecf5;
        --mpc-muted: #6b7280;
        --mpc-ink: #1c2030;
        --mpc-text-color: #4b5165;
        --mpc-accent: #6366f1;
        --mpc-accent-2: #a855f7;
        --mpc-shadow: 0 18px 40px -22px rgba(40, 40, 90, .35);
      }

      * {
        box-sizing: border-box;
      }

      body {
        font-family: "Inter", system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif;
        color: var(--mpc-ink);
        line-height: 1.65;
        background: radial-gradient(1100px 560px at 100% -8%, #eef1ff 0, transparent 52%),
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

</head>

<body>
<div data-mpc-section="wrapper" class="wrapper" data-mpc-unwrap="1">


    <!-- header -->
    <header class="site-header">
        <div class="site-header__inner">
            <a href="" class="site-header__logo">MPC+VE</a>
            <div class="site-header__actions" data-mpc-contact="phone|header" data-mpc-key="mainphone">
                {'contact_mainphone_phone_header_caption' | lexicon}
                <a href="tel:{'contact_mainphone_phone_value' | lexicon}" class="site-header__phone" data-mpc-cfield="value">{'contact_mainphone_phone_fvalue' | lexicon}</a>
                <select class="site-header__lang" name="language" data-choose-lang>
                    {set $languages = $_modx->config.mpc_available_languages | split: ','}
                    {foreach $languages as $lang}
                        <option {($lang == $.cookie.mpc_lang) ? 'selected' : ''} value="{$lang}">{$lang}</option>
                    {/foreach}
                </select>
            </div>
        </div>
    </header>

    <!-- wrap -->
    <main class="wrap">

        <!--CONTENT-->
        <section id="resource_17807758387333" data-mpc-section="resource" data-mpc-name="Hero">
            <div class="container">
                <h6 data-mpc-tv="subtitle" data-mpc-ftype="text">{'mpc_resource_tv_subtitle' | lexicon}</h6>
                <h1 data-mpc-rfield="longtitle">{'mpc_resource_longtitle' | lexicon}</h1>
                <p data-mpc-rfield="introtext">{'mpc_resource_introtext' | lexicon}</p>
                <div data-mpc-rfield="content">{'mpc_resource_content' | lexicon}</div>
                <img data-mpc-tv="img" src="/assets/mpcmedia/images/resource/code-abstract.jpg" width="960" height="420" alt="Превью">
            </div>
        </section>
        <section id="howto_17807758387562" data-mpc-section="howto" data-mpc-lexicon="howto" data-mpc-name="Как это работает" data-mpc-field="bg_img"
                 data-lazy="bg:/assets/components/phpthumbof/cache/gradient-blue.af3927e4465ae183ba9105c925f64192.webp">

            <style>
              #howto_17807758387562 {
                padding-left: 20px;
                padding-right: 20px;
                position: relative;
                --mpc-text-color: #fff;
              }

              #howto_17807758387562 > .container {
                position: relative;
                z-index: 1;
              }

              .overlay {
                position: absolute;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background-color: rgba(90, 90, 90, 0.4);
                z-index: 0;
              }
            </style>
            <div class="overlay"></div>
            <div class="container">
                <h6 data-mpc-field="kicker">{'howto_kicker' | lexicon}</h6>
                <h1 data-mpc-field="title">{'howto_title' | lexicon}</h1>

                <!-- textarea-поле: правится как многострочный текст; data-mpc-unwrap убирает div -->
                {'howto_lead' | lexicon}


                <!-- richtext-поле: правится в WYSIWYG-редакторе на фронте -->
                <div data-mpc-field="details" data-mpc-ftype="richtext" data-mpc-if="$details">{'howto_details' | lexicon}</div>


                <a href="howto_cta" data-mpc-field="cta">
                    <span data-mpc-field="cta_text">{'howto_cta_text' | lexicon}</span>
                </a>
            </div>
        </section>
        <section id="features_17807758388164" data-mpc-section="features" data-mpc-lexicon="features" data-mpc-name="Возможности">
            <div class="container">
                <h6 data-mpc-field="kicker">{'features_kicker' | lexicon}</h6>
                <h1 data-mpc-field="title">{'features_title' | lexicon}</h1>

                <!-- карточки-фичи: повторяемый список, максимум 6 элементов (data-mpc-max) -->
                <ul data-mpc-field="cards" data-mpc-fcap="Возможности" data-mpc-fdesc="карточки фич, максимум 6" data-mpc-max="6">
                    <li data-mpc-item>
                        <h5 data-mpc-field-1="title">{'features_cards_title' | lexicon}</h5>
                        <p data-mpc-field-1="content">{'features_cards_content' | lexicon}</p>
                    </li>
                    <li data-mpc-item>
                        <h5 data-mpc-field-1="title">{'features_cards_1_title_1' | lexicon}</h5>
                        <p data-mpc-field-1="content">{'features_cards_1_content_1' | lexicon}</p>
                    </li>
                    <li data-mpc-item>
                        <h5 data-mpc-field-1="title">{'features_cards_2_title_2' | lexicon}</h5>
                        <p data-mpc-field-1="content">{'features_cards_2_content_2' | lexicon}</p>
                    </li>
                    <li data-mpc-item>
                        <h5 data-mpc-field-1="title">{'features_cards_3_title_3' | lexicon}</h5>
                        <p data-mpc-field-1="content">{'features_cards_3_content_3' | lexicon}</p>
                    </li>
                    <li data-mpc-item>
                        <h5 data-mpc-field-1="title">{'features_cards_4_title_4' | lexicon}</h5>
                        <p data-mpc-field-1="content">{'features_cards_4_content_4' | lexicon}</p>
                    </li>
                    <li data-mpc-item>
                        <h5 data-mpc-field-1="title">{'features_cards_5_title_5' | lexicon}</h5>
                        <p data-mpc-field-1="content">{'features_cards_5_content_5' | lexicon}</p>
                    </li>
                </ul>

                <!-- поле-выпадайка: опции из data-mpc-values (Caption==value); капшен в лексикон -->
                <h2 data-mpc-field="opts_title">{'features_opts_title' | lexicon}</h2>
                <p>
                    <span data-mpc-field="plan_caption">{'features_plan_caption' | lexicon}</span>
                    <span data-mpc-field="plan" data-mpc-ftype="listbox"
                          data-mpc-values="Не выбран==||Старт==start||Бизнес==business||Энтерпрайз==enterprise">{'features_plan_business' | lexicon}</span>
                </p>
                <p>
                    <span data-mpc-field="channels_caption">{'features_channels_caption' | lexicon}</span>
                    <span data-mpc-field="channels" data-mpc-ftype="checkbox" data-mpc-values="Telegram==tg||Email==em||Телефон==phone">{('features_channels_tg') | lexicon}
                        , {('features_channels_em') | lexicon}</span>
                </p>
                <!-- @SELECT: опции тянутся из БД (резолвит сам migx) -->
                <p>
                    <span data-mpc-field="related_caption">{'features_related_caption' | lexicon}</span>
                    <span data-mpc-field="related" data-mpc-ftype="listbox" data-mpc-values="@SELECT pagetitle,id FROM modx_site_content WHERE published=1 LIMIT 10">43</span>
                </p>

                <!-- вложенный список: группа → пункты внутри (data-mpc-field-2) -->
                <h2 data-mpc-field="stack_title">{'features_stack_title' | lexicon}</h2>
                <ul data-mpc-field="stack">
                    <li data-mpc-item>
                        <h5 data-mpc-field-1="title">{'features_stack_title' | lexicon}</h5>
                        <ul data-mpc-field-1="items">
                            <li data-mpc-item-1>
                                <h6 data-mpc-field-2="title">{'features_stack_items_title' | lexicon}</h6>
                                <p data-mpc-field-2="content">{'features_stack_items_content' | lexicon}</p>
                            </li>
                        </ul>
                    </li>
                    <li data-mpc-item>
                        <h5 data-mpc-field-1="title">{'features_stack_1_title_1' | lexicon}</h5>
                        <ul data-mpc-field-1="items">
                            <li data-mpc-item-1>
                                <h6 data-mpc-field-2="title">{'features_stack_1_items_title' | lexicon}</h6>
                                <p data-mpc-field-2="content">{'features_stack_1_items_content' | lexicon}</p>
                            </li>
                        </ul>
                    </li>
                </ul>

                <!-- limit=2, offset=1: из трёх отзывов покажутся 2-й и 3-й (data-mpc-lim/-off) -->
                <h2 data-mpc-field="quotes_title">{'features_quotes_title' | lexicon}</h2>
                <ul data-mpc-field="quotes" data-mpc-lim="2" data-mpc-off="1">
                    <li data-mpc-item>
                        <p data-mpc-field-1="content">{'features_quotes_1_content_1' | lexicon}</p>
                    </li>
                    <li data-mpc-item>
                        <p data-mpc-field-1="content">{'features_quotes_2_content_2' | lexicon}</p>
                    </li>
                </ul>
            </div>
        </section>
        <section id="features_17807758388294" data-mpc-section="features" data-mpc-lexicon="features" data-mpc-name="Возможности">
            <div class="container">
                <h6 data-mpc-field="kicker">{'' | lexicon}</h6>
                <h1 data-mpc-field="title">{'features_demo_title' | lexicon}</h1>

                <!-- карточки-фичи: повторяемый список, максимум 6 элементов (data-mpc-max) -->
                <ul data-mpc-field="cards" data-mpc-fcap="Возможности" data-mpc-fdesc="карточки фич, максимум 6" data-mpc-max="6"></ul>

                <!-- поле-выпадайка: опции из data-mpc-values (Caption==value); капшен в лексикон -->
                <h2 data-mpc-field="opts_title">{'' | lexicon}</h2>
                <p>
                    <span data-mpc-field="plan_caption">{'' | lexicon}</span>
                    <span data-mpc-field="plan" data-mpc-ftype="listbox"
                          data-mpc-values="Не выбран==||Старт==start||Бизнес==business||Энтерпрайз==enterprise">{'features_plan_' | lexicon}</span>
                </p>
                <p>
                    <span data-mpc-field="channels_caption">{'' | lexicon}</span>
                    <span data-mpc-field="channels" data-mpc-ftype="checkbox" data-mpc-values="Telegram==tg||Email==em||Телефон==phone"></span>
                </p>
                <!-- @SELECT: опции тянутся из БД (резолвит сам migx) -->
                <p>
                    <span data-mpc-field="related_caption">{'' | lexicon}</span>
                    <span data-mpc-field="related" data-mpc-ftype="listbox" data-mpc-values="@SELECT pagetitle,id FROM modx_site_content WHERE published=1 LIMIT 10"></span>
                </p>

                <!-- вложенный список: группа → пункты внутри (data-mpc-field-2) -->
                <h2 data-mpc-field="stack_title">{'' | lexicon}</h2>
                <ul data-mpc-field="stack"></ul>

                <!-- limit=2, offset=1: из трёх отзывов покажутся 2-й и 3-й (data-mpc-lim/-off) -->
                <h2 data-mpc-field="quotes_title">{'' | lexicon}</h2>
                <ul data-mpc-field="quotes" data-mpc-lim="2" data-mpc-off="1"></ul>
            </div>
        </section>
        <section id="media_17807758388931" data-mpc-section="media" data-mpc-lexicon="media" data-mpc-name="Медиа">
            <div class="container">
                <h6 data-mpc-field="kicker">{'media_kicker' | lexicon}</h6>
                <h1 data-mpc-field="title">{'media_title' | lexicon}</h1>

                <!-- картинки в один ряд, по 50% ширины -->
                <div class="media-grid">
                    <!-- одиночная картинка: грабятся src/width/height/alt, создаётся превью -->
                    <img data-mpc-field="photo" src="assets/components/migxpageconfigurator/images/fake-img.png" width="720" height="440" alt="{'media_photo_alt' | lexicon}"
                         data-lazy="/assets/components/phpthumbof/cache/workspace.afda893e98b4bb4f7adf0297f12bdbaf.webp">

                    <!-- адаптивная картинка <picture> + <source> под десктоп -->
                    <picture data-mpc-field="cover" data-mpc-if="$cover">
                        <source media="(min-width: 992px)" data-lazy="/assets/components/phpthumbof/cache/design.e4ca4c89a5dc065a5c9f8069f855e076.webp">

                        <img src="assets/components/migxpageconfigurator/images/fake-img.png" width="720" height="480" alt="{'media_cover_alt' | lexicon}"
                             data-lazy="/assets/components/phpthumbof/cache/design.eaab6a038695849e751b2908020723f8.webp">
                    </picture>

                </div>

                <!-- аудио на всю ширину -->
                <audio data-mpc-field="podcast" controls data-lazy="/assets/mpcmedia/audios/media/horse.mp3"></audio>

                <!-- видео на всю ширину под аудио -->
                <video data-mpc-field="promo" controls muted poster="/assets/mpcmedia/images/media/screen.jpg">
                    <source type="video/mp4" data-lazy="/assets/mpcmedia/videos/media/mov_bbb.mp4">


                </video>
            </div>
        </section>
        <section id="fieldtypes_17807758388594" data-mpc-section="fieldtypes" data-mpc-name="Поля любого типа">
            <div class="container">
                <h6 data-mpc-field="kicker">{'fieldtypes_kicker' | lexicon}</h6>
                <h1 data-mpc-field="title">{'fieldtypes_title' | lexicon}</h1>

                <ul>
                    <li>
                        <h6>Текст</h6>
                        <p data-mpc-tv="ft_text" data-mpc-ftype="text">{'mpc_resource_tv_ft_text' | lexicon}</p>
                    </li>
                    <li>
                        <h6>Многострочный</h6>
                        <p data-mpc-tv="ft_textarea" data-mpc-ftype="textarea">{'mpc_resource_tv_ft_textarea' | lexicon}</p>
                    </li>
                    <li>
                        <h6>Форматированный</h6>
                        <div data-mpc-tv="ft_richtext" data-mpc-ftype="richtext">{'mpc_resource_tv_ft_richtext' | lexicon}</div>
                    </li>
                    <li>
                        <h6>Число</h6>
                        <p data-mpc-tv="ft_number" data-mpc-ftype="number">42</p>
                    </li>
                    <li>
                        <h6>Дата</h6>
                        <p data-mpc-tv="ft_date" data-mpc-ftype="date">2026-06-05 12:30:00</p>
                    </li>
                    <li>
                        <h6>E-mail</h6>
                        <p data-mpc-tv="ft_email" data-mpc-ftype="email">hello@example.ru</p>
                    </li>
                    <li>
                        <h6>Ссылка</h6>
                        <p data-mpc-tv="ft_url" data-mpc-ftype="url">https://example.ru</p>
                    </li>
                    <li>
                        <h6>Теги</h6>
                        <p data-mpc-tv="ft_tag" data-mpc-ftype="tag">modx,cms,верстка</p>
                    </li>
                    <li>
                        <h6>Файл</h6>
                        <p data-mpc-tv="ft_file" data-mpc-ftype="file">/assets/files/guide.pdf</p>
                    </li>
                    <li>
                        <h6>Выпадайка (Caption==value)</h6>
                        <p data-mpc-tv="ft_listbox" data-mpc-ftype="listbox" data-mpc-values="Малый==s||Средний==m||Большой==l">{'mpc_resource_tv_ft_listbox_m' | lexicon}</p>
                    </li>
                    <li>
                        <h6>Выпадайка (список, кириллица)</h6>
                        <p data-mpc-tv="ft_listbox2" data-mpc-ftype="listbox"
                           data-mpc-values="Большой||Средний||Маленький">{'mpc_resource_tv_ft_listbox2_malenkij' | lexicon}</p>
                    </li>
                    <li>
                        <h6>Мультивыбор</h6>
                        <p data-mpc-tv="ft_multi" data-mpc-ftype="listbox-multiple"
                           data-mpc-values="Хлопок==cotton||Бамбук==bamboo||Лён==linen">{('mpc_resource_tv_ft_multi_cotton') | lexicon}
                            , {('mpc_resource_tv_ft_multi_linen') | lexicon}</p>
                    </li>
                    <li>
                        <h6>Радио (option)</h6>
                        <p data-mpc-tv="ft_option" data-mpc-ftype="option" data-mpc-values="Малый==s||Средний==m||Большой==l">{'mpc_resource_tv_ft_option_s' | lexicon}</p>
                    </li>
                    <li>
                        <h6>Чекбоксы</h6>
                        <p data-mpc-tv="ft_checkbox" data-mpc-ftype="checkbox"
                           data-mpc-values="Хлопок==cotton||Бамбук==bamboo||Лён==linen">{('mpc_resource_tv_ft_checkbox_cotton') | lexicon}
                            , {('mpc_resource_tv_ft_checkbox_linen') | lexicon}</p>
                    </li>
                    <li>
                        <h6>Список из БД (@SELECT)</h6>
                        <p data-mpc-tv="ft_select" data-mpc-ftype="listbox" data-mpc-values="@SELECT pagetitle,id FROM [[+PREFIX]]site_content WHERE published=1 LIMIT 10">
                            43</p>
                    </li>
                    <li>
                        <h6>Изображение</h6>
                        <img data-mpc-tv="ft_image" src="/assets/mpcmedia/images/resource/pillow.jpg" width="320" height="200" alt="Картинка">
                    </li>
                </ul>
            </div>
        </section>
        <section id="dynamic_17807758389191" data-mpc-section="dynamic" data-mpc-name="Динамика и мультиязычность">
            <div class="container">
                <h6 data-mpc-field="kicker">{'dynamic_kicker' | lexicon}</h6>
                <h1 data-mpc-field="title">{'dynamic_title' | lexicon}</h1>
                <p data-mpc-field="content">{'dynamic_content' | lexicon}</p>

                <!-- вспомогательное поле-параметр: в HTML страницы не попадёт (data-mpc-remove) -->


                <!-- вызов сниппета на фронте; внутренний <a> — шаблон одного результата -->
                <ul data-mpc-snippet="!pdoResources|default">
                    <a href="page-types/tpl-shablon-primer.html" data-mpc-chunk="pdoresources/default/item.tpl">
                        <span data-mpc-rfield="pagetitle">Шаблон Шаблон-пример</span>
                    </a></ul>
            </div>
        </section>
        {set $section = '!getStaticSection'| snippet:['section_name' => 'footer_cta']}{if $section}{set $lexicon_prefix = $section.lexicon_prefix}{set $kicker = $section.kicker}{set $title = $section.title}{set $content = $section.content}{set $cta = $section.cta}{set $cta_text = $section.cta_text}{set $inline_styles = $section.inline_styles}{set $class_names = $section.class_names}{set $position = $section.position}{set $contacts = $section.contacts}{/if}
        <section id="cta" data-mpc-section="footer_cta" data-mpc-lexicon="footer_cta" data-mpc-static data-mpc-name="CTA (статичная секция)">
            <div class="container">
                <h6 data-mpc-field="kicker">{'footer_cta_kicker' | lexicon}</h6>
                <h1 data-mpc-field="title">{'footer_cta_title' | lexicon}</h1>
                <p data-mpc-field="content">{'footer_cta_content' | lexicon}</p>
                <a href="{$cta}" data-mpc-field="cta">
                    <span data-mpc-field="cta_text">{'footer_cta_cta_text' | lexicon}</span>
                </a>
            </div>
        </section>                            <!--CONTENT-->

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
                    <h4 class="site-footer__head">Контакты</h4>
                    <ul class="site-footer__contacts">
                        <li data-mpc-contact="phone|footer">
                            <img class="site-footer__ico" src="assets/components/migxpageconfigurator/images/fake-img.png" data-mpc-nothumb data-svg
                                 data-mpc-cfield="attributes" alt="" data-lazy="assets/project_files/img/phone.svg">
                            <a href="tel:{'contact_9c9c74ceb6accf3662ff270866dff8b3_phone_value' | lexicon}"
                               data-mpc-cfield="value">{'contact_9c9c74ceb6accf3662ff270866dff8b3_phone_fvalue' | lexicon}</a>
                        </li>
                        <li data-mpc-contact="email|footer">
                            <img class="site-footer__ico" src="assets/components/migxpageconfigurator/images/fake-img.png" data-mpc-nothumb data-svg
                                 data-mpc-cfield="attributes" alt="" data-lazy="assets/project_files/img/envelope.svg">
                            <a href="mailto:{'contact_f97568a29d689de9b2d25f588bc3f4aa_email_value' | lexicon}"
                               data-mpc-cfield="value">{'contact_f97568a29d689de9b2d25f588bc3f4aa_email_fvalue' | lexicon}</a>
                        </li>
                    </ul>
                </div>
            </div>
            <div class="site-footer__bottom">
                <span>© 2026 MigxPageConfigurator. Все права защищены.</span>
                <span>Собрано на mpc + mpcVE</span>
            </div>
        </div>
    </footer>
</div>
</body>
</html>
