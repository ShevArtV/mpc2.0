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
import { openSectionFields } from './editors/sectionfields.js';
import { openForSection } from './scope.js';
import { sectionKeyOf } from './address.js';

function boolOf(v) { return v === true || v === 1 || v === '1' || v === 'true'; }
function byPos(a, b) { return (parseInt(a.position, 10) || 0) - (parseInt(b.position, 10) || 0); }

// Ключ записи конфига. section_name уникален (человекочитаемое имя ЭТОЙ записи),
// MIGX_formname — имя ТИПА секции, общее у копий; серверные матчеры и
// findSectionInLevel понимают оба, поэтому уникальное берём первым.
function domKeyOf(s) {
    return String(s.section_name || s.MIGX_formname || '');
}
// Поиск секции в DOM: сперва по уникальному section_name (mpc с 2.5.67
// подставляет его в data-mpc-name каждой копии), затем фолбэк на MIGX_formname
// для старого рендера, где у всех копий атрибуты первой секции.
function findSectionDom(s) {
    var keys = [String(s.section_name || ''), String(s.MIGX_formname || '')];
    var els = Array.prototype.slice.call(document.querySelectorAll('[data-mpc-section]'));
    for (var k = 0; k < keys.length; k++) {
        if (!keys[k]) { continue; }
        for (var i = 0; i < els.length; i++) {
            if (sectionKeyOf(els[i]) === keys[k]
                || els[i].getAttribute('data-mpc-section') === keys[k]) {
                return { el: els[i], idx: i };
            }
        }
    }
    return { el: null, idx: -1 };
}
// DOM-элемент секции (на странице). null — секции нет в DOM (скрытая/вырезанная).
function findSectionEl(s) {
    return findSectionDom(s).el;
}
// Индекс секции в порядке DOM (как на странице); -1 если нет в DOM.
function domIndex(s) {
    return findSectionDom(s).idx;
}
// Сортировка как на СТРАНИЦЕ: по позиции в DOM; секции без DOM (наследуемые/
// скрытые) — в конец, между собой по position.
function byDom(a, b) {
    var ia = domIndex(a), ib = domIndex(b);
    if (ia === -1 && ib === -1) { return byPos(a, b); }
    if (ia === -1) { return 1; }
    if (ib === -1) { return -1; }
    return ia - ib;
}

// Эффективная позиция секции: для своей — position из РЕСУРСА, для наследуемой —
// из ТИПА (own/inherited — разные записи, у каждой свой position → каскад «тип
// задаёт набор, ресурс переопределяет порядок»). Пустой position → в конец.
function effPos(s) {
    var p = parseInt(s.position, 10);
    return isNaN(p) ? 1e9 : p;
}
// Порядок как РЕНДЕР: по position (-1 и отрицательные — вверху, меньше
// положительных). Равные позиции — стабильно по DOM (порядок на странице).
function byPosition(a, b) {
    var d = effPos(a) - effPos(b);
    return d !== 0 ? d : byDom(a, b);
}

// Прокрутить страницу к секции + подсветить. Работает и для своих, и для
// наследуемых из типа (наследуемые тоже рендерятся на странице).
function scrollToSection(s) {
    var el = findSectionEl(s);
    if (!el) { toast('Секция не видна на странице (скрыта)', true); return; }
    el.scrollIntoView({ behavior: 'smooth', block: 'start' });
    el.classList.add('mpcve-sec-flash');
    setTimeout(function () { el.classList.remove('mpcve-sec-flash'); }, 1200);
}

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
    // КЛОН перед добавлением служебного _origin: иначе мутируем общие объекты
    // секций в S.configData, и синтетический ключ _origin утекает в панель скрытых
    // полей (Object.keys) как мнимое поле «_origin = resource». Shallow-копии
    // достаточно — сайдбар читает только _origin + плоские поля секции.
    var own = asArray(d.resource).map(function (s) { var c = Object.assign({}, s); c._origin = 'resource'; return c; });
    if (d.isType) { return own.sort(byPosition); }
    var ownKeys = {};
    own.forEach(function (s) { ownKeys[keyOf(s)] = true; });
    var inherited = asArray(d.type)
        .filter(function (s) { return !ownKeys[keyOf(s)]; })
        .map(function (s) { var c = Object.assign({}, s); c._origin = 'type'; return c; });
    // Порядок как на странице (DOM); наследуемые/скрытые — в конец.
    return own.concat(inherited).sort(byPosition);
}

