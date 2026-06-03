/**
 * mpcVisualEditor — RTE: ДЕФОЛТНЫЙ провайдер (нативный execCommand) + РЕЕСТР
 * (pluggable). Используют модальный richtext-редактор и панель скрытых полей.
 *
 * 1) Тулбар строится из allowedTags (системная настройка mpc_allowed_tags — та же,
 *    что фильтрует html перед сохранением через strip_tags). Кнопка показывается,
 *    только если её тег разрешён — нет смысла предлагать тег, который вырежется.
 * 2) Картинки — через наш загрузчик (api image/upload), кнопка только если 'img'
 *    разрешён.
 * 3) MutationObserver на области диспатчит события (всплывают):
 *    mpcve:rte:nodeadded / noderemoved / nodechanged (detail.node[, attribute]) —
 *    проект слушает их и навешивает классы/обработчики на вставленные теги.
 * 4) Подменить редактор: window.MpcVE.setRte(provider) — объект с методом
 *    create(container, opts) → инстанс { getHTML, focus, destroy }.
 *
 * opts: { value, allowedTags:[], upload(file)->Promise<url> }.
 */
import { toast } from '../dom.js';

// Тег → кнопка. exec — execCommand; block — formatBlock; wrap — обернуть выделение
// в тег (для small/mark/span, которых нет в execCommand); link/image — особые.
var BUTTONS = [
    { tag: 'b', label: 'B', title: 'Жирный', style: 'font-weight:700', exec: 'bold' },
    { tag: 'i', label: 'I', title: 'Курсив', style: 'font-style:italic', exec: 'italic' },
    { tag: 'u', label: 'U', title: 'Подчёркнутый', style: 'text-decoration:underline', exec: 'underline' },
    { tag: 's', label: 'S', title: 'Зачёркнутый', style: 'text-decoration:line-through', exec: 'strikeThrough' },
    { tag: 'mark', label: 'mark', title: 'Выделение', wrap: 'mark' },
    { tag: 'small', label: 'small', title: 'Мелкий', wrap: 'small' },
    { tag: 'span', label: 'span', title: 'Span (для классов/обработчиков)', wrap: 'span' },
    { sep: true, after: ['b', 'i', 'u', 's', 'mark', 'small', 'span'] },
    { tag: 'p', label: '¶', title: 'Абзац', block: 'p' },
    { tag: 'h1', label: 'H1', title: 'Заголовок 1', block: 'h1' },
    { tag: 'h2', label: 'H2', title: 'Заголовок 2', block: 'h2' },
    { tag: 'h3', label: 'H3', title: 'Заголовок 3', block: 'h3' },
    { tag: 'h4', label: 'H4', title: 'Заголовок 4', block: 'h4' },
    { tag: 'h5', label: 'H5', title: 'Заголовок 5', block: 'h5' },
    { tag: 'h6', label: 'H6', title: 'Заголовок 6', block: 'h6' },
    { tag: 'blockquote', label: '❝', title: 'Цитата', block: 'blockquote' },
    { sep: true, after: ['p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote'] },
    { tag: 'ul', label: '•', title: 'Маркированный список', exec: 'insertUnorderedList' },
    { tag: 'ol', label: '1.', title: 'Нумерованный список', exec: 'insertOrderedList' },
    { tag: 'a', label: '🔗', title: 'Ссылка', link: true },
    { tag: 'img', label: '🖼', title: 'Картинка', image: true }
];

function norm(tags) {
    return (tags || []).map(function (t) { return String(t).trim().toLowerCase(); }).filter(Boolean);
}

