<strong>MigxPageConfigurator</strong>

Author: Shevchenko Artur <shev.art.v@yandex.ru>


<strong>Вступление</strong>

Компонент предназначен для повышения гибкости работы с контентом сайта. Позволяет ускорить интеграцию вёрстки с Modx Revolution.

Основные возможности:

<ol>
    <li>Автоматическое создание элементов сайта: шаблоны, ТВ.</li>
    <li>Автоматическая расстановка в вёрстке плейсхолдеров, вызовов сниппетов, чанков.</li>
    <li>Автоматическое создание файлов чанков и секций.</li>
    <li>Автоматическое заполнение контентом админки сайта.</li>
    <li>Централизованное редактирование вёрстки.</li>
    <li>Редактирование контента прямо на фронте (компонент <strong>mpcVisualEditor</strong>).</li>
    <li>Многоязычность через файлы лексиконов с переключением языка на лету.</li>
    <li>Встроенная ленивая загрузка изображений.</li>
    <li>Удобное управление контактами из админки.</li>
    <li>Декларативное управление настройками и сущностями из консоли (CLI).</li>
</ol>


<strong>Начало работы</strong>

Чтобы работать с компонентом было комфортнее рекомендую:

<ol>
    <li>Прочитать документацию на сайте <a href="https://docs.modx.pro">https://docs.modx.pro</a> (краткая справка и список изменений — в папке <em>core/components/migxpageconfigurator/docs</em>)</li>
    <li>Ознакомиться с примерами сущностей, которыми оперирует компонент, в папке <em>core/components/migxpageconfigurator/examples</em></li>
</ol>

MPC 2.0


<strong>Служебная информация</strong>

К служебной информации можно отнести любые части шаблонов, которые есть на всех страницах и не зависят от ресурса (например фавикон, логотип, метрики и т.д.).
Служебная информация записывается в системные настройки или в настройки созданные с помощью компонента <strong>ClientConfig</strong>.
Если требуется записать настройку в конкретный контекст необходимо добавить атрибут <em>data-mpc-ctx</em> с указанием контекста или без значения, если нужно записать данные в текущий контекст.
Для обозначения служебной информации в шаблоне используется атрибут <em>data-mpc-info</em> с указанием ключа служебной информации. Из коробки доступны любые системные настройки.
Для добавления собственных ключей необходимо отредактировать конфигурацию MIGX с именем <em>mpc_service_info</em>.
Для служебной информации доступен вывод по условию. Чтобы задать условие используйте атрибут <em>data-mpc-if</em>, если не указывать условие — условием будет плейсхолдер поля.


<strong>Работа с секциями</strong>

Каждый шаблон состоит из секций. Секция может быть любым html тегом, но, как правило, это тег <em>section</em> или <em>div</em>.
Чтобы определить секцию нужно указать атрибут <em>data-mpc-section</em> с указанием ключа секции. Ключ секции должен содержать только латинские буквы, цифры и знаки подчёркивания (например <em>test</em>, <em>main</em>, <em>header</em>). Также для секции необходимо указать имя в атрибуте <em>data-mpc-name</em>. Имя необходимо для контент-менеджера, чтобы он по нему мог понять, что находится внутри. Если секция используется в нескольких шаблонах или несколько раз в одном шаблоне, то все копии следует отметить атрибутом <em>data-mpc-copy</em>, в значении рекомендую указывать название или путь к шаблону, в котором находится оригинал секции. Для копий необязательно использовать ту же разметку, что для оригинала.

Например это оригинал:

<code><section id="{$id}" data-mpc-section="third" data-mpc-name="Оригинал секции">
     <div class="container">
        <h1 data-mpc-field="title">Секция с простыми полями</h1>
        <h2 data-mpc-field="subtitle">SubTitle</h2>
        <div data-mpc-if="$content" data-mpc-field="content">
            <p>Paragraph 1</p>
            <p>Paragraph 2</p>
            <p>Paragraph 3</p>
        </div>
     </div>
</section></code>

Тогда копия может быть такой:

<code><section id="{$id}" data-mpc-section="third" data-mpc-name="Оригинал секции" data-mpc-copy="test.tpl">
    <span data-mpc-field="title">Другой заголовок</span>
    <span data-mpc-field="subtitle">Другой подзаголовок</span>
</section></code>

Каждая секция состоит из набора полей, который определяется указанием атрибутов <em>data-mpc-field</em>.