function sectionOp(payload) {
    payload.resourceId = S.cfg.resourceId || 0;
    return api.post('section/op', payload);
}
function netErr() { toast('Сетевая ошибка', true); }
// Рефреш конфига с сервера + перерисовка (после операций, меняющих НАБОР секций).
function refresh() { return loadConfig().then(function () { currentItems = null; if (panel) { render(); } }); }

var panel = null;
var dragFrom = null;
// Текущий порядок строк панели (фиксируем, чтобы drag-перестановка/правки не
// откатывались ре-сортировкой по DOM при каждом render). Сбрасывается на refresh.
var currentItems = null;

export function toggleSidebar() {
    if (panel) { closeSidebar(); } else { openSidebar(); }
}
function closeSidebar() {
    if (panel) { panel.remove(); panel = null; }
    currentItems = null;
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
    if (!currentItems) { currentItems = sectionList(); }
    var items = currentItems;
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
        '<button type="button" class="mpcve-sec__btn" data-op="fields" title="Поля секции (вкл. скрытые и списки)">✎</button>' +
        '<button type="button" class="mpcve-sec__btn" data-op="vis" title="' + (hidden ? 'Скрыта — показать' : 'Видна — скрыть') + '">' + (hidden ? '🚫' : '👁') + '</button>' +
        '<button type="button" class="mpcve-sec__btn' + (stat ? ' mpcve-sec__btn--on' : '') + '" data-op="stat" title="' + (stat ? 'Статичная' : 'Не статичная') + '">📌</button>' +
    '</div>';
}

function lockedRow(s, i) {
    return '<div class="mpcve-sec mpcve-sec--locked" data-i="' + i + '" title="Секция наследуется от типа страницы">' +
        '<span class="mpcve-sec__grip mpcve-sec__grip--lock">🔒</span>' +
        '<span class="mpcve-sec__name mpcve-sec__name--inherit">' + esc(s.section_name) + '</span>' +
        '<button type="button" class="mpcve-sec__btn" data-op="fields" title="Редактировать поля типа">✎</button>' +
        '<button type="button" class="mpcve-sec__btn" data-op="copy" title="Локализовать секцию для этой страницы">⬇</button>' +
    '</div>';
}

function copyFromType(s) {
    sectionOp({ op: 'copy_one', section: s.section_name }).then(function (r) {
        if (r && r.success) { refresh(); toast('Секция локализована — «Обновить» для рендера'); }
        else { toast((r && r.message) || 'Ошибка', true); }
    }).catch(netErr);
}

function wire(items) {
    panel.querySelectorAll('.mpcve-sec').forEach(function (row) {
        var i = parseInt(row.getAttribute('data-i'), 10);
        var s = items[i];
        // Клик по имени секции → скролл к ней на странице (для ВСЕХ — свои и из
        // типа: наследуемые тоже видны на странице).
        var nameEl = row.querySelector('.mpcve-sec__name');
        if (nameEl) {
            nameEl.style.cursor = 'pointer';
            nameEl.title = 'Клик — прокрутить к секции';
            nameEl.addEventListener('click', function () { scrollToSection(s); });
        }
        if (s._origin === 'type') {
            // Копирование в страницу — ТОЛЬКО по кнопке ⬇, не по всему ряду
            // (иначе клик-скролл по имени случайно копировал секцию).
            var copyBtn = row.querySelector('[data-op=copy]');
            if (copyBtn) {
                copyBtn.addEventListener('click', function (e) { e.stopPropagation(); copyFromType(s); });
            }
            var inheritedFields = row.querySelector('[data-op=fields]');
            if (inheritedFields) {
                inheritedFields.addEventListener('click', function (e) {
                    e.stopPropagation();
                    closeSidebar();
                    openForSection(domKeyOf(s), false, function () { openSectionFields(s); });
                });
            }
            return;
        }
        wireDrag(row, i, items);
        var fieldsBtn = row.querySelector('[data-op=fields]');
        if (fieldsBtn) {
            // Панель полей секции (config-driven): скрытые/вырезанные поля + MIGX-списки.
            // Сайдбар сам — не .mpcve-modal, но панель полей — да; закрываем сайдбар,
            // чтобы Escape/клики не путались между двумя оверлеями.
            fieldsBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                closeSidebar();
                openForSection(domKeyOf(s), boolOf(s.is_static), function () { openSectionFields(s); });
            });
        }
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
