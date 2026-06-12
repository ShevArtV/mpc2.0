<section>

    <h2>{$title}</h2>

    <div>
        <i class="{$contacts['header']['phone_header']['attributes']}"></i>
        <a href="tel:{$contacts['header']['phone_header']['value']}">{$contacts['header']['phone_header']['fvalue']}</a>
        <span>{$contacts['header']['phone_header']['caption']}</span>
    </div>

    {if $address}
    <div>{$address}</div>
{/if}


</section>