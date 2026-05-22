mpcVisualEditor (mpcVE)
=======================

Визуальный фронт-редактор контента для сайтов на migxpageconfigurator (mpc).
Позволяет администраторам править содержимое страниц прямо с фронта (inline),
без захода в админку. Сохранение — в те же хранилища, что использует mpc:
TV mpc_config, lexicon-файлы, нативные поля ресурса, произвольные TV.

Требования:
- MODX Revolution 2.8.x
- PHP 7.4+
- migxpageconfigurator 2.4.0+ (жёсткая зависимость: address-space, edit-mode рендер, фасад)
- pdoTools

Включение: дать роли permission `mpcve_edit`, открыть страницу с edit-параметром.

Автор: Arthur Shevchenko (https://t.me/ShevArtV)
