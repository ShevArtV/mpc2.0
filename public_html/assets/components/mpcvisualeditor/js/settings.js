/**
 * mpcVisualEditor — панель «Настройки»: список служебных настроек (data-mpc-info)
 * из settings/list. Источник — ИСХОДНЫЕ шаблоны (вкл. data-mpc-remove, которых нет
 * в DOM) + тип/значение из БД. Клик по записи открывает редактор настройки с её
 * типом (editors/info.js → info/save). Доступно при праве mpcve_edit_global.
 *
 * Переиспользует разметку .mpcve-sidebar (как журнал изменений).
 */
import { esc, toast } from './dom.js';
import { loadSettingsList } from './api.js';
import { openInfoEditor } from './editors/info.js';

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
        '<div class="mpcve-sidebar__hint">⚠ Меняются на ВСЕХ страницах. Тип берётся из БД.</div>' +
        '<div class="mpcve-sidebar__list"><div class="mpcve-hpanel__empty">Загрузка…</div></div>';
    document.body.appendChild(panel);
    panel.querySelector('[data-act=close]').addEventListener('click', close);
    loadSettingsList(true).then(function (items) {
        if (panel) { render(items || []); }
    }).catch(function () { toast('Не удалось загрузить настройки', true); });
}

function render(items) {
    var box = panel.querySelector('.mpcve-sidebar__list');
    if (!items.length) {
        box.innerHTML = '<div class="mpcve-hpanel__empty">Настройки (data-mpc-info) не найдены.</div>';
        return;
    }
    box.innerHTML = items.map(function (s, i) {
        var lock = s.protected ? ' 🔒' : '';
        var val = (s.value != null && s.value !== '') ? esc(String(s.value).replace(/\s+/g, ' ').slice(0, 90)) : '<i>пусто</i>';
        return '<div class="mpcve-set" data-i="' + i + '" title="Клик — редактировать">' +
            '<div class="mpcve-set__key">' + esc(s.key) + lock +
                (s.ctx != null ? ' <span class="mpcve-set__ctx">@' + esc(s.ctx || 'web') + '</span>' : '') +
            '</div>' +
            '<div class="mpcve-set__val">' + val + '</div>' +
        '</div>';
    }).join('');
    box.querySelectorAll('.mpcve-set').forEach(function (row) {
        var s = items[parseInt(row.getAttribute('data-i'), 10)];
        row.addEventListener('click', function () {
            // элемент на странице (если есть) — для живого апдейта DOM; иначе null.
            var dom = document.querySelector('[data-mpc-info="' + (window.CSS && CSS.escape ? CSS.escape(s.key) : s.key) + '"]');
            close();
            openInfoEditor(dom || null, s);
        });
    });
}
