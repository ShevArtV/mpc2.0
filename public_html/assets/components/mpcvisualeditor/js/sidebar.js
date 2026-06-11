/**
 * mpcVisualEditor — сайдбар управления СЕКЦИЯМИ: порядок (position, drag-drop),
 * видимость (hide_section), статичность (is_static) + работа с наследованием от ТИПА.
 *
 * Объединённый список: СВОИ секции ресурса (origin=resource — обычные контролы) +
 * наследуемые из типа (origin=type — под 🔒, клик копирует секцию в ресурс).
 * Сверху — массовые операции из типа: дополнить / перезаписать / очистить.
 * На самой странице-ТИПЕ наследования нет → только свои секции, без «из типа».
 * Запись — section/op (RAW-массив конфига); эффект виден после «Обновить».
 */
import { S } from './state.js';
import { api, loadConfig } from './api.js';
import { esc, toast, confirmDialog } from './dom.js';

function boolOf(v) { return v === true || v === 1 || v === '1' || v === 'true'; }
function byPos(a, b) { return (parseInt(a.position, 10) || 0) - (parseInt(b.position, 10) || 0); }

// Ключ секции — как на бэке (MIGX_formname + lexicon_prefix|section_name).
function keyOf(s) {
    return String(s.MIGX_formname || '') + '|' + String(s.lexicon_prefix || s.section_name || '');
}
function asArray(src) {
    if (!src) { return []; }
    return Object.keys(src).map(function (k) { return src[k]; })
        .filter(function (s) { return s && s.section_name; });
}

// Объединённый список: свои + наследуемые из типа (которых нет у ресурса).
function sectionList() {
    var d = S.configData || {};
    var own = asArray(d.resource).map(function (s) { s._origin = 'resource'; return s; });
    if (d.isType) { return own.sort(byPos); }
    var ownKeys = {};
    own.forEach(function (s) { ownKeys[keyOf(s)] = true; });
    var inherited = asArray(d.type)
        .filter(function (s) { return !ownKeys[keyOf(s)]; })
        .map(function (s) { s._origin = 'type'; return s; });
    return own.concat(inherited).sort(byPos);
}

function sectionOp(payload) {
    payload.resourceId = S.cfg.resourceId || 0;
    return api.post('section/op', payload);
}
function netErr() { toast('Сетевая ошибка', true); }
// Рефреш конфига с сервера + перерисовка (после операций, меняющих НАБОР секций).
function refresh() { return loadConfig().then(function () { if (panel) { render(); } }); }

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
    var fromType = !S.configData.isType
        ? '<div class="mpcve-sidebar__fromtype">' +
            '<button type="button" class="mpcve-btn mpcve-btn--sm" data-bulk="merge" title="Добавить секции типа, которых ещё нет у страницы">＋ Дополнить из типа</button>' +
            '<button type="button" class="mpcve-btn mpcve-btn--sm" data-bulk="overwrite" title="Заменить секции страницы секциями типа">⟳ Перезаписать из типа</button>' +
            '<button type="button" class="mpcve-btn mpcve-btn--sm mpcve-btn--danger" data-bulk="clear" title="Удалить секции страницы (вернётся наследование из типа)">🗑 Очистить</button>' +
          '</div>'
        : '';
    panel.innerHTML =
        '<div class="mpcve-sidebar__head">Секции' +
            '<button type="button" class="mpcve-sidebar__x" data-act="close" title="Закрыть">✕</button>' +
        '</div>' +
        '<div class="mpcve-sidebar__hint">⋮⋮ порядок · 👁 видимость · 📌 статичность · 🔒 из типа (клик — скопировать)</div>' +
        fromType +
        '<div class="mpcve-sidebar__list"></div>' +
        '<div class="mpcve-sidebar__foot">' +
            '<button type="button" class="mpcve-btn mpcve-btn--primary" data-act="reload">Обновить страницу</button>' +
        '</div>';
    document.body.appendChild(panel);
    panel.querySelector('[data-act=close]').addEventListener('click', closeSidebar);
    panel.querySelector('[data-act=reload]').addEventListener('click', function () { window.location.reload(); });
    panel.querySelectorAll('[data-bulk]').forEach(function (b) {
        b.addEventListener('click', function () { bulk(b.getAttribute('data-bulk')); });
    });
    render();
}

