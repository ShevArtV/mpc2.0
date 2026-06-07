/**
 * mpcVisualEditor — сайдбар управления СЕКЦИЯМИ: порядок (position, drag-drop),
 * видимость (hide_section), статичность (is_static).
 *
 * Список — из configData.resource (стабы секций c position/is_static/hide_section),
 * сортировка по position. Запись — field/save на уровне resource: стаб секции
 * живёт в ресурс-конфиге, рендер мёржит type+resource и сортирует `uasort` по
 * position (Render::parseConfig). Эффект (скрытие/порядок/статичность меняют
 * РЕНДЕР, не DOM напрямую) виден после «Обновить».
 */
import { S } from './state.js';
import { api } from './api.js';
import { esc, toast } from './dom.js';

function boolOf(v) { return v === true || v === 1 || v === '1' || v === 'true'; }

// Список секций ресурса, отсортированный по position.
function sectionList() {
    var src = (S.configData && S.configData.resource) || {};
    return Object.keys(src).map(function (k) { return src[k]; })
        .filter(function (s) { return s && s.section_name; })
        .sort(function (a, b) { return ((parseInt(a.position, 10) || 0) - (parseInt(b.position, 10) || 0)); });
}

// Структурные операции над секциями — отдельный экшен section/op (RAW-запись
// массива конфига; field/save сюда не годится — лексиконизировал бы 0/1).
function sectionOp(payload) {
    payload.resourceId = S.cfg.resourceId || 0;
    return api.post('section/op', payload);
}

var panel = null;
var dragFrom = null;

export function toggleSidebar() {
    if (panel) { closeSidebar(); } else { openSidebar(); }
}

function closeSidebar() {
    if (panel) { panel.remove(); panel = null; }
}

function openSidebar() {
    if (!S.configData) {
        toast('Конфиг не загружен — включите режим редактирования', true);
        return;
    }
    panel = document.createElement('div');
    panel.className = 'mpcve-sidebar';
    panel.innerHTML =
        '<div class="mpcve-sidebar__head">Секции' +
            '<button type="button" class="mpcve-sidebar__x" data-act="close" title="Закрыть">✕</button>' +
        '</div>' +
        '<div class="mpcve-sidebar__hint">⋮⋮ перетащить — порядок · 👁 видимость · 📌 статичность</div>' +
        '<div class="mpcve-sidebar__list"></div>' +
        '<div class="mpcve-sidebar__foot">' +
            '<button type="button" class="mpcve-btn mpcve-btn--primary" data-act="reload">Обновить страницу</button>' +
        '</div>';
    document.body.appendChild(panel);
    panel.querySelector('[data-act=close]').addEventListener('click', closeSidebar);
    panel.querySelector('[data-act=reload]').addEventListener('click', function () { window.location.reload(); });
    render();
}

function render() {
    var box = panel.querySelector('.mpcve-sidebar__list');
    var items = sectionList();
    box.innerHTML = items.length ? items.map(function (s, i) {
        var hidden = boolOf(s.hide_section);
        var stat = boolOf(s.is_static);
        return '<div class="mpcve-sec" data-i="' + i + '" draggable="true">' +
            '<span class="mpcve-sec__grip" title="Перетащите, чтобы переставить">⋮⋮</span>' +
            '<span class="mpcve-sec__name' + (hidden ? ' mpcve-sec__name--off' : '') + '">' + esc(s.section_name) + '</span>' +
            '<button type="button" class="mpcve-sec__btn" data-op="vis" title="' + (hidden ? 'Скрыта — показать' : 'Видна — скрыть') + '">' + (hidden ? '🚫' : '👁') + '</button>' +
            '<button type="button" class="mpcve-sec__btn' + (stat ? ' mpcve-sec__btn--on' : '') + '" data-op="stat" title="' + (stat ? 'Статичная' : 'Не статичная') + '">📌</button>' +
        '</div>';
    }).join('') : '<div class="mpcve-hpanel__empty">Секции не найдены.</div>';
    wire(items);
}

function wire(items) {
    panel.querySelectorAll('.mpcve-sec').forEach(function (row) {
        var i = parseInt(row.getAttribute('data-i'), 10);
        var s = items[i];
        wireDrag(row, i, items);
        row.querySelector('[data-op=vis]').addEventListener('click', function () {
            var nv = boolOf(s.hide_section) ? 0 : 1;
            sectionOp({ op: 'visibility', section: s.section_name, value: nv }).then(function (r) {
                if (r && r.success) { s.hide_section = nv; render(); toast('Сохранено — «Обновить» для рендера'); }
                else { toast((r && r.message) || 'Ошибка', true); }
            }).catch(function () { toast('Сетевая ошибка', true); });
        });
        row.querySelector('[data-op=stat]').addEventListener('click', function () {
            var nv = boolOf(s.is_static) ? 0 : 1;
            sectionOp({ op: 'static', section: s.section_name, value: nv }).then(function (r) {
                if (r && r.success) { s.is_static = nv; render(); toast('Сохранено — «Обновить» для рендера'); }
                else { toast((r && r.message) || 'Ошибка', true); }
            }).catch(function () { toast('Сетевая ошибка', true); });
        });
    });
}

function wireDrag(row, idx, items) {
    row.addEventListener('dragstart', function (e) {
        dragFrom = idx;
        row.classList.add('mpcve-sec--drag');
        if (e.dataTransfer) {
            e.dataTransfer.effectAllowed = 'move';
            try { e.dataTransfer.setData('text/plain', String(idx)); } catch (_) {}
        }
    });
    row.addEventListener('dragend', function () {
        dragFrom = null;
        panel.querySelectorAll('.mpcve-sec--drag, .mpcve-sec--over').forEach(function (r) {
            r.classList.remove('mpcve-sec--drag', 'mpcve-sec--over');
        });
    });
    row.addEventListener('dragover', function (e) {
        if (dragFrom === null || dragFrom === idx) { return; }
        e.preventDefault();
        row.classList.add('mpcve-sec--over');
    });
    row.addEventListener('dragleave', function () { row.classList.remove('mpcve-sec--over'); });
    row.addEventListener('drop', function (e) {
        e.preventDefault();
        row.classList.remove('mpcve-sec--over');
        var from = dragFrom, to = idx;
        dragFrom = null;
        if (from === null || from === to) { return; }
        var moved = items.splice(from, 1)[0];
        items.splice(to, 0, moved);
        // Локально проставляем position для немедленной перерисовки; на бэк шлём
        // новый ПОРЯДОК имён одним запросом (section/op move переназначит position).
        items.forEach(function (s, i) { s.position = i + 1; });
        var order = items.map(function (s) { return s.section_name; });
        render();
        // rollback модели+UI при ошибке (обратный splice), иначе экран расходится с БД.
        var rollback = function () {
            items.splice(to, 1);
            items.splice(from, 0, moved);
            items.forEach(function (s, i) { s.position = i + 1; });
            render();
        };
        sectionOp({ op: 'move', order: order }).then(function (r) {
            if (r && r.success) { toast('Порядок сохранён — «Обновить» для рендера'); }
            else { rollback(); toast((r && r.message) || 'Ошибка сохранения порядка', true); }
        }).catch(function () { rollback(); toast('Сетевая ошибка', true); });
    });
}
