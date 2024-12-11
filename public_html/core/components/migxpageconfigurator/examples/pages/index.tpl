{set $path = '!getParsedConfigPath' | snippet:[]}
{if $path}
    {include $path}
{else}
    {$_modx->resource.content}
{/if}
