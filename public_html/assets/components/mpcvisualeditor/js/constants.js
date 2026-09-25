/**
 * mpcVisualEditor — константы (без зависимостей).
 */

// Префиксы маркеров цепочки полей. data-mpc-* читает и нарезка, и редактор;
// data-mpcve-* — ТОЛЬКО редактор: нарезка их не видит, поэтому ими размечают
// кастомный вывод (чанк сниппета), который нельзя дублировать цепочкой data-mpc-*
// (нарезка сочла бы дубль полем и затёрла контент). Схема та же:
// field > item > field-1 > item-1 > field-2 …, плюс ftype и max; цепочка может
// быть смешанной. Секция/static/name/res — только data-mpc-*.
export var MARKER_PREFIXES = ['data-mpc-', 'data-mpcve-'];

// Имя атрибута-маркера `name` (field, item-1, ftype…), которое есть на el, или null.
export function mpcAttrName(el, name) {
    if (!el || !el.hasAttribute) { return null; }
    for (var i = 0; i < MARKER_PREFIXES.length; i++) {
        if (el.hasAttribute(MARKER_PREFIXES[i] + name)) { return MARKER_PREFIXES[i] + name; }
    }
    return null;
}

export function hasMpc(el, name) {
    return mpcAttrName(el, name) !== null;
}

// Значение маркера (data-mpc-* приоритетнее data-mpcve-*), null если нет.
export function mpcAttr(el, name) {
    var a = mpcAttrName(el, name);
    return a ? el.getAttribute(a) : null;
}

// CSS-селектор маркера в обоих префиксах: [data-mpc-X],[data-mpcve-X].
export function mpcSel(name) {
    return MARKER_PREFIXES.map(function (p) { return '[' + p + name + ']'; }).join(',');
}

// Ближайший предок (вкл. el) с маркером `name` в любом префиксе.
export function closestMpc(el, name) {
    return el && el.closest ? el.closest(mpcSel(name)) : null;
}

// Уровень поля цепочки по имени атрибута: 0 — field, N — field-N, -1 — не поле.
export function fieldLevelOf(attrName) {
    var m = /^data-mpc(?:ve)?-field(?:-(\d+))?$/.exec(attrName || '');
    return m ? (m[1] ? parseInt(m[1], 10) : 0) : -1;
}

// Атрибуты-маркеры редактируемых полей в DOM (edit-mode сохраняет их в рендере).
export var FIELD_ATTRS = ['data-mpc-field', 'data-mpc-rfield', 'data-mpc-tv',
    'data-mpc-field-1', 'data-mpc-field-2', 'data-mpc-field-3',
    'data-mpcve-field', 'data-mpcve-field-1', 'data-mpcve-field-2', 'data-mpcve-field-3',
    // Произвольный лексиконный ключ (data-mpc-lexicon="topic:key") — правится
    // инлайн как текст/HTML, привязки к секции/ресурсу нет.
    'data-mpc-lexicon'];
export var SELECTOR = FIELD_ATTRS.map(function (a) { return '[' + a + ']'; }).join(',');

// Служебная информация (data-mpc-info) — ГЛОБАЛЬНЫЕ настройки (системные/
// контекстные/ClientConfig). Помечается редактируемой отдельно и только при
// праве mpcve_edit_global (S.cfg.editGlobal).
export var INFO_SELECTOR = '[data-mpc-info]';

// Подсказки (title) по типу редактора.
export var TYPE_HINT = {
    text: 'Текст — клик, чтобы редактировать',
    textarea: 'Текст (многострочный) — клик, откроется окно',
    richtext: 'Текст с форматированием — клик, откроется редактор',
    image: 'Изображение — клик, чтобы заменить',
    picture: 'Адаптивная картинка — клик: главное фото + источники',
    media: 'Видео/аудио — клик: файл, постер, источники, атрибуты',
    rows: 'Список — клик, чтобы добавить/удалить/переставить строки',
    link: 'Ссылка — клик, чтобы изменить адрес (текст правится внутри)',
    listbox: 'Выбор из списка — клик',
    'listbox-multiple': 'Выбор нескольких значений — клик',
    option: 'Выбор одного варианта — клик',
    checkbox: 'Выбор нескольких вариантов — клик',
    number: 'Число — клик, чтобы изменить',
    date: 'Дата — клик, чтобы изменить',
    tags: 'Теги — клик, чтобы редактировать',
    file: 'Файл — клик, чтобы выбрать/загрузить',
    color: 'Цвет — клик, чтобы выбрать'
};

// Редактируемые поля СЕКЦИИ = вкладка «Стили секции», кроме css_file_path
// (путь к файлу — не правим из фронта). props = «Дополнительные свойства».
export var SECTION_STYLE_FIELDS = ['inline_styles', 'class_names', 'props'];

// Структурные ключи (для скрытых под-полей СТРОК списков) — не редактируем.
// Сюда же стилевые (показываем отдельной веткой) + css_file_path (вообще не правим).
export var STRUCTURAL = ['section_name', 'MIGX_formname', 'MIGX_id', 'id', 'position',
    'is_static', 'file_name', 'limit', 'lexicon_prefix', 'css_file_path',
    'inline_styles', 'class_names', 'props'];

// Понятные подписи известных полей (приоритетнее captions из конфигуратора).
export var FIELD_LABELS = {
    inline_styles: 'Inline-стили',
    class_names: 'CSS-классы',
    resources: 'Ресурсы (resources)'
};
