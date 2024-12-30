<!--##{"templatename":"Шаблон-пример","wrapper":"wrapper","icon":"icon-gear","include":"file:pages/index.tpl"}##-->

<!-- php -d display_errors -d error_reporting=E_ALL /home/host1860015/art-sites.ru/htdocs/customfactory/core/components/migxpageconfigurator/console/mgr_tpl.php web examples.tpl -->

<section id="{$id}" data-mpc-section="first" data-mpc-name="Секция с простыми полями" data-mpc-static>
    <div class="container" data-mpc-field="bg_img"
         style="background-image: url('https://i.pinimg.com/736x/2b/d7/27/2bd7274a962e509da7dd8ed5b27549f7.jpg')">
        <h1 data-mpc-field="title">Секция с простыми полями</h1>
        <h2 data-mpc-field="subtitle">SubTitle</h2>
        <div data-mpc-if="$content" data-mpc-field="content" data-mpc-unwrap>
            <p>Paragraph 1</p>
            <p>Paragraph 2</p>
            <p>Paragraph 3</p>
        </div>
        <a href="#target" data-mpc-field="target">
            <span data-mpc-field="btn_text">Some text</span>
        </a>


        <video data-mpc-field="video" loop="" muted="" autoplay="" poster="https://avatars.mds.yandex.net/i?id=3f163cfdb0ec47365f7e65df553506de_l-5312300-images-thumbs&n=13">
            <source src="https://vkvideo.ru/video-127553155_456242995" type="video/mp4">
            <source src="https://vkvideo.ru/video-225069804_456239130" type="video/mp4">
        </video>

        <audio data-mpc-field="audio" loop="" muted="" autoplay="" src="https://vk.com/audio/audio-2001646286_131646286"></audio>
    </div>
    <div class="container"
         style="background-image: url('https://i.pinimg.com/736x/2b/d7/27/2bd7274a962e509da7dd8ed5b27549f7.jpg')">
        <img data-mpc-field="img" src="https://i.pinimg.com/736x/2b/d7/27/2bd7274a962e509da7dd8ed5b27549f7.jpg" data-mpc-thumb="q=90&f=webp" width="500" height="400" alt="Это озеро">
        <picture class="my-class" data-mpc-field="picture" data-mpc-if="$picture">
            <img src="https://www.castorama.ru/upload/iblock/aca/m0ipouunfumwx30tswh2ec8jeq1g9o1j/1001422054_1.jpg" width="100" height="40" alt="Пикча">
            <source srcset="https://i.pinimg.com/originals/25/2d/a5/252da55f9043961132aba261e903b6d9.jpg" data-mpc-nothumb width="100" height="40" media="(min-width: 1199px)">
        </picture>
    </div>
</section>
<section id="{$id}" data-mpc-section="second" data-mpc-name="Секция с вызовом сниппета">
    <div class="container">
        <span data-mpc-field="resources" data-mpc-remove="1">1,2,3</span>
        <span data-mpc-field="limit" data-mpc-remove="1">6</span>
        <h1 data-mpc-field="title">Секция с вызовами сниппетов и чанками</h1>

        <h2 data-mpc-field="content">Этот сниппет будет вызван уже на бэке</h2>
        <div data-mpc-snippet="!pdoResources|extended" data-mpc-symbol="{ ">
        </div>

        <h2 data-mpc-field="subtitle">Этот сниппет будет вызван при отдаче страницы на фронт</h2>
        <div data-mpc-snippet="!pdoResources|default">
            <a href="{$uri}" data-mpc-chunk="pdoresources/default/item.tpl">{$pagetitle}</a>
        </div>

        <h2>Результат вызова этого сниппета сохранён в плейсхолдер</h2>
        <div data-mpc-snippet="!pdoResources|placeholder"></div>
        ##$test}

        <div data-mpc-chunk="common/chunk_1.tpl" data-mpc-include>
            <p>Included chunk</p>
        </div>

        <div data-mpc-chunk="common/chunk_3.tpl" data-mpc-include data-mpc-symbol="##">
            <p>Included chunk no prerender {$.get.id}</p>
        </div>

        <div data-mpc-chunk="common/chunk_2.tpl" data-mpc-parse="['resources' => '{$resources}']">
            <p>Parsed chunk {$resources}</p>
        </div>

        <div data-mpc-chunk="common/chunk_4.tpl" data-mpc-parse="['resources' => '{$resources}']" data-mpc-symbol="##">
            <p>Parsed chunk no prerender {$resources} - {$.get.id}</p>
        </div>
    </div>
