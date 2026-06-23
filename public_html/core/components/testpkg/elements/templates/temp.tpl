<!--##{"templatename":"Шаблон-пример","wrapper":"wrapper","icon":"icon-gear","include":"file:pages/index.tpl"}##-->

<!-- ============================================================================
     ПРЕЗЕНТАЦИЯ ПАКЕТОВ MigxPageConfigurator (mpc) + mpcVisualEditor (mpcVE)
     Лендинг, который РАССКАЗЫВАЕТ о возможностях и одновременно их ПОКАЗЫВАЕТ:
     каждая секция собрана на реальных data-mpc-* маркерах, поэтому страница =
     ещё и полный тест пайплайна (нарезка → конфиг/чанки/TV/лексиконы → рендер →
     фронт-редактор). Сложные кейсы — на отдельной странице.

     Нарезать (на сервере):
     ./console/mpc cut temp --upd
       /usr/local/php/php-7.4/bin/php ~/art-sites.ru/htdocs/mpc-app/core/components/migxpageconfigurator/console/mgr_tpl.php web temp.tpl 1
     ============================================================================ -->


<!-- HERO — поля ресурса (rfield) + Template Variables (tv, авто-создаются из шаблона).
     rfield пишет в нативные поля ресурса (pagetitle/longtitle/content), tv — в TV. -->
<section id="{$id}" data-mpc-section="resource" data-mpc-name="Hero">
    <style>
      #{$id} { {$inline_styles} }
      </style>
    <div class="container">
        <h6 data-mpc-tv="subtitle" data-mpc-ftype="text">MODX · редактирование прямо на странице</h6>
        <h1 data-mpc-rfield="longtitle">Статичная вёрстка превращается в редактируемый сайт</h1>
        <p data-mpc-rfield="introtext">Размечаете готовый HTML атрибутами <code>data-mpc-*</code> и режете один раз. mpc создаёт секции, чанки, TV и лексиконы — а контент-менеджер правит страницы в браузере, без доступа в админку.</p>
        <div data-mpc-rfield="content"><p>MigxPageConfigurator берёт на себя бэкенд (нарезку и хранение), mpcVisualEditor — визуальное редактирование на живой странице.</p></div>
        <img data-mpc-tv="img" src="https://loremflickr.com/960/420/code,abstract" width="960" height="420" alt="Превью">
    </div>
</section>


<!-- КАК ЭТО РАБОТАЕТ — простые поля секции (data-mpc-field), типы (data-mpc-ftype),
     фон (bg_img), условный вывод (data-mpc-if), снятие обёртки (data-mpc-unwrap),
     ссылка-кнопка (data-mpc-field на <a>). Секция лексиконизируется (data-mpc-lexicon). -->
