<section>

    <h2>{$title}</h2>

    <div>
        <a href="tel:{$contacts['header']['phone_header']['value']}">{$contacts['header']['phone_header']['fvalue']}</a>
        {$contacts['header']['phone_header']['caption']}
    </div>

    {if $address}
    <div>{$address}</div>
{/if}


</section>