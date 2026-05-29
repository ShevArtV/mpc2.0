<?php

/**
 * Fixture-список исключений для lexicon-снапшотов.
 *
 * `promo_content` — exclude в ПРЕФИКСНОЙ форме ({section}_field). Грабер чтит
 * её через полный lex-ключ, каттер обязан тоже (после setSectionContext).
 * Это воспроизводит баг 2.4.6-rc (асимметрия Cutter↔Grabber): без фикса каттер
 * ставил `| lexicon` на excluded-по-префиксу поле → пусто на рендере.
 */

$excludeLexiconFields = [
    'promo_content',
];