<section id="{$id}" data-mpc-section="howto" data-mpc-lexicon="howto" data-mpc-name="Как это работает" data-mpc-field="bg_img"
         style="background-image: url('https://loremflickr.com/1200/520/gradient,blue')">
    <span data-mpc-remove="1" data-mpc-field="inline_styles">
        padding-left: 20px;
        padding-right: 20px;
        position:relative;
        --mpc-text-color: #fff;
    </span>
    <style>
        #{$id} { {$inline_styles} }
        #{$id} > .container { position: relative; z-index: 1;}
        .overlay{
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
    <div class="container" >
        <h6 data-mpc-field="kicker">Один проход нарезки</h6>
        <h1 data-mpc-field="title" data-mpc-if="('{$title}' | lexicon) | match: 'Вёрстка*'" data-mpc-symbol="##">Вёрстка → нарезка → редактируемая страница</h1>

        <!-- textarea-поле: правится как многострочный текст; data-mpc-unwrap убирает div -->
        <div data-mpc-field="lead" data-mpc-ftype="textarea" data-mpc-if="$lead" data-mpc-unwrap>
            <p>1. Размечаете готовый HTML маркерами. 2. Запускаете нарезку. 3. mpc генерирует редактируемые секции, чанки, TV и файлы лексиконов.</p>
        </div>

        <!-- richtext-поле: правится в WYSIWYG-редакторе на фронте -->
        <div data-mpc-field="details" data-mpc-ftype="richtext" data-mpc-if="$details">
            <p>Контент-менеджер открывает страницу и кликает по любому тексту, картинке или списку — <b>правка идёт прямо здесь</b>, с пер-полевым сохранением.</p>
            <p>Структура (какие секции и поля есть) живёт в шаблоне: меняете вёрстку — перенарезаете. Контент остаётся за менеджером.</p>
        </div>

        <a href="#cta" data-mpc-field="cta">
            <span data-mpc-field="cta_text"><b>Посмотреть возможности</b></span>
        </a>
    </div>
</section>


<!-- ВОЗМОЖНОСТИ — повторяемые списки (ul[data-mpc-field] + li[data-mpc-item], поля
     элемента = data-mpc-field-1), вложенные списки (data-mpc-field-2), лимит строк
     (data-mpc-max), limit/offset (data-mpc-lim/-off), поля-списки значений
     (listbox/checkbox) с опциями из data-mpc-values. -->
<section id="{$id}" data-mpc-section="features" data-mpc-lexicon="features" data-mpc-name="Возможности">
    <style>
      #{$id} { {$inline_styles} }
    </style>
    <div class="container">
        <h6 data-mpc-field="kicker">Что умеют пакеты</h6>
        <h1 data-mpc-field="title">Возможности из коробки</h1>

        <!-- карточки-фичи: повторяемый список, максимум 6 элементов (data-mpc-max) -->
        <ul data-mpc-field="cards" data-mpc-fcap="Возможности" data-mpc-fdesc="карточки фич, максимум 6" data-mpc-max="6">
            <li data-mpc-item>
                <h5 data-mpc-field-1="title">Визуальное редактирование</h5>
                <p data-mpc-field-1="content">Клик по тексту, картинке или списку прямо на странице — без захода в админку.</p>
            </li>
            <li data-mpc-item>
                <h5 data-mpc-field-1="title">Поля любого типа</h5>
                <p data-mpc-field-1="content">Текст, richtext, число, дата, выпадайки, чекбоксы, теги, файлы, медиа.</p>
            </li>
            <li data-mpc-item>
                <h5 data-mpc-field-1="title">TV из шаблона</h5>
                <p data-mpc-field-1="content">Template Variables создаются автоматически при нарезке и привязываются к шаблону.</p>
            </li>
            <li data-mpc-item>
                <h5 data-mpc-field-1="title">Мультиязычность</h5>
                <p data-mpc-field-1="content">Переводимые значения уходят в лексиконы — контент по языкам без дублей страниц.</p>
            </li>
            <li data-mpc-item>
                <h5 data-mpc-field-1="title">История изменений</h5>
                <p data-mpc-field-1="content">Кто, когда и что поправил — с диффом и откатом правок.</p>
            </li>
            <li data-mpc-item>
                <h5 data-mpc-field-1="title">Блокировка и менеджер файлов</h5>
                <p data-mpc-field-1="content">Двое не правят одну страницу; загрузка картинок через медиа-источник.</p>
            </li>
        </ul>

        <!-- поле-выпадайка: опции из data-mpc-values (Caption==value); капшен в лексикон -->
        <h2 data-mpc-field="opts_title">Настраиваемые поля-списки</h2>
        <p><span data-mpc-field="plan_caption">Тариф:</span> <span data-mpc-field="plan" data-mpc-ftype="listbox" data-mpc-values="Не выбран==||Старт==start||Бизнес==business||Энтерпрайз==enterprise">business</span></p>
        <p><span data-mpc-field="channels_caption">Каналы связи:</span> <span data-mpc-field="channels" data-mpc-ftype="checkbox" data-mpc-values="Telegram==tg||Email==em||Телефон==phone">tg||em</span></p>
        <!-- @SELECT: опции тянутся из БД (резолвит сам migx) -->
        <p><span data-mpc-field="related_caption">Связанный ресурс:</span> <span data-mpc-field="related" data-mpc-ftype="listbox" data-mpc-values="@SELECT pagetitle,id FROM [[+PREFIX]]site_content WHERE published=1 LIMIT 10">43</span></p>

        <!-- вложенный список: группа → пункты внутри (data-mpc-field-2) -->
        <h2 data-mpc-field="stack_title">Что генерирует нарезка</h2>
        <ul data-mpc-field="stack">
            <li data-mpc-item>
                <h5 data-mpc-field-1="title">Структура</h5>
                <ul data-mpc-field-1="items">
                    <li data-mpc-item-1>
                        <h6 data-mpc-field-2="title">Секции и чанки</h6>
                        <p data-mpc-field-2="content">Каждая секция шаблона → отдельный редактируемый чанк.</p>
                    </li>
                </ul>
            </li>
            <li data-mpc-item>
                <h5 data-mpc-field-1="title">Данные</h5>
                <ul data-mpc-field-1="items">
                    <li data-mpc-item-1>
                        <h6 data-mpc-field-2="title">TV и лексиконы</h6>
                        <p data-mpc-field-2="content">Значения полей и переводы — в TV ресурса и файлах лексиконов.</p>
                    </li>
                </ul>
            </li>
        </ul>

        <!-- limit=2, offset=1: из трёх отзывов покажутся 2-й и 3-й (data-mpc-lim/-off) -->
        <h2 data-mpc-field="quotes_title">Отзывы разработчиков</h2>
        <ul data-mpc-field="quotes" data-mpc-lim="2" data-mpc-off="1">
            <li data-mpc-item>
                <p data-mpc-field-1="content">«Сдаю клиентские сайты, которые менеджер правит сам.» — Антон</p>
            </li>
            <li data-mpc-item>
                <p data-mpc-field-1="content">«Мультиязычность перестала быть болью.» — Игорь</p>
            </li>
            <li data-mpc-item>
                <p data-mpc-field-1="content">«Нарезал вёрстку — получил CMS-страницу.» — Мария</p>
            </li>
        </ul>
    </div>
</section>


<!-- СЕКЦИЯ-КОПИЯ — data-mpc-copy: наследует структуру одноимённой секции-ориджина
     ("features" = «Возможности» выше) и не плодит свой конфиг; лексикон — собственный. -->
<section id="{$id}" data-mpc-section="features" data-mpc-lexicon="features_demo" data-mpc-copy="templates/temp.tpl" data-mpc-name="Демо-копия секции «Возможности»">
    <style>
      #{$id} { {$inline_styles} }
    </style>
    <div class="container">
        <h1 data-mpc-field="title">Та же секция, другой контент</h1>
    </div>
</section>


<!-- ПОЛЯ ЛЮБОГО ТИПА — Template Variables всех типов (data-mpc-tv). TV авто-создаются
     из шаблона (тип из data-mpc-ftype, опции из data-mpc-values) в категории пакета;
     значения заграбливаются нарезкой. Опции listbox/option/checkbox — из elements TV. -->
<section id="{$id}" data-mpc-section="fieldtypes" data-mpc-name="Поля любого типа">
    <style>
      #{$id} { {$inline_styles} }
    </style>
    <div class="container">
        <h6 data-mpc-field="kicker">Любой тип — редактируется на месте</h6>
        <h1 data-mpc-field="title">Поля любого типа</h1>

        <ul>
            <li><h6>##'temp_fieldtypes_text' | lexicon}</h6><p data-mpc-tv="ft_text" data-mpc-ftype="text">Простой текст</p></li>
            <li><h6>##'temp_fieldtypes_textarea' | lexicon}</h6><p data-mpc-tv="ft_textarea" data-mpc-ftype="textarea">Несколько строк описания продукта</p></li>
            <li><h6>##'temp_fieldtypes_richtext' | lexicon}</h6><div data-mpc-tv="ft_richtext" data-mpc-ftype="richtext"><p>Текст с <b>жирным</b> и <i>курсивом</i></p></div></li>
            <li><h6>##'temp_fieldtypes_number' | lexicon}</h6><p data-mpc-tv="ft_number" data-mpc-ftype="number">42</p></li>
            <li><h6>##'temp_fieldtypes_date' | lexicon}</h6><p data-mpc-tv="ft_date" data-mpc-ftype="date">2026-06-05 12:30:00</p></li>
            <li><h6>##'temp_fieldtypes_email' | lexicon}</h6><p data-mpc-tv="ft_email" data-mpc-ftype="email">hello@example.ru</p></li>
            <li><h6>##'temp_fieldtypes_url' | lexicon}</h6><p data-mpc-tv="ft_url" data-mpc-ftype="url">https://example.ru</p></li>
            <li><h6>##'temp_fieldtypes_tags' | lexicon}</h6><p data-mpc-tv="ft_tag" data-mpc-ftype="tag">modx,cms,верстка</p></li>
            <li><h6>##'temp_fieldtypes_file' | lexicon}</h6><p data-mpc-tv="ft_file" data-mpc-ftype="file">/assets/files/guide.pdf</p></li>
            <li><h6>##'temp_fieldtypes_listbox' | lexicon}</h6><p data-mpc-tv="ft_listbox" data-mpc-ftype="listbox" data-mpc-values="Малый==s||Средний==m||Большой==l">m</p></li>
            <li><h6>##'temp_fieldtypes_listbox2' | lexicon}</h6><p data-mpc-tv="ft_listbox2" data-mpc-ftype="listbox" data-mpc-values="Большой||Средний||Маленький">Маленький</p></li>
            <li><h6>##'temp_fieldtypes_multi' | lexicon}</h6><p data-mpc-tv="ft_multi" data-mpc-ftype="listbox-multiple" data-mpc-values="Хлопок==cotton||Бамбук==bamboo||Лён==linen">cotton||linen</p></li>
            <li><h6>##'temp_fieldtypes_radio' | lexicon}</h6><p data-mpc-tv="ft_option" data-mpc-ftype="option" data-mpc-values="Малый==s||Средний==m||Большой==l">s</p></li>
            <li><h6>##'temp_fieldtypes_checkboxes' | lexicon}</h6><p data-mpc-tv="ft_checkbox" data-mpc-ftype="checkbox" data-mpc-values="Хлопок==cotton||Бамбук==bamboo||Лён==linen">cotton||linen</p></li>
            <li><h6>##'temp_fieldtypes_dbselect' | lexicon}</h6><p data-mpc-tv="ft_select" data-mpc-ftype="listbox" data-mpc-values="@SELECT pagetitle,id FROM [[+PREFIX]]site_content WHERE parent=1 LIMIT 10">43</p></li>
            <li><h6>##'temp_fieldtypes_image' | lexicon}</h6><img data-mpc-tv="ft_image" src="https://loremflickr.com/320/200/pillow" width="320" height="200" alt="Картинка"></li>
        </ul>
    </div>
</section>


<!-- МЕДИА — img/picture/video/audio (data-mpc-field). Медиа скачивается на сервер и
     оптимизируется (превью); data-mpc-nothumb/-nolazy управляют этим. -->
<section id="{$id}" data-mpc-section="media" data-mpc-lexicon="media" data-mpc-name="Медиа">
    <style>
      #{$id} { {$inline_styles} }
    </style>
    <div class="container">
        <h6 data-mpc-field="kicker">Скачивает и оптимизирует</h6>
        <h1 data-mpc-field="title">Медиа из коробки</h1>

        <!-- картинки в один ряд, по 50% ширины -->
        <div class="media-grid">
            <!-- одиночная картинка: грабятся src/width/height/alt, создаётся превью -->
            <img data-mpc-field="photo" src="https://loremflickr.com/720/440/workspace" data-mpc-nothumb="1" width="720" height="440" alt="Рабочее место">

            <!-- адаптивная картинка <picture> + <source> под десктоп -->
            <picture data-mpc-field="cover" data-mpc-if="$cover">
                <img src="https://loremflickr.com/720/480/design" width="720" height="480" alt="Адаптив">
                <source srcset="https://loremflickr.com/1280/480/design" media="(min-width: 992px)">
            </picture>
        </div>

        <!-- аудио на всю ширину -->
        <audio data-mpc-field="podcast" controls src="https://www.w3schools.com/html/horse.mp3"></audio>

        <!-- видео на всю ширину под аудио -->
        <video data-mpc-field="promo" controls muted poster="https://loremflickr.com/960/540/screen">
            <source src="https://www.w3schools.com/html/mov_bbb.mp4" type="video/mp4">
        </video>
    </div>
</section>


<!-- ДИНАМИКА И I18N — сниппеты (data-mpc-snippet), подключение/парсинг чанков
     (data-mpc-chunk + data-mpc-include/-parse), вспомогательные поля (data-mpc-remove). -->
<section id="{$id}" data-mpc-section="dynamic" data-mpc-name="Динамика и мультиязычность">
    <style>
      #{$id} { {$inline_styles} }
    </style>
    <div class="container">
        <h6 data-mpc-field="kicker">Сниппеты · чанки · лексиконы</h6>
        <h1 data-mpc-field="title">Динамические блоки</h1>
        <p data-mpc-field="content">Внутри секций можно вызывать сниппеты и подключать чанки — вывод списков, related-контент, переключение языка.</p>

        <!-- вспомогательное поле-параметр: в HTML страницы не попадёт (data-mpc-remove) -->
        <span data-mpc-field="resources" data-mpc-remove="1">1,2,3</span>

        <!-- вызов сниппета на фронте; внутренний <a> — шаблон одного результата -->
        <ul data-mpc-snippet="!pdoResources|default" data-mpc-symbol="##">
            <li data-mpc-chunk="pdoresources/default/item.tpl" data-mpc-res="{$id}">
                <a href="{$uri}">
                    {set $lexicons = $id | lexiconsarr: $template}
                    <span data-mpc-rfield="longtitle">{$lexicons[$longtitle] ?: $longtitle}</span>
                </a>
            </li>
        </ul>
    </div>
</section>


<!-- ОБЩИЕ БЛОКИ + CTA — статичная секция (data-mpc-static): значения общие для всех
     страниц сайта (хранятся на странице статичных блоков), без пер-ресурсного foreach. -->
<section id="{$id}" data-mpc-section="footer_cta" data-mpc-lexicon="footer_cta" data-mpc-static data-mpc-name="CTA (статичная секция)">
    <style>
      #{$id} { {$inline_styles} }
    </style>

    <div data-mpc-field="list_simple_video" data-mpc-remove="1">
        <div data-mpc-item="1">
            <span data-mpc-field-1="content">web</span>
            <video data-mpc-field-1="video" controls muted poster="https://loremflickr.com/960/540/screen">
                <source src="https://www.w3schools.com/html/mov_bbb.mp4" type="video/mp4">
            </video>
        </div>
    </div>

    <div class="container">
        <h6 data-mpc-field="kicker">Один блок — на всех страницах</h6>
        <h1 data-mpc-field="title">Попробуйте редактор</h1>
        <p data-mpc-field="content">Эта секция статична: её правят один раз, и изменение видно на всех страницах сайта.</p>
        <p data-mpc-lexicon="common:site_test_msg">Это <b>тестовое</b> сообщение для <em>лексиконов.</em></p>
        <div data-mpc-lexicon="common:site_test_msg_one" data-mpc-unwrap="1">
            <p>Это <b>ещё одно тестовое</b> сообщение для <em>лексиконов.</em></p>
        </div>
        <a href="#" data-mpc-field="cta"><span data-mpc-field="cta_text"><b>Открыть документацию</b></span></a>
    </div>
</section>