// Массовые операции из типа (overwrite/clear — деструктивны, спрашиваем подтверждение).
function bulk(kind) {
    if (kind === 'merge') {
        sectionOp({ op: 'from_type', mode: 'merge' }).then(afterBulk).catch(netErr);
        return;
    }
    var msg = kind === 'overwrite'
        ? 'Перезаписать ВСЕ секции страницы секциями типа? Правки текущих секций будут потеряны.'
        : 'Очистить секции страницы? Контент секций удалится, останется наследование из типа.';
    confirmDialog(msg, { okLabel: kind === 'clear' ? 'Очистить' : 'Перезаписать', danger: true }).then(function (ok) {
        if (!ok) { return; }
        sectionOp({ op: kind === 'clear' ? 'clear' : 'from_type', mode: 'overwrite' }).then(afterBulk).catch(netErr);
    });
}
function afterBulk(r) {
    if (r && r.success) { refresh(); toast('Готово — «Обновить» для рендера'); }
    else { toast((r && r.message) || 'Ошибка', true); }
}

function render() {
    var box = panel.querySelector('.mpcve-sidebar__list');
    var items = sectionList();
    box.innerHTML = items.length ? items.map(function (s, i) {
        return s._origin === 'type' ? lockedRow(s, i) : ownRow(s, i);
    }).join('') : '<div class="mpcve-hpanel__empty">Секции не найдены.</div>';
    wire(items);
}

function ownRow(s, i) {
    var hidden = boolOf(s.hide_section);
    var stat = boolOf(s.is_static);
    return '<div class="mpcve-sec" data-i="' + i + '" draggable="true">' +
        '<span class="mpcve-sec__grip" title="Перетащите, чтобы переставить">⋮⋮</span>' +
        '<span class="mpcve-sec__name' + (hidden ? ' mpcve-sec__name--off' : '') + '">' + esc(s.section_name) + '</span>' +
        '<button type="button" class="mpcve-sec__btn" data-op="vis" title="' + (hidden ? 'Скрыта — показать' : 'Видна — скрыть') + '">' + (hidden ? '🚫' : '👁') + '</button>' +
        '<button type="button" class="mpcve-sec__btn' + (stat ? ' mpcve-sec__btn--on' : '') + '" data-op="stat" title="' + (stat ? 'Статичная' : 'Не статичная') + '">📌</button>' +
    '</div>';
}

function lockedRow(s, i) {
    return '<div class="mpcve-sec mpcve-sec--locked" data-i="' + i + '" title="Секция из типа — клик, чтобы скопировать в эту страницу">' +
        '<span class="mpcve-sec__grip mpcve-sec__grip--lock">🔒</span>' +
        '<span class="mpcve-sec__name mpcve-sec__name--inherit">' + esc(s.section_name) + '</span>' +
        '<button type="button" class="mpcve-sec__btn" data-op="copy" title="Скопировать в эту страницу">⬇</button>' +
    '</div>';
}

function copyFromType(s) {
    sectionOp({ op: 'copy_one', section: s.section_name }).then(function (r) {
        if (r && r.success) { refresh(); toast('Секция скопирована — «Обновить» для рендера'); }
        else { toast((r && r.message) || 'Ошибка', true); }
    }).catch(netErr);
}

function wire(items) {
    panel.querySelectorAll('.mpcve-sec').forEach(function (row) {
        var i = parseInt(row.getAttribute('data-i'), 10);
        var s = items[i];
        if (s._origin === 'type') {
            row.addEventListener('click', function () { copyFromType(s); });
            return;
        }
        wireDrag(row, i, items);
        row.querySelector('[data-op=vis]').addEventListener('click', function () {
            var nv = boolOf(s.hide_section) ? 0 : 1;
            sectionOp({ op: 'visibility', section: s.section_name, value: nv }).then(function (r) {
                if (r && r.success) { s.hide_section = nv; render(); toast('Сохранено — «Обновить» для рендера'); }
                else { toast((r && r.message) || 'Ошибка', true); }
            }).catch(netErr);
        });
        row.querySelector('[data-op=stat]').addEventListener('click', function () {
            var nv = boolOf(s.is_static) ? 0 : 1;
            sectionOp({ op: 'static', section: s.section_name, value: nv }).then(function (r) {
                if (r && r.success) { s.is_static = nv; render(); toast('Сохранено — «Обновить» для рендера'); }
                else { toast((r && r.message) || 'Ошибка', true); }
            }).catch(netErr);
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
        items.forEach(function (s, i) { s.position = i + 1; });
        // На бэк — порядок имён ТОЛЬКО своих секций (наследуемые из типа не двигаем).
        var order = items.filter(function (s) { return s._origin !== 'type'; })
            .map(function (s) { return s.section_name; });
        render();
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
