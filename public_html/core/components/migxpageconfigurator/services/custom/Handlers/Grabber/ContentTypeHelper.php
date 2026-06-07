<?php

namespace MpcServices\Handlers\Grabber;

/**
 * Чистые (PURE/STATIC) утилиты классификации content-type поля: по HTML-тегу
 * маркера (data-mpc-field) и по типу TV (modTemplateVar.type). Вынесено из
 * LexiconManager (God-класс) — единый источник для решения о лексиконизации
 * у грабера, каттера и редактора.
 *
 * @author Arthur Shevchenko (https://t.me/ShevArtV)
 */
class ContentTypeHelper
{
    /** Content-type по HTML-тегу элемента (img/source/picture→image, video, audio, иначе text). */
    public static function contentTypeForTag(string $tag): string
    {
        $tag = strtolower($tag);
        if (in_array($tag, ['img', 'source', 'picture'], true)) {
            return 'image';
        }
        if ($tag === 'video') {
            return 'video';
        }
        if ($tag === 'audio') {
            return 'audio';
        }
        return 'text';
    }

    /**
     * Content-type по ТИПУ TV (modTemplateVar.type): image→image; текстовые
     * (text/textarea/richtext/tinymce/tinymcerte)→text; всё прочее (number/date/
     * email/url/file/listbox/option/checkbox/tag/…)→'raw' (не лексиконизируется).
     */
    public static function contentTypeForTvType(string $tvType): string
    {
        $t = strtolower(trim($tvType));
        if (strpos($t, 'image') !== false) {
            return 'image';
        }
        if (in_array($t, ['text', 'textarea', 'richtext', 'tinymce', 'tinymcerte'], true)) {
            return 'text';
        }
        return 'raw';
    }
}
