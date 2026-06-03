/**
 * mpcVisualEditor — редактор текста С ФОРМАТИРОВАНИЕМ (richtext) в МОДАЛКЕ с RTE.
 * Тулбар (B/I/U/S, заголовки, списки, ссылка, очистка) поверх contenteditable —
 * на нативном document.execCommand (без сборки/зависимостей). Значение — HTML
 * (innerHTML), пишется через field/save (лексикон-round-trip — как у инлайна).
 */
import { api } from '../api.js';
import { toast } from '../dom.js';
import { fieldAddress } from '../address.js';

// Кнопки тулбара: cmd — execCommand; block — formatBlock; link — вставка ссылки.
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

export function openRichtextEditor(el) {
    if (document.querySelector('.mpcve-modal')) { return; }
    var addr = fieldAddress(el);
    if (!addr) { toast('Нет адреса поля', true); return; }
    var orig = el.innerHTML;

    var toolbar = BTNS.map(function (b) {
        if (b.sep) { return '<span class="mpcve-rte__sep"></span>'; }
        var s = b.style ? (' style="' + b.style + '"') : '';
        return '<button type="button" class="mpcve-rte__btn"' +
            ' data-cmd="' + (b.cmd || '') + '" data-block="' + (b.block || '') + '"' +
            ' data-link="' + (b.link ? '1' : '') + '" title="' + b.title + '"' + s + '>' + b.label + '</button>';
    }).join('');

    var overlay = document.createElement('div');
    overlay.className = 'mpcve-modal';
    overlay.innerHTML =
        '<div class="mpcve-modal__card mpcve-modal__card--wide">' +
            '<div class="mpcve-modal__head">Текст с форматированием</div>' +
            '<div class="mpcve-rte__toolbar">' + toolbar + '</div>' +
            '<div class="mpcve-rte__area" contenteditable="true" spellcheck="false"></div>' +
            '<div class="mpcve-modal__actions">' +
                '<button type="button" class="mpcve-btn" data-act="cancel">Отмена</button>' +
                '<button type="button" class="mpcve-btn mpcve-btn--primary" data-act="save">Сохранить</button>' +
            '</div>' +
        '</div>';
    document.body.appendChild(overlay);

    var area = overlay.querySelector('.mpcve-rte__area');
    area.innerHTML = orig;
    area.focus();

    function close() { overlay.remove(); document.removeEventListener('keydown', onKey); }
    function onKey(e) { if (e.key === 'Escape') { close(); } }
    document.addEventListener('keydown', onKey);
    overlay.addEventListener('click', function (e) { if (e.target === overlay) { close(); } });
    overlay.querySelector('[data-act=cancel]').addEventListener('click', close);

    var tb = overlay.querySelector('.mpcve-rte__toolbar');
    // mousedown гасим, чтобы клик по кнопке НЕ снимал выделение в области.
    tb.addEventListener('mousedown', function (e) { if (e.target.closest('.mpcve-rte__btn')) { e.preventDefault(); } });
    tb.addEventListener('click', function (e) {
        var btn = e.target.closest('.mpcve-rte__btn');
        if (!btn) { return; }
        e.preventDefault();
        area.focus();
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

    var saveBtn = overlay.querySelector('[data-act=save]');
    saveBtn.addEventListener('click', function () {
        var value = area.innerHTML;
        saveBtn.disabled = true; saveBtn.textContent = 'Сохранение…';
        api.post('field/save', { address: addr, value: value }).then(function (r) {
            if (r && r.success) {
                el.innerHTML = value; // обновляем страницу без перезагрузки
                toast('Сохранено');
                close();
            } else {
                toast((r && r.message) || 'Ошибка сохранения', true);
                saveBtn.disabled = false; saveBtn.textContent = 'Сохранить';
            }
        }).catch(function () {
            toast('Сетевая ошибка', true);
            saveBtn.disabled = false; saveBtn.textContent = 'Сохранить';
        });
    });
}