</section>
<section id="{$id}" data-mpc-section="third" data-mpc-name="Секция со списками">
    <div class="container">
        <h1 data-mpc-field="title">Секция со списками</h1>

        <img data-mpc-field="list_images" src="https://i.pinimg.com/736x/2b/d7/27/2bd7274a962e509da7dd8ed5b27549f7.jpg" width="500" height="400" alt="Это озеро">
        <img data-mpc-field="list_images" src="https://i.pinimg.com/originals/25/2d/a5/252da55f9043961132aba261e903b6d9.jpg" width="500" height="400" alt="Это озеро">

        <picture data-mpc-field="list_pictures">
            <img src="https://i.pinimg.com/originals/98/ff/53/98ff536fe77a80edff7e3c575a98fc8b.jpg" width="100" height="40" alt="Пикча">
            <source srcset="https://main-cdn.sbermegamarket.ru/big1/hlr-system/115/358/634/898/011/600004546568b0.jpeg" width="100" height="40" media="(min-width: 1199px)">
        </picture>
        <picture data-mpc-field="list_pictures">
            <img src="https://img.joomcdn.net/ef8de7dff03f0a23264cf9305e71cc5ebf78edd6_original.jpeg" width="100" height="40" alt="Пикча">
            <source srcset="https://i.pinimg.com/736x/52/56/0e/52560ecbff8efe34cdf7be5436dbc90d.jpg" width="100" height="40" media="(min-width: 1199px)">
        </picture>


        <ul data-mpc-field="list_of_lists">
            <li data-mpc-item="">
                <h5 data-mpc-field-1="title">Title1</h5>
                <ul data-mpc-field-1="list_triple_img">
                    <li data-mpc-item-1>
                        <h5 data-mpc-field-2="title">Title12</h5>
                        <h6 data-mpc-field-2="subtitle">Subtitle12</h6>
                        <p data-mpc-field-2="content">Content12</p>
                        <img data-mpc-field-2="img" src="https://i.pinimg.com/736x/80/7d/2b/807d2b9987f0d35a1036b1597c3deb74.jpg" width="50" height="50" alt="Радуга">
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
        </ul>


        <ul data-mpc-field="list_triple" data-mpc-lim="2" data-mpc-off="1">
            <li data-mpc-item>
                <h5 data-mpc-field-1="title">Title1</h5>
                <h6 data-mpc-field-1="subtitle">Subtitle1</h6>
                <p data-mpc-field-1="content">Content1</p>
            </li>
            <li data-mpc-item>
                <h5 data-mpc-field-1="title">Title2</h5>
                <h6 data-mpc-field-1="subtitle">Subtitle2</h6>
                <p data-mpc-field-1="content">Content2</p>
            </li>
            <li data-mpc-item>
                <h5 data-mpc-field-1="title">Title3</h5>
                <h6 data-mpc-field-1="subtitle">Subtitle3</h6>
                <p data-mpc-field-1="content">Content3</p>
            </li>
        </ul>

        <ul data-mpc-field="list_triple_img">
            <li data-mpc-item>
                <h5 data-mpc-field-1="title">Title12</h5>
                <h6 data-mpc-field-1="subtitle">Subtitle12</h6>
                <p data-mpc-field-1="content">Content12</p>
                <img data-mpc-field-1="img" src="https://i.pinimg.com/736x/80/7d/2b/807d2b9987f0d35a1036b1597c3deb74.jpg" data-mpc-nothumb width="50" height="50" alt="Радуга">
            </li>
            <li data-mpc-item>
                <h5 data-mpc-field-1="title">Title22</h5>
                <h6 data-mpc-field-1="subtitle">Subtitle22</h6>
                <p data-mpc-field-1="content">Content22</p>
                <img data-mpc-field-1="img" src="https://i.pinimg.com/736x/f3/d9/2d/f3d92dcd1cd6f66daa196bfd255ac41d.jpg" width="50" height="50" alt="Озеро">
            </li>
        </ul>

        <ul data-mpc-field="list_triple_picture">
            <li data-mpc-item>
                <h5 data-mpc-field-1="title">Title12</h5>
                <h6 data-mpc-field-1="subtitle">Subtitle12</h6>
                <p data-mpc-field-1="content">Content12</p>
                <picture data-mpc-field-1="picture">
                    <img src="https://img.joomcdn.net/ef8de7dff03f0a23264cf9305e71cc5ebf78edd6_original.jpeg" width="100" height="40" alt="Пикча">
                    <source srcset="https://i.pinimg.com/736x/52/56/0e/52560ecbff8efe34cdf7be5436dbc90d.jpg" width="100" height="40" media="(min-width: 1199px)">
                </picture>
            </li>
            <li data-mpc-item>
                <h5 data-mpc-field-1="title">Title22</h5>
                <h6 data-mpc-field-1="subtitle">Subtitle22</h6>
                <p data-mpc-field-1="content">Content22</p>
                <picture data-mpc-field-1="picture">
                    <img src="https://i.pinimg.com/originals/98/ff/53/98ff536fe77a80edff7e3c575a98fc8b.jpg" width="100" height="40" alt="Пикча">
                    <source srcset="https://main-cdn.sbermegamarket.ru/big1/hlr-system/115/358/634/898/011/600004546568b0.jpeg" width="100" height="40" media="(min-width: 1199px)">
                </picture>
            </li>
        </ul>

        <ul data-mpc-field="list_triple_images">
            <li data-mpc-item>
                <h5 data-mpc-field-1="title">Title12</h5>
                <h6 data-mpc-field-1="subtitle">Subtitle12</h6>
                <p data-mpc-field-1="content">Content12</p>
                <img data-mpc-field-1="list_images" src="https://img.joomcdn.net/ef8de7dff03f0a23264cf9305e71cc5ebf78edd6_original.jpeg" width="53" height="53" alt="Озеро">
                <img data-mpc-field-1="list_images" src="https://i.pinimg.com/736x/52/56/0e/52560ecbff8efe34cdf7be5436dbc90d.jpg" width="52" height="52" alt="Радуга">
            </li>
            <li data-mpc-item>
                <h5 data-mpc-field-1="title">Title22</h5>
                <h6 data-mpc-field-1="subtitle">Subtitle22</h6>
                <p data-mpc-field-1="content">Content22</p>
                <img data-mpc-field-1="list_images" src="https://i.pinimg.com/originals/98/ff/53/98ff536fe77a80edff7e3c575a98fc8b.jpg" width="50" height="50" alt="Озеро">
                <img data-mpc-field-1="list_images" src="https://main-cdn.sbermegamarket.ru/big1/hlr-system/115/358/634/898/011/600004546568b0.jpeg" width="51" height="51" alt="Радуга">
            </li>
        </ul>

        <ul data-mpc-field="list_triple_pictures">
            <li data-mpc-item>
                <h5 data-mpc-field-1="title">Title12</h5>
                <h6 data-mpc-field-1="subtitle">Subtitle12</h6>
                <p data-mpc-field-1="content">Content12</p>
                <picture data-mpc-field-1="list_pictures">
                    <img src="https://img.joomcdn.net/ef8de7dff03f0a23264cf9305e71cc5ebf78edd6_original.jpeg" width="100" height="40" alt="Пикча1">
                    <source srcset="https://i.pinimg.com/736x/52/56/0e/52560ecbff8efe34cdf7be5436dbc90d.jpg" width="101" height="41" media="(min-width: 1199px)">
                </picture>
                <picture data-mpc-field-1="list_pictures">
                    <img src="https://i.pinimg.com/originals/98/ff/53/98ff536fe77a80edff7e3c575a98fc8b.jpg" width="102" height="42" alt="Пикча2">
                    <source srcset="https://main-cdn.sbermegamarket.ru/big1/hlr-system/115/358/634/898/011/600004546568b0.jpeg" width="103" height="43" media="(min-width: 1199px)">
                </picture>
            </li>
            <li data-mpc-item>
                <h5 data-mpc-field-1="title">Title22</h5>
                <h6 data-mpc-field-1="subtitle">Subtitle22</h6>
                <p data-mpc-field-1="content">Content22</p>
                <picture data-mpc-field-1="list_pictures">
                    <img src="https://avatars.mds.yandex.net/get-mpic/1726038/img_id4088522110359291241.jpeg/orig" width="44" height="104" alt="Пикча3">
                    <source srcset="https://avatars.mds.yandex.net/get-mpic/1726038/img_id4088522110359291241.jpeg/orig" width="47" height="107" media="(min-width: 1199px)">
                </picture>
                <picture data-mpc-field-1="list_pictures">
                    <img src="https://i.pinimg.com/originals/a4/f0/f4/a4f0f4f3470cad8ef1ddb4393feca0b0.jpg" width="105" height="45" alt="Пикча4">
                    <source srcset="https://i.pinimg.com/originals/a4/f0/f4/a4f0f4f3470cad8ef1ddb4393feca0b0.jpg" width="106" height="46" media="(min-width: 1199px)">
                </picture>
            </li>
        </ul>

        <video data-mpc-field="list_videos" loop="" muted="" autoplay="" poster="https://avatars.mds.yandex.net/i?id=3f163cfdb0ec47365f7e65df553506de_l-5312300-images-thumbs&n=13">
            <source src="https://vkvideo.ru/video-127553155_456242995" type="video/mp4">
        </video>
        <video data-mpc-field="list_videos" src="https://vkvideo.ru/video-225069804_456239130" loop="" muted="" autoplay="" poster="https://avatars.mds.yandex.net/i?id=3f163cfdb0ec47365f7e65df553506de_l-5312300-images-thumbs&n=13">

        </video>

        <audio data-mpc-field="list_audios" loop="" muted="" autoplay="" src="https://vk.com/audio/audio-2001646286_131646286"></audio>
        <audio data-mpc-field="list_audios" loop="" muted="" autoplay="" src="https://vk.com/audio/audio-2001646286_131646287"></audio>
    </div>
</section>