// Fallback, если allowedTags НЕ передан (undefined/null) — напр. старая кэш-
// страница без свежего mpcVEConfig.allowedTags. Пустой массив [] (настройка реально
// пустая) — это НЕ fallback: «ничего не разрешено» (как strip_tags с пустым списком).
var DEFAULT_TAGS = ['p', 'br', 'b', 'strong', 'i', 'em', 'u', 's', 'small', 'mark',
    'blockquote', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li', 'a', 'img', 'span'];

function effectiveTags(t) {
    return (t === undefined || t === null) ? DEFAULT_TAGS.slice() : norm(t);
}

function btnHtml(b) {
    var s = b.style ? (' style="' + b.style + '"') : '';
    return '<button type="button" class="mpcve-rte__btn" data-i="' + b._i + '" title="' + b.title + '"' + s + '>' + b.label + '</button>';
}

// --- дефолтный провайдер -----------------------------------------------
function create(container, opts) {
    opts = opts || {};
    var allowed = effectiveTags(opts.allowedTags);
    var has = function (tag) { return allowed.indexOf(tag) !== -1; };

    // Видимые кнопки: тег разрешён (a/img/wrap/exec/block — все по своему тегу).
    // Разделители — только если в группе осталась хоть одна кнопка по краям.
    var btns = [];
    var prevWasBtn = false;
    BUTTONS.forEach(function (b, i) {
        if (b.sep) {
            if (prevWasBtn) { btns.push({ sep: true }); prevWasBtn = false; }
            return;
        }
        if (has(b.tag)) {
            b._i = i;
            btns.push(b);
            prevWasBtn = true;
        }
    });
    // removeFormat — утилита, не тег; добавляем, если есть хоть какое-то форматирование.
    var anyInline = btns.some(function (b) { return b.exec || b.wrap; });
    var toolbarInner = btns.map(function (b) {
        return b.sep ? '<span class="mpcve-rte__sep"></span>' : btnHtml(b);
    }).join('');
    if (anyInline) {
        toolbarInner += '<span class="mpcve-rte__sep"></span>' +
            '<button type="button" class="mpcve-rte__btn" data-clear="1" title="Очистить формат">⌫</button>';
    }

    container.innerHTML =
        (toolbarInner ? '<div class="mpcve-rte__toolbar">' + toolbarInner + '</div>' : '') +
        '<div class="mpcve-rte__area" contenteditable="true" spellcheck="false"></div>';
    var area = container.querySelector('.mpcve-rte__area');
    area.innerHTML = opts.value || '';

    var toolbar = container.querySelector('.mpcve-rte__toolbar');
    if (toolbar) {
        // mousedown гасим — клик по кнопке не снимает выделение в области.
        toolbar.addEventListener('mousedown', function (e) {
            if (e.target.closest('.mpcve-rte__btn')) { e.preventDefault(); }
        });
        toolbar.addEventListener('click', function (e) {
            var el = e.target.closest('.mpcve-rte__btn');
            if (!el) { return; }
            e.preventDefault();
            area.focus();
            if (el.getAttribute('data-clear')) { document.execCommand('removeFormat', false, null); return; }
            var b = BUTTONS[parseInt(el.getAttribute('data-i'), 10)];
            runAction(b, area, opts);
        });
    }

    // MutationObserver → события add/change/delete (для классов/обработчиков).
    var obs = new MutationObserver(function (muts) {
        muts.forEach(function (m) {
            if (m.type === 'childList') {
                m.addedNodes.forEach(function (n) { if (n.nodeType === 1) { emit(area, 'nodeadded', n); } });
                m.removedNodes.forEach(function (n) { if (n.nodeType === 1) { emit(area, 'noderemoved', n); } });
            } else if (m.type === 'attributes' && m.target.nodeType === 1) {
                emit(area, 'nodechanged', m.target, m.attributeName);
            }
        });
    });
    obs.observe(area, { childList: true, subtree: true, attributes: true, characterData: false });

    return {
        getHTML: function () { return area.innerHTML; },
        focus: function () { area.focus(); },
        destroy: function () { obs.disconnect(); }
    };
}

function emit(area, name, node, attr) {
    var detail = { node: node, attribute: attr || null };
    // Для добавленного/изменённого узла шлём НА нём (event.target = узел, всплывает);
    // удалённый узел отвязан — шлём на области.
    var target = (name === 'noderemoved') ? area : node;
    try {
        target.dispatchEvent(new CustomEvent('mpcve:rte:' + name, { bubbles: true, detail: detail }));
    } catch (e) { /* старые браузеры без CustomEvent-конструктора — игнор */ }
}

function runAction(b, area, opts) {
    if (b.exec) { document.execCommand(b.exec, false, null); return; }
    if (b.block) { document.execCommand('formatBlock', false, b.block); return; }
    if (b.wrap) { wrapSelection(b.wrap); return; }
    if (b.link) {
        var sel = window.getSelection();
        var range = (sel && sel.rangeCount) ? sel.getRangeAt(0) : null;
        var url = window.prompt('URL ссылки:', 'https://');
        if (range) { sel.removeAllRanges(); sel.addRange(range); }
        if (url) { document.execCommand('createLink', false, url); }
        return;
    }
    if (b.image) { pickImage(area, opts); return; }
}

// Обернуть выделение в тег (small/mark/span — нет команды execCommand).
function wrapSelection(tag) {
    var sel = window.getSelection();
    if (!sel || !sel.rangeCount) { return; }
    var range = sel.getRangeAt(0);
    if (range.collapsed) { return; }
    var el = document.createElement(tag);
    try {
        range.surroundContents(el);
    } catch (e) {
        // выделение пересекает границы элементов — fallback через extract/insert
        el.appendChild(range.extractContents());
        range.insertNode(el);
    }
    sel.removeAllRanges();
    var r2 = document.createRange();
    r2.selectNodeContents(el);
    sel.addRange(r2);
}

// Картинка через наш загрузчик (opts.upload) → вставка <img> в каретку.
function pickImage(area, opts) {
    if (typeof opts.upload !== 'function') { toast('Загрузчик не настроен', true); return; }
    var input = document.createElement('input');
    input.type = 'file';
    input.accept = 'image/*';
    input.style.display = 'none';
    document.body.appendChild(input);
    input.addEventListener('change', function () {
        var file = input.files[0];
        input.remove();
        if (!file || file.type.indexOf('image/') !== 0) { return; }
        area.focus();
        opts.upload(file).then(function (url) {
            if (url) { document.execCommand('insertImage', false, url); }
        }).catch(function (err) { toast((err && err.message) || 'Ошибка загрузки', true); });
    });
    input.click();
}

// --- реестр (pluggable) ------------------------------------------------
var currentProvider = { create: create };

export function setRteProvider(provider) {
    if (provider && typeof provider.create === 'function') { currentProvider = provider; }
}

export function createRte(container, opts) {
    return currentProvider.create(container, opts);
}

// --- санитайз html по allowedTags (фильтр перед сохранением) ------------
// Зеркалит strip_tags на бэке: разрешённые теги остаются (с атрибутами),
// запрещённые — разворачиваются (контент/текст сохраняется). Применяем на save
// независимо от провайдера (RTE мог вставить что угодно).
export function sanitizeHtml(html, allowedTags) {
    var allowed = effectiveTags(allowedTags);
    var tmp = document.createElement('div');
    tmp.innerHTML = html == null ? '' : String(html);
    (function walk(node) {
        Array.prototype.slice.call(node.childNodes).forEach(function (c) {
            if (c.nodeType !== 1) { return; }
            walk(c);
            if (allowed.indexOf(c.tagName.toLowerCase()) === -1) {
                while (c.firstChild) { node.insertBefore(c.firstChild, c); }
                node.removeChild(c);
            }
        });
    })(tmp);
    return tmp.innerHTML;
}
