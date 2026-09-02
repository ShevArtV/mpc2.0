/**
 * mpcVisualEditor — модалка истории изменений ресурса (кто/когда/что) + откат.
 * Таблица с фильтрами (секция → зависимое поле, источник редактор/админка),
 * сортировкой по дате, инлайн-диффом старое↔новое (по словам, del/ins) и
 * разворотом полного содержимого. Откат (↩) — только у field-записей с
 * сохранённым старым значением (revertable); структурные операции — аудит.
 */
import { S } from './state.js';
import { api } from './api.js';
import { esc, toast, confirmDialog, closeOnBackdrop } from './dom.js';

var overlay = null;
var allEntries = [];
var flt = { section: '', field: '', source: '', sortDesc: true };

export function toggleChangelog() { overlay ? close() : open(); }

function close() {
    if (overlay) { overlay.remove(); overlay = null; document.removeEventListener('keydown', onKey); }
}
function onKey(e) { if (e.key === 'Escape') { close(); } }

function open() {
    flt = { section: '', field: '', source: '', sortDesc: true }; // сброс фильтров при каждом открытии
    overlay = document.createElement('div');
    overlay.className = 'mpcve-modal mpcve-clogm';
    overlay.innerHTML =
        '<div class="mpcve-modal__card mpcve-clogm__card">' +
            '<div class="mpcve-modal__head">История изменений' +
                '<button type="button" class="mpcve-clogm__x" data-act="close" title="Закрыть">✕</button>' +
            '</div>' +
            '<div class="mpcve-clogm__filters">' +
                '<label>Секция <select data-f="section"></select></label>' +
                '<label>Поле <select data-f="field"></select></label>' +
                '<label>Источник <select data-f="source">' +
                    '<option value="">все</option><option value="editor">редактор</option><option value="admin">админка</option>' +
                '</select></label>' +
                '<button type="button" class="mpcve-btn" data-act="sort">↓ дата</button>' +
                '<span class="mpcve-clogm__count"></span>' +
            '</div>' +
            '<div class="mpcve-clogm__wrap"><table class="mpcve-clogm__table">' +
                '<thead><tr><th>Время</th><th>Юзер</th><th>Ур.</th><th>Секция · Поле</th><th>Было → Стало</th><th></th></tr></thead>' +
                '<tbody></tbody>' +
            '</table></div>' +
        '</div>';
    document.body.appendChild(overlay);
    closeOnBackdrop(overlay, function () { close(); });
    overlay.querySelector('[data-act=close]').addEventListener('click', close);
    document.addEventListener('keydown', onKey);

    overlay.querySelector('[data-f=section]').addEventListener('change', function () {
        flt.section = this.value; flt.field = ''; buildFieldOptions(); render();
    });
    overlay.querySelector('[data-f=field]').addEventListener('change', function () { flt.field = this.value; render(); });
    overlay.querySelector('[data-f=source]').addEventListener('change', function () { flt.source = this.value; render(); });
    overlay.querySelector('[data-act=sort]').addEventListener('click', function () {
        flt.sortDesc = !flt.sortDesc; this.textContent = (flt.sortDesc ? '↓' : '↑') + ' дата'; render();
    });

    load();
}

function load() {
    var tbody = overlay.querySelector('tbody');
    tbody.innerHTML = '<tr><td colspan="6" class="mpcve-clogm__empty">Загрузка…</td></tr>';
    api.post('log/list', { resourceId: S.cfg.resourceId || 0, limit: 500 }).then(function (r) {
        allEntries = (r && r.success && r.data && r.data.entries) || [];
        buildSectionOptions(); buildFieldOptions(); render();
    }).catch(function () {
        tbody.innerHTML = '<tr><td colspan="6" class="mpcve-clogm__empty">Ошибка загрузки.</td></tr>';
    });
}

// --- фильтр-опции (из самих записей: фильтруем по тому, что есть в истории) ---
function fillSelect(sel, values, cur, allLabel) {
    sel.innerHTML = '<option value="">' + allLabel + '</option>' +
        values.map(function (v) {
            return '<option value="' + esc(v) + '"' + (v === cur ? ' selected' : '') + '>' + esc(v) + '</option>';
        }).join('');
}
function buildSectionOptions() {
    var set = {};
    allEntries.forEach(function (e) { if (e.section) { set[e.section] = 1; } });
    fillSelect(overlay.querySelector('[data-f=section]'), Object.keys(set).sort(), flt.section, 'все секции');
}
function buildFieldOptions() {
    var set = {};
    allEntries.forEach(function (e) {
        if (e.field && (!flt.section || e.section === flt.section)) { set[e.field] = 1; }
    });
    fillSelect(overlay.querySelector('[data-f=field]'), Object.keys(set).sort(), flt.field, 'все поля');
}

// --- рендер таблицы ---
function fmtTime(ts) {
    if (!ts) { return ''; }
    var d = new Date(ts * 1000), p = function (n) { return (n < 10 ? '0' : '') + n; };
    return p(d.getDate()) + '.' + p(d.getMonth() + 1) + ' ' + p(d.getHours()) + ':' + p(d.getMinutes());
}
var LEVEL = { resource: 'Р', type: 'Т', global: 'Г' };

