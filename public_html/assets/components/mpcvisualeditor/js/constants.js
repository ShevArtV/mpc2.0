/**
 * mpcVisualEditor — константы (без зависимостей).
 */

// Атрибуты-маркеры редактируемых полей в DOM (edit-mode сохраняет их в рендере).
export var FIELD_ATTRS = ['data-mpc-field', 'data-mpc-rfield', 'data-mpc-tv',
    'data-mpc-field-1', 'data-mpc-field-2', 'data-mpc-field-3'];
export var SELECTOR = FIELD_ATTRS.map(function (a) { return '[' + a + ']'; }).join(',');

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
    'listbox-multiple': 'Выбор нескольких значений — клик'
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