<strong>Статичные секции.</strong> Секция может быть статической, т.е. отображаться с одинаковым контентом на разных страницах сайта. Чтобы сделать секцию статической, добавьте ей флаг <em>data-mpc-static</em> (без значения). Статичную секцию можно сделать обычной, а обычную статичной для отдельных ресурсов или для всех ресурсов с конкретным шаблоном. У статичных секций плейсхолдеры отложенные (символ <code>##</code> вместо <code>{</code>), значения каскадятся от ресурса.


<strong>Работа с полями секции</strong>

Поля бывают элементарные (<em>img</em>, <em>picture</em>, <em>video</em>, <em>audio</em>, <em>title</em>, <em>subtitle</em>, <em>content</em>, <em>btn_text</em>) и списочные (<em>list_of_lists</em>, <em>list_images</em>, <em>list_triple</em>, <em>list_triple_pictures</em> и т.д.). Разница между ними в том, что списочные поля состоят из других списочных и элементарных полей.
Начиная с mpc 2.5.0 имя поля может быть любым (произвольная латиница с цифрами и подчёркиванием), а не только из набора зарезервированных — тип такого поля задаётся атрибутом <em>data-mpc-ftype</em> (см. ниже).

<blockquote><strong>DEPRECATED — медиа-списки</strong> (<em>list_images</em>, <em>list_pictures</em>, <em>list_videos</em>, <em>list_audios</em>): спец-имена для «массива однотипных медиа без ul/li». Появились, когда нужно было расставить картинки в разных местах, где <code>{foreach}</code> не подходил. Нарезаются ФИКСИРОВАННЫМИ слотами (<code>$list_images[0]</code>, <code>[1]</code>, …), а не циклом, поэтому число элементов задаётся на нарезке и не меняется динамически (в т.ч. из фронт-редактора). С появлением произвольных имён полей секции (mpc 2.5.0) надобность отпала — вместо медиа-списка заводите нужное число обычных <em>img</em>/<em>picture</em>-полей с разными именами. Новые шаблоны на медиа-списки не делать; существующие продолжают работать.</blockquote>

<strong>Условный вывод.</strong> Для всех полей доступен условный вывод — для этого укажите полю атрибут <em>data-mpc-if</em> с указанием условия без оператора <em>if</em>.
Если атрибуту <em>data-mpc-if</em> не указать значение, то в качестве условия будет взят плейсхолдер поля. Например из такого шаблона:

<code><h2 data-mpc-field="subtitle" data-mpc-if>SubTitle</h2></code>

получим вот такой результат:

<code>{if $subtitle} <h2>SubTitle</h2> {/if}</code>

<strong>Limit и offset.</strong> Для списочных полей также доступно указание limit (<em>data-mpc-lim</em>) и offset (<em>data-mpc-off</em>).
Например такой шаблон:

<code><ul data-mpc-field="list_of_lists" data-mpc-lim="1" data-mpc-off="1">
    <li data-mpc-item="">
        <h5 data-mpc-field-1="title">Title1</h5>
        <ul  data-mpc-field-1="list_triple_img">
            <li data-mpc-item-1>
                <h5 data-mpc-field-2="title">Title12</h5>
                <h6 data-mpc-field-2="subtitle">Subtitle12</h6>
                <p data-mpc-field-2="content">Content12</p>
                <img data-mpc-nolazy data-mpc-field-2="img" src="https://i.pinimg.com/736x/80/7d/2b/807d2b9987f0d35a1036b1597c3deb74.jpg" width="50" height="50" alt="Радуга">
            </li>
        </ul>
    </li>
    <li data-mpc-item="">
        <h5 data-mpc-field-1="title">Title12</h5>
        <ul data-mpc-field-1="list_triple_img">
            <li data-mpc-item-1>
                <h5 data-mpc-field-2="title">Title122</h5>
                <h6 data-mpc-field-2="subtitle">Subtitle122</h6>
                <p data-mpc-field-2="content">Content22</p>
                <img data-mpc-field-2="img" src="https://i.pinimg.com/736x/f3/d9/2d/f3d92dcd1cd6f66daa196bfd255ac41d.jpg" width="50" height="50" alt="Радуга">
            </li>
        </ul>
    </li>
</ul></code>

преобразуется в такой чанк:

<code><ul>
    {foreach $list_of_lists as $item1 index=$i1 last=$l1}
        {set $c1 = 0}
        {if $i1 >= 1 AND $c1 < 1}
            {set $c1 = sum($c1, 1)}
            <li>
                <h5>{$item1.title}</h5>
                <ul>
                    {foreach $item1.list_triple_img as $item2 index=$i2 last=$l2}
                        <li>
                            <h5>{$item2.title}</h5>
                            <h6>{$item2.subtitle}</h6>
                            <p>{$item2.content}</p>
                            <img src="{$item2.img[0].src}" width="{$item2.img[0].width}" height="{$item2.img[0].height}" alt="{$item2.img[0].alt}">
                        </li>
                    {/foreach}
                </ul>
            </li>
        {/if}
    {/foreach}
</ul></code>

А пользователь увидит только второй элемент.

<strong>Максимум записей в списке.</strong> На контейнере списка можно ограничить число записей, которые контент-менеджер сможет добавить, атрибутом <em>data-mpc-max</em> с числом. Это пишется в migx-конфиг (<em>maxRecords</em>).


<strong>Имя, тип и подпись поля</strong>

Для автоматической генерации правильного поля в админке полю/TV можно дать дополнительные атрибуты:

<ul>
    <li><em>data-mpc-ftype</em> — тип поля (имя MODX input-типа: <em>listbox</em>, <em>number</em>, <em>date</em>, <em>checkbox</em>, <em>option</em> и т.д.). Определяет также мультиопционность.</li>
    <li><em>data-mpc-fcap</em> — подпись (caption) поля в админке.</li>
    <li><em>data-mpc-fdesc</em> — описание поля в админке.</li>
    <li><em>data-mpc-values</em> — список опций для <em>listbox</em>/<em>option</em> в формате migx <code>Подпись==key||Подпись2==key2</code> или выборкой <code>@SELECT ...</code>.</li>
</ul>

Например:

<code><select data-mpc-field="size" data-mpc-ftype="listbox" data-mpc-fcap="Размер" data-mpc-values="Маленький==s||Большой==l"></select></code>


<strong>Поля ресурса и TV</strong>

Кроме полей секции компонент умеет работать напрямую с полями текущего ресурса и его TV.

<ul>
    <li><em>data-mpc-rfield</em> — вывод поля ресурса. В значении — имя поля (<em>pagetitle</em>, <em>longtitle</em>, <em>introtext</em> и т.д.). На нарезке превращается в <code>{$resource.pagetitle}</code> (или ключ лексикона при включённой многоязычности), а контент грабится прямо в нативную колонку ресурса.</li>
    <li><em>data-mpc-tv</em> — вывод TV ресурса. В значении — имя TV; если TV ещё нет, она будет создана автоматически (тип берётся из <em>data-mpc-ftype</em>, подпись/описание — из <em>data-mpc-fcap</em>/<em>data-mpc-fdesc</em>). Превращается в <code>{$resource.tvs.subtitle}</code>.</li>
    <li><em>data-mpc-res</em> — флаг на поддереве: помечает, что внутри данные ЧУЖОГО ресурса. <em>rfield</em>/<em>tv</em> внутри такого блока каттер и грабер не трогают — контент туда пишет разработчик сам.</li>
    <li><em>data-mpc-rid</em> + <em>data-mpc-table</em> — сменить источник значения поля: при <em>data-mpc-table</em> отличном от <em>config</em> поле читается из колонки ресурса с id из <em>data-mpc-rid</em>.</li>
</ul>

Например:

<code><h1 data-mpc-rfield="pagetitle">Заголовок страницы</h1>
<div data-mpc-tv="subtitle" data-mpc-ftype="textarea" data-mpc-fcap="Подзаголовок">Текст</div></code>


<strong>Вставка элементов: сниппеты и чанки</strong>

В разметке можно сразу размещать вызовы сниппетов и подключение чанков.

<ul>
    <li><em>data-mpc-snippet</em> — заменяет элемент на вызов сниппета. Значение <code>"имяСниппета|пресет"</code> (пресет опционален). Префикс <code>!</code> — некэшируемый вызов.</li>
    <li><em>data-mpc-chunk</em> — имя файла-чанка (используется вместе с <em>data-mpc-include</em>/<em>data-mpc-parse</em>); также помечает вложенный чанк для нарезки в отдельный файл.</li>
    <li><em>data-mpc-include</em> — флаг: подключить чанк из файла (<code>{include "file:..."}</code>). Имя файла берётся из соседнего <em>data-mpc-chunk</em>.</li>
    <li><em>data-mpc-parse</em> — как <em>include</em>, но через <em>parseChunk</em> с параметрами; само значение атрибута — массив параметров (Fenom-литерал), имя чанка — из <em>data-mpc-chunk</em>.</li>
    <li><em>data-mpc-attr</em> — «отложенный» атрибут: строка <code>data-mpc-attr="attr=val"</code> целиком заменяется на <code>attr=val</code>. Нужен, чтобы Fenom/спецсимволы в атрибуте дошли до рендера невырезанными.</li>
</ul>

Примеры:

<code><div data-mpc-snippet="pdoResources|news"></div>
<div data-mpc-include data-mpc-chunk="header.tpl"></div>
<div data-mpc-parse="['cls' => 'card']" data-mpc-chunk="card.tpl"></div>
<a data-mpc-attr="href={$resource.uri}">Ссылка</a></code>


<strong>Дополнительные атрибуты вывода</strong>

<ul>
    <li><em>data-mpc-unwrap</em> — флаг: вывести только содержимое (плейсхолдер/вызов), отбросив сам элемент-обёртку.</li>
    <li><em>data-mpc-symbol</em> — переопределить первый символ Fenom-тега. По умолчанию <code>{</code>, для статичных секций <code>##</code>. Пример: <code>data-mpc-symbol="##"</code>.</li>
    <li><em>data-mpc-nolazy</em> — флаг: отключить ленивую загрузку для конкретного изображения/фона.</li>
</ul>


<strong>Разметка текста в полях</strong>

По умолчанию любое значение поля при записи очищается от HTML (<em>strip_tags</em>). Какие теги разрешено сохранять — задаётся в системной настройке <em>mpc_allowed_tags</em> (через запятую). Пусто — вырезаются все теги.
Эта же настройка управляет тулбаром визуального редактора: кнопка форматирования показывается только для разрешённого тега. Чтобы появились кнопки ссылки/картинки, добавьте в настройку <em>a</em> и <em>img</em>. Дополнительные разрешённые атрибуты к безопасным дефолтам задаются в настройке <em>mpcve_allowed_attrs</em>.
Для разметки контента рекомендуется ограничиться набором: <em>strong</em>, <em>em</em>, <em>u</em>, <em>s</em>, <em>ul</em>/<em>li</em>, <em>ol</em>/<em>li</em>, <em>blockquote</em>, <em>code</em>, <em>kbd</em>, <em>a</em>.


<strong>Многоязычность (лексиконы)</strong>

Компонент умеет хранить значения полей не в самих полях, а в файлах лексиконов — это даёт перевод контента и переключение языка на лету без перенарезки.

<ul>
    <li><em>mpc_use_lexicons</em> — главный переключатель: при включённом значения пишутся ключом в БД + переводом в файл лексикона.</li>
    <li><em>mpc_default_language</em> — язык-источник (по умолчанию <em>ru</em>), из него берутся плейсхолдеры при синхронизации остальных языков.</li>
    <li><em>mpc_available_languages</em> — все языки сайта (через запятую). При нарезке набор ключей всех неосновных языков приводится к текущему: новые ключи добавляются со значением-плейсхолдером, удалённые выкидываются.</li>
    <li><em>data-mpc-lexicon</em> — на секции задаёт префикс ключей лексикона (он же мерж-ключ секции); пустое значение → имя секции. Различает оригинал и копию секции.</li>
    <li><em>data-mpc-translate</em> — только для контактов: переопределяет список переводимых под-полей контакта (CSV), перекрывая настройку <em>mpc_contact_lexicon_fields</em>.</li>
</ul>


<strong>Работа с контактами и другой публичной информацией</strong>

Контакты сохраняются в ТВ с именем <em>contacts</em> у ресурса с шаблоном Контакты. Всё это задаётся в системных настройках.
Для добавления контактов используется атрибут <em>data-mpc-contact</em>, где нужно указать тип контакта и расположение. Доступные типы:

<ol>
    <li><em>phone</em> — телефон</li>
    <li><em>mail</em> — email</li>
    <li><em>address</em> — адрес</li>
    <li><em>social</em> — соц. сеть</li>
    <li><em>map</em> — карта</li>
    <li><em>worktime</em> — время работы</li>
    <li><em>requisite</em> — реквизит</li>
    <li><em>messenger</em> — мессенджер</li>
</ol>

Контакты группируются по значению. Один контакт может иметь несколько мест размещения на странице (например в шапке и в подвале).
Расположение — это набор латинских символов, цифр и знака подчёркивания (например <em>header</em> и <em>footer</em>).
Так же для контакта можно указать ключ в атрибуте <em>data-mpc-key</em>. Ключ нужен для обращения к конкретному контакту. Ключ может содержать только латиницу, цифры и нижнее подчёркивание. Если ключ не указать, он будет сгенерирован автоматически. Ключ невозможно изменить из админки.

Данные контакта следует размещать в html элементах с атрибутами <em>data-mpc-cfield</em>. Доступны следующие поля контакта:

<ol>
    <li><em>caption</em> — подпись</li>
    <li><em>attributes</em> — любые другие данные, например иконка</li>
</ol>

Важно: из-за особенностей работы компонента не используйте знак <em>+</em>, его можно заменить на <em>%2B</em>, но только не в контактах.
Так же в контактах не допускается использовать svg, эти теги просто не будут заменены на плейсхолдеры.


<strong>Генерация миниатюр</strong>

Компонент умеет генерировать миниатюры изображений с помощью сниппета pThumb. Сниппет устанавливается отдельно.
Вы можете указать свой в системной настройке <em>mpc_thumb_snippet</em>.
В системной настройке <em>mpc_common_thumb_params</em> можно указать параметры генерации миниатюр, ширина и высота подставятся из соответствующих атрибутов.
Если оставить эту настройку пустой, миниатюры генерироваться не будут. Отключить генерацию миниатюр для отдельного изображения можно добавив ему атрибут <em>data-mpc-nothumb</em>.
Через атрибут <em>data-mpc-thumb</em> можно задать индивидуальные параметры для конкретного изображения.
<strong>ВАЖНО:</strong> изображения в списках считаются одним целым, поэтому атрибуты <em>data-mpc-nothumb</em> и <em>data-mpc-thumb</em> следует указывать первому элементу, а применены они будут ко всем.
Для фоновых изображений (поле <em>bg_img</em>), которые заданы с помощью атрибута <em>style</em>, также доступна генерация миниатюр. При этом ширину и высоту следует указывать в атрибуте <em>style</em>.
<strong>ВАЖНО:</strong> каждое свойство должно заканчиваться знаком «;», иначе значение не будет считано.
Верная запись выглядит так:

<code><div class="container" data-mpc-field="bg_img" style="background-image: url('https://i.pinimg.com/736x/2b/d7/27/2bd7274a962e509da7dd8ed5b27549f7.jpg');height: 500px;width:1920px;"></div></code>


<strong>Разворачивание SVG</strong>

Если в системной настройке указано значение атрибута и этот же атрибут указан тегу <em>img</em>, то при загрузке страницы тег <em>img</em> будет заменён на SVG из файла.
Картинки с атрибутом из системной настройки <em>mpc_expand_attr</em> игнорируются скриптом, который отвечает за ленивую загрузку.


<strong>Загрузка картинок</strong>

Если путь к картинке начинается с http и в системной настройке <em>mpc_images_path</em> указан путь к папке, то изображения будут загружены в эту папку при обработке шаблона.
К пути будет добавлено значение атрибута <em>data-mpc-section</em>, т.е. если в системной настройке указан путь <em>/assets/images/</em> и картинки будут находиться в секции <code>data-mpc-section="first"</code>, то загружены они будут в папку <em>/assets/images/first/</em>.


<strong>Как добавить поле в секцию и самостоятельно указать плейсхолдер?</strong>

Стандартные механизмы генерации плейсхолдеров достаточно универсальны, но всё же не покрывают 100% задач. Кроме того, кому-то может быть удобнее и привычнее расставлять плейсхолдеры и писать вызовы самостоятельно. В этом случае для создания полей в админке нужно внутри секции перечислить все необходимые поля, добавив им атрибут <em>data-mpc-remove</em>:

<code><section id="{$id}" data-mpc-section="first" data-mpc-name="Секция с простыми полями">
    <span data-mpc-field="title" data-mpc-remove>Title</span>
    <div class="container">
        <h1>{$title}</h1>
    </div>
</section></code>

<strong>ВАЖНО:</strong> если вы обращаетесь к глобальным массивам <code>$_GET</code>, <code>$_SESSION</code>, <code>$_COOKIE</code> или используете другие плейсхолдеры, которые будут доступны только непосредственно перед отдачей страницы пользователю, то начинать запись следует с <code>##</code> вместо <code>{</code>. Например:

<code><h1>Купить грибы в ##$.get.city}</h1>
##'msProducts' | snippet: ['parents' => 0, 'resource' => $.session.resources]} <!-- этот вызов полностью будет произведён перед отдачей на фронт -->
##'msProducts' | snippet: ['parents' => 0, 'resource' => $.session.resources, 'title' => '{$title}' ]} <!-- а в этот вызов будет передан параметр title, значение которому будет присвоено на этапе пререндера --></code>


<strong>Визуальный редактор (mpcVisualEditor)</strong>

Размеченные компонентом страницы можно редактировать прямо на фронте — отдельным компонентом <strong>mpcVisualEditor</strong>. Редактор находит поля по тем же <em>data-mpc-*</em> маркерам и сохраняет правки по каждому полю отдельно.
Чтобы маркеры остались в готовых чанках (иначе редактору не за что зацепиться), на нарезке должна быть включена системная настройка <em>mpc_edit_mode</em>. Сам редактор подключается на фронт только когда одновременно <code>mpcve_active=1</code> И <code>mpc_edit_mode=1</code>.
Для боевого деплоя <em>mpc_edit_mode</em> выключают и делают перенарезку — в файлы попадает чистый HTML без служебных атрибутов.


<strong>Управление из консоли (CLI)</strong>

Компонент умеет декларативно приводить админку к описанному в проектных манифестах состоянию — без ручного клика в админке. Тонкая обёртка — <em>console/mpc</em>, доступны группы команд: <em>resources</em>, <em>plugins</em>, <em>configs</em>, <em>settings</em>, <em>clientconfig</em>, <em>packages</em>, <em>cut</em>, <em>cache</em>, <em>lexicon</em>.
Подробности, флаги и формат манифестов — в <em>core/components/migxpageconfigurator/console/README.md</em>.


<strong>Системные события</strong>

<em>mpcOnGetSectionFieldsValues</em> — позволяет изменить получаемые из шаблона данные. Параметры:
<ul>
    <li><em>sectionKey</em> — ключ секции, значение атрибута <em>data-mpc-section</em></li>
    <li><em>fieldsValues</em> — массив значений полей секции</li>
    <li><em>section</em> — DOMElement секции</li>
</ul>

<em>mpcOnHandleContact</em> — позволяет изменить контактные данные. Параметры:
<ul>
    <li><em>contact</em> — массив контактных данных, доступен как <code>$contact[0]</code></li>
</ul>

<em>mpcOnBeforeDownloadFile</em> — позволяет изменить имя файла перед загрузкой медиа (картинки/видео/аудио/прочее). Параметры:
<ul>
    <li><em>fileName</em> — имя файла (без расширения); вернуть новое через <code>returnedValues['fileName']</code></li>
    <li><em>extension</em> — расширение файла</li>
    <li><em>type</em> — тип медиа (images/videos/audios/others)</li>
    <li><em>downloadPath</em> — путь к папке загрузки внутри источника</li>
    <li><em>Grabber</em> — экземпляр загрузчика</li>
</ul>

<em>mpcOnBeforeRender</em> — перед рендером ресурса. Параметры: <em>resourceData</em> (можно подменить через <code>returnedValues['resourceData']</code>), <em>Render</em>.

<em>mpcOnBeforeParseConfig</em> — перед разбором конфига секций. Параметры: <em>sections</em> (подмена через <code>returnedValues['sections']</code>), <em>Render</em>.

<em>mpcOnGetSectionHtml</em> — после сборки HTML секции при рендере. Параметры: <em>section</em>, <em>html</em> (подмена через <code>returnedValues['html']</code>), <em>Render</em>.

<em>mpcOnGetNewHtml</em> — при формировании нового HTML поля на нарезке. Параметры: <em>fieldHTMLNew</em> (подмена через <code>returnedValues['fieldHTMLNew']</code>), <em>PlaceholderProcessor</em>.

<em>mpcOnFieldSave</em> — после сохранения значения поля (в т.ч. из визуального редактора). Параметры: <em>resourceId</em>, <em>address</em> (уровень/адрес поля).

<em>mpcOnGetLexiconKey</em> — при вычислении ключа лексикона. Параметры: <em>sectionLexiconPrefix</em>, <em>lexiconKey</em> (подмена через <code>returnedValues['lexiconKey']</code>), <em>fieldName</em>, <em>Grabber</em>.

<em>mpcOnImportLexiconValue</em> — при импорте значения лексикона. Параметры: <em>value</em> (подмена через <code>returnedValues['value']</code>).

<em>mpcOnGetResourceIdentifier</em> — при вычислении идентификатора ресурса для ключей лексикона. Параметры: <em>rid</em> (подмена через <code>returnedValues['rid']</code>), <em>Grabber</em>.

<em>mpcOnAddCellToExcel</em>, <em>mpcOnBeforeSaveExcel</em> — хуки экспорта лексиконов в XLSX.
