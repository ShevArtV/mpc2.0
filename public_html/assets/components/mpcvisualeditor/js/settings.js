/**
 * mpcVisualEditor — панель «Настройки»: список ВСЕХ data-mpc-info на странице,
 * включая <head> (favicon/метрики), по которым нельзя кликнуть инлайн. Клик по
 * записи открывает редактор настройки (editors/info.js → info/save). Кнопка в
 * тулбаре и сама панель доступны только при праве mpcve_edit_global.
 *
 * Переиспользует разметку .mpcve-sidebar (как журнал изменений).
 */
import { esc } from './dom.js';
import { openInfoEditor } from './editors/info.js';

function tag(el) { return el.tagName ? el.tagName.toLowerCase() : ''; }
function valueOf(el) {
    var t = tag(el);
    if (t === 'link') { return el.getAttribute('href') || ''; }
    if (t === 'img') { return el.getAttribute('src') || ''; }
    return (el.textContent || '').replace(/\s+/g, ' ').trim();
}

// Уникальные настройки страницы по ключу+контексту (один data-mpc-info может
// встречаться несколько раз — например в шапке и подвале; это одна настройка).
function uniqueItems() {
    var seen = {};
    return Array.prototype.slice.call(document.querySelectorAll('[data-mpc-info]'))
        .filter(function (el) {
            var k = (el.getAttribute('data-mpc-info') || '') + '|'
                + (el.hasAttribute('data-mpc-ctx') ? (el.getAttribute('data-mpc-ctx') || '') : '');
            if (seen[k]) { return false; }
            seen[k] = 1;
            return true;
        });
}

var panel = null;

export function toggleSettings() {
    if (panel) { close(); } else { open(); }
}
function close() { if (panel) { panel.remove(); panel = null; } }

function open() {
    panel = document.createElement('div');
    panel.className = 'mpcve-sidebar';
    panel.innerHTML =
        '<div class="mpcve-sidebar__head">Настройки сайта' +
            '<button type="button" class="mpcve-sidebar__x" data-act="close" title="Закрыть">✕</button>' +
        '</div>' +
        '<div class="mpcve-sidebar__hint">⚠ Меняются на ВСЕХ страницах. Список data-mpc-info этой страницы.</div>' +
        '<div class="mpcve-sidebar__list"></div>';
    document.body.appendChild(panel);
    panel.querySelector('[data-act=close]').addEventListener('click', close);
    render();
}

function render() {
    var box = panel.querySelector('.mpcve-sidebar__list');
    var items = uniqueItems();
    if (!items.length) {
        box.innerHTML = '<div class="mpcve-hpanel__empty">На странице нет data-mpc-info.</div>';
        return;
    }
    box.innerHTML = items.map(function (el, i) {
        var key = el.getAttribute('data-mpc-info') || '';
        var ctx = el.hasAttribute('data-mpc-ctx') ? (el.getAttribute('data-mpc-ctx') || 'тек.') : '';
        var val = valueOf(el);
        return '<div class="mpcve-set" data-i="' + i + '" title="Клик — редактировать">' +
            '<div class="mpcve-set__key">' + esc(key) +
                (ctx ? ' <span class="mpcve-set__ctx">@' + esc(ctx) + '</span>' : '') + '</div>' +
            '<div class="mpcve-set__val">' + (val ? esc(val.slice(0, 90)) : '<i>пусто</i>') + '</div>' +
        '</div>';
    }).join('');
    box.querySelectorAll('.mpcve-set').forEach(function (row) {
        var el = items[parseInt(row.getAttribute('data-i'), 10)];
        row.addEventListener('click', function () { close(); openInfoEditor(el); });
    });
}
