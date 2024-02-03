{extends 'file:sections/wrapper.tpl'}
{block 'content'}
    {set $path = '!getParsedConfigPath' | snippet:[]}
    {if $path}
        {include $path}
    {else}
        {$_modx->resource.content}
    {/if}
{/block}