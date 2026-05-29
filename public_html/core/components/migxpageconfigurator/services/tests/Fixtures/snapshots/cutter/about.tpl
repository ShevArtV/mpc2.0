<section>

    <h2>{$title}</h2>

    <p>{$text}</p>

    <img src="{$image[0].src}" width="{$image[0].width}" height="{$image[0].height}" alt="{$image[0].alt}">

    <div class="hero" style="background-image: url('{$bg_img}'); width: 1920px; height: 600px;"></div>

    <div>{foreach $items as $item1 index=$i1 last=$l1}
    <article>
            <h3>{$item1.item_title}</h3>
            <p>{$item1.item_text}</p>
        </article>
{/foreach}
</div>

</section>