function filtered() {
    var rows = allEntries.filter(function (e) {
        if (flt.section && e.section !== flt.section) { return false; }
        if (flt.field && e.field !== flt.field) { return false; }
        if (flt.source && (e.source || 'editor') !== flt.source) { return false; }
        return true;
    });
    rows.sort(function (a, b) { return flt.sortDesc ? (b.ts - a.ts) : (a.ts - b.ts); });
    return rows;
}

function render() {
    var tbody = overlay.querySelector('tbody');
    var rows = filtered();
    overlay.querySelector('.mpcve-clogm__count').textContent = rows.length + ' зап.';
    if (!rows.length) {
        tbody.innerHTML = '<tr><td colspan="6" class="mpcve-clogm__empty">Нет записей по фильтру.</td></tr>';
        return;
    }
    tbody.innerHTML = rows.map(function (e) {
        var label = e.action === 'field'
            ? (e.section ? (esc(e.section) + ' · ' + esc(e.field)) : esc(e.field))
            : (e.action === 'row' ? ('список: ' + esc(e.field)) : ('секции: ' + esc(e.field)));
        var diff = e.action === 'field'
            ? '<div class="mpcve-clogm__diff" title="клик — развернуть">' + diffHtml(e.old, e.new) + '</div>'
            : '<div class="mpcve-clogm__diff">' + esc(strip(e.new)) + '</div>';
        var act = e.revertable
            ? '<button type="button" class="mpcve-clogm__revert" data-id="' + esc(String(e.id)) + '">↩</button>'
            : (e.reverted ? '<span class="mpcve-clogm__rev">откачено</span>' : '');
        var src = (e.source === 'admin') ? ' <span class="mpcve-clogm__src" title="из админки">⚙</span>' : '';
        return '<tr>' +
            '<td class="mpcve-clogm__t">' + fmtTime(e.ts) + '</td>' +
            '<td>' + esc(e.user || '—') + src + '</td>' +
            '<td class="mpcve-clogm__lvl" title="' + esc(e.level || '') + '">' + (LEVEL[e.level] || '') + '</td>' +
            '<td>' + label + '</td>' +
            '<td>' + diff + '</td>' +
            '<td class="mpcve-clogm__act">' + act + '</td>' +
        '</tr>';
    }).join('');

    tbody.querySelectorAll('.mpcve-clogm__diff').forEach(function (d) {
        d.addEventListener('click', function () { d.classList.toggle('mpcve-clogm__diff--full'); });
    });
    tbody.querySelectorAll('.mpcve-clogm__revert').forEach(function (b) {
        b.addEventListener('click', function () {
            confirmDialog('Вернуть прежнее значение этого поля?', {
                title: 'Откат правки', okLabel: '↩ Вернуть', cancelLabel: 'Отмена'
            }).then(function (ok) {
                if (!ok) { return; }
                b.disabled = true;
                api.post('log/revert', { id: b.getAttribute('data-id') }).then(function (r) {
                    if (r && r.success) {
                        toast('Откат выполнен — обновляю…');
                        setTimeout(function () { window.location.reload(); }, 1000);
                    } else {
                        toast((r && r.message) || 'Ошибка отката', true); b.disabled = false;
                    }
                }).catch(function () { toast('Сетевая ошибка', true); b.disabled = false; });
            });
        });
    });
}

// --- инлайн-дифф по словам (LCS) ---
function strip(s) {
    return String(s == null ? '' : s).replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
}
function tokenize(s) { return strip(s).split(/(\s+)/).filter(function (t) { return t !== ''; }); }

function diffHtml(oldRaw, newRaw) {
    var a = tokenize(oldRaw), b = tokenize(newRaw);
    // Защита от тяжёлого LCS на больших значениях — фолбэк «старое → новое».
    if (a.length > 300 || b.length > 300) {
        return '<span class="mpcve-clogm__old">' + esc(strip(oldRaw)) + '</span> → ' +
               '<span class="mpcve-clogm__new">' + esc(strip(newRaw)) + '</span>';
    }
    var n = a.length, m = b.length;
    var dp = [];
    for (var i = 0; i <= n; i++) { dp[i] = new Array(m + 1).fill(0); }
    for (i = n - 1; i >= 0; i--) {
        for (var j = m - 1; j >= 0; j--) {
            dp[i][j] = (a[i] === b[j]) ? dp[i + 1][j + 1] + 1 : Math.max(dp[i + 1][j], dp[i][j + 1]);
        }
    }
    var out = [], ci = 0, cj = 0;
    function push(t, v) {
        var last = out[out.length - 1];
        if (last && last.t === t) { last.v += v; } else { out.push({ t: t, v: v }); }
    }
    while (ci < n && cj < m) {
        if (a[ci] === b[cj]) { push('eq', a[ci]); ci++; cj++; }
        else if (dp[ci + 1][cj] >= dp[ci][cj + 1]) { push('del', a[ci]); ci++; }
        else { push('ins', b[cj]); cj++; }
    }
    while (ci < n) { push('del', a[ci++]); }
    while (cj < m) { push('ins', b[cj++]); }
    if (!out.length) { return '<span class="mpcve-clogm__muted">(пусто)</span>'; }
    return out.map(function (p) {
        if (p.t === 'del') { return '<del>' + esc(p.v) + '</del>'; }
        if (p.t === 'ins') { return '<ins>' + esc(p.v) + '</ins>'; }
        return esc(p.v);
    }).join('');
}
