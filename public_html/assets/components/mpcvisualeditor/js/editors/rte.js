/**
 * mpcVisualEditor — общий RTE-тулбар (document.execCommand) для contenteditable.
 * Используют И модальный richtext-редактор (editors/richtext.js), И панель
 * скрытых полей (panels.js) — чтобы richtext правился ОДИНАКОВО (с форматированием)
 * везде. Без сборки/зависимостей.
 */

// cmd — execCommand; block — formatBlock (заголовок/абзац); link — вставка ссылки.
var BTNS = [
    { cmd: 'bold', label: 'B', title: 'Жирный', style: 'font-weight:700' },
    { cmd: 'italic', label: 'I', title: 'Курсив', style: 'font-style:italic' },
    { cmd: 'underline', label: 'U', title: 'Подчёркнутый', style: 'text-decoration:underline' },
    { cmd: 'strikeThrough', label: 'S', title: 'Зачёркнутый', style: 'text-decoration:line-through' },
    { sep: true },
    { block: 'h2', label: 'H2', title: 'Заголовок 2' },
    { block: 'h3', label: 'H3', title: 'Заголовок 3' },
    { block: 'p', label: '¶', title: 'Абзац' },
    { sep: true },
    { cmd: 'insertUnorderedList', label: '•', title: 'Маркированный список' },
    { cmd: 'insertOrderedList', label: '1.', title: 'Нумерованный список' },
    { sep: true },
    { link: true, label: '🔗', title: 'Ссылка' },
    { cmd: 'unlink', label: '⛓', title: 'Убрать ссылку' },
    { cmd: 'removeFormat', label: '⌫', title: 'Очистить формат' }
];

export function rteToolbarHtml() {
    var inner = BTNS.map(function (b) {
        if (b.sep) { return '<span class="mpcve-rte__sep"></span>'; }
        var s = b.style ? (' style="' + b.style + '"') : '';
        return '<button type="button" class="mpcve-rte__btn"' +
            ' data-cmd="' + (b.cmd || '') + '" data-block="' + (b.block || '') + '"' +
            ' data-link="' + (b.link ? '1' : '') + '" title="' + b.title + '"' + s + '>' + b.label + '</button>';
    }).join('');
    return '<div class="mpcve-rte__toolbar">' + inner + '</div>';
}

// Навешивает команды тулбара на область. mousedown гасим, чтобы клик по кнопке
// НЕ снимал выделение в области; команда применяется к выделению этой области.
export function wireRteToolbar(toolbarEl, areaEl) {
    toolbarEl.addEventListener('mousedown', function (e) {
        if (e.target.closest('.mpcve-rte__btn')) { e.preventDefault(); }
    });
    toolbarEl.addEventListener('click', function (e) {
        var btn = e.target.closest('.mpcve-rte__btn');
        if (!btn) { return; }
        e.preventDefault();
        areaEl.focus();
        if (btn.getAttribute('data-link')) {
            var sel = window.getSelection();
            var range = (sel && sel.rangeCount) ? sel.getRangeAt(0) : null;
            var url = window.prompt('URL ссылки:', 'https://');
            if (range) { sel.removeAllRanges(); sel.addRange(range); } // вернуть выделение после prompt
            if (url) { document.execCommand('createLink', false, url); }
            return;
        }
        var block = btn.getAttribute('data-block');
        if (block) { document.execCommand('formatBlock', false, block); return; }
        var cmd = btn.getAttribute('data-cmd');
        if (cmd) { document.execCommand(cmd, false, null); }
    });
}
