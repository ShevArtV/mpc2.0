<!--##{"templatename":"Шаблон-пример","wrapper":"wrapper","icon":"icon-gear","include":"file:pages/index.tpl"}##-->

<!-- ============================================================================
     ИСХОДНИК ТЕМЫ «bento» (страница). Режется ТАК ЖЕ, как базовый temp.tpl, но
     выхлоп идёт в sections/_themes/bento/ и контент НЕ трогается (Grabber/Render
     не запускаются):
         ./console/mpc cut temp --theme=bento

     Тема — оверрайд-слой: здесь описаны только секции, которым нужна ДРУГАЯ
     РАЗМЕТКА (структурно иная сетка). Секции, которых тут нет, на рендере
     возьмутся из базовой вёрстки sections/*.tpl и перестроятся под bento-сетку
     из CSS темы (_themes/bento/wrapper.tpl). Имена секций/полей/лексиконов —
     те же, что в базе, поэтому контент подхватывается без изменений.
     ============================================================================ -->


<!-- HERO в раскладке СПЛИТ: текст слева, картинка справа (.hero-split) — вместо
     базовой одной колонки. Все поля/маркеры (rfield/tv/ftype) идентичны базовой
     секции resource, меняется только обёртка/сетка. -->
<section id="{$id}" data-mpc-section="resource" data-mpc-name="Hero">
    <style>
      #{$id} { {$inline_styles} }
    </style>
    <div class="container hero-split">
        <div class="hero-split__text">
            <h6 data-mpc-tv="subtitle" data-mpc-ftype="text">MODX · редактирование прямо на странице</h6>
            <h1 data-mpc-rfield="longtitle">Статичная вёрстка превращается в редактируемый сайт</h1>
            <p data-mpc-rfield="introtext">Размечаете готовый HTML атрибутами <code>data-mpc-*</code> и режете один раз. mpc создаёт секции, чанки, TV и лексиконы — а контент-менеджер правит страницы в браузере, без доступа в админку.</p>
            <div data-mpc-rfield="content"><p>MigxPageConfigurator берёт на себя бэкенд (нарезку и хранение), mpcVisualEditor — визуальное редактирование на живой странице.</p></div>
        </div>
        <img data-mpc-tv="img" class="hero-split__media" src="https://loremflickr.com/960/420/code,abstract" width="960" height="420" alt="Превью">
    </div>
</section>


<!-- ВОЗМОЖНОСТИ в bento-раскладке: карточки фич — нумерованные плитки разного
     размера (.bento-cards), а «экстра»-поля (списки/стек/отзывы) сложены в три
     колонки (.feature-extras) вместо вертикальной стопки. Поля/типы/значения —
     те же, что в базовой секции features. -->
<section id="{$id}" data-mpc-section="features" data-mpc-lexicon="features" data-mpc-name="Возможности">
    <style>
      #{$id} { {$inline_styles} }
    </style>
    <div class="container">
        <div class="section-head">
            <h6 data-mpc-field="kicker">Что умеют пакеты</h6>
            <h1 data-mpc-field="title">Возможности из коробки</h1>
        </div>

        <!-- карточки-фичи: повторяемый список, максимум 6 элементов (data-mpc-max) -->
        <ul data-mpc-field="cards" data-mpc-fcap="Возможности" data-mpc-fdesc="карточки фич, максимум 6" data-mpc-max="6" class="bento-cards">
            <li data-mpc-item class="bento-card">
                <div class="bento-card__body">
                    <h5 data-mpc-field-1="title">Визуальное редактирование</h5>
                    <p data-mpc-field-1="content">Клик по тексту, картинке или списку прямо на странице — без захода в админку.</p>
                </div>
            </li>
            <li data-mpc-item class="bento-card">
                <div class="bento-card__body">
                    <h5 data-mpc-field-1="title">Поля любого типа</h5>
                    <p data-mpc-field-1="content">Текст, richtext, число, дата, выпадайки, чекбоксы, теги, файлы, медиа.</p>
                </div>
            </li>
            <li data-mpc-item class="bento-card">
                <div class="bento-card__body">
                    <h5 data-mpc-field-1="title">TV из шаблона</h5>
                    <p data-mpc-field-1="content">Template Variables создаются автоматически при нарезке и привязываются к шаблону.</p>
                </div>
            </li>
            <li data-mpc-item class="bento-card">
                <div class="bento-card__body">
                    <h5 data-mpc-field-1="title">Мультиязычность</h5>
                    <p data-mpc-field-1="content">Переводимые значения уходят в лексиконы — контент по языкам без дублей страниц.</p>
                </div>
            </li>
            <li data-mpc-item class="bento-card">
                <div class="bento-card__body">
                    <h5 data-mpc-field-1="title">История изменений</h5>
                    <p data-mpc-field-1="content">Кто, когда и что поправил — с диффом и откатом правок.</p>
                </div>
            </li>
            <li data-mpc-item class="bento-card">
                <div class="bento-card__body">
                    <h5 data-mpc-field-1="title">Блокировка и менеджер файлов</h5>
                    <p data-mpc-field-1="content">Двое не правят одну страницу; загрузка картинок через медиа-источник.</p>
                </div>
            </li>
        </ul>

        <!-- «экстра»-поля секции в трёх колонках -->
        <div class="feature-extras">
            <div class="feature-extras__col">
                <h2 data-mpc-field="opts_title">Настраиваемые поля-списки</h2>
                <p><span data-mpc-field="plan_caption">Тариф:</span> <span data-mpc-field="plan" data-mpc-ftype="listbox" data-mpc-values="Не выбран==||Старт==start||Бизнес==business||Энтерпрайз==enterprise">business</span></p>
                <p><span data-mpc-field="channels_caption">Каналы связи:</span> <span data-mpc-field="channels" data-mpc-ftype="checkbox" data-mpc-values="Telegram==tg||Email==em||Телефон==phone">tg||em</span></p>
                <p><span data-mpc-field="related_caption">Связанный ресурс:</span> <span data-mpc-field="related" data-mpc-ftype="listbox" data-mpc-values="@SELECT pagetitle,id FROM [[+PREFIX]]site_content WHERE published=1 LIMIT 10">43</span></p>
            </div>

            <div class="feature-extras__col">
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
            </div>

            <div class="feature-extras__col">
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
        </div>
    </div>
</section>


<!-- МЕДИА в раскладке «постер-сцена + лента»: видео-промо крупным блоком сверху
     (.media-stage), под ним картинки в ряд (.media-row), аудио — узкой плашкой
     (.media-bar). Порядок и обёртки иные, чем в базовой .media-grid; поля
     (photo/cover/podcast/promo) те же. -->
<section id="{$id}" data-mpc-section="media" data-mpc-lexicon="media" data-mpc-name="Медиа">
    <style>
      #{$id} { {$inline_styles} }
    </style>
    <div class="container">
        <div class="section-head">
            <h6 data-mpc-field="kicker">Скачивает и оптимизирует</h6>
            <h1 data-mpc-field="title">Медиа из коробки</h1>
        </div>

        <!-- видео-промо крупным блоком-сценой -->
        <div class="media-stage">
            <video data-mpc-field="promo" controls muted poster="https://loremflickr.com/960/540/screen">
                <source src="https://www.w3schools.com/html/mov_bbb.mp4" type="video/mp4">
            </video>
        </div>

        <!-- картинки в ряд под сценой -->
        <div class="media-row">
            <!-- одиночная картинка: грабятся src/width/height/alt, создаётся превью -->
            <img data-mpc-field="photo" src="https://loremflickr.com/720/440/workspace" width="720" height="440" alt="Рабочее место">
            <!-- адаптивная картинка <picture> + <source> под десктоп -->
            <picture data-mpc-field="cover" data-mpc-if="$cover">
                <img src="https://loremflickr.com/720/480/design" width="720" height="480" alt="Адаптив">
                <source srcset="https://loremflickr.com/1280/480/design" media="(min-width: 992px)">
            </picture>
        </div>

        <!-- аудио узкой плашкой -->
        <div class="media-bar">
            <audio data-mpc-field="podcast" controls src="https://www.w3schools.com/html/horse.mp3"></audio>
        </div>
    </div>
</section>
