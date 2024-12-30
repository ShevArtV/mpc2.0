<?php
$path = MODX_CORE_PATH . $input;
if(file_exists($path)){
    $content = file_get_contents($path);
    return str_replace('##', '{', $content);
}