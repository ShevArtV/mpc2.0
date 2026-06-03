/**
 * mpcVisualEditor — журнал изменений ресурса (кто/когда/что) + откат правок полей.
 * Кнопка «🕓 История» в тулбаре → панель справа. Откат (↩) есть только у записей
 * полей с сохранённым старым значением (revertable); структурные операции
 * (списки/секции) — только аудит.
 */
import { S } from './state.js';
import { api } from './api.js';
import { esc, toast } from './dom.js';

var panel = null;

export function toggleChangelog() {
    if (panel) { closePanel(); } else { openPanel(); }
}

function closePanel() { if (panel) { panel.remove(); panel = null; } }

function openPanel() {
    panel = document.createElement('div');
    panel.className = 'mpcve-sidebar mpcve-clog';
    panel.innerHTML =
        '<div class="mpcve-sidebar__head">История изменений' +
            '<button type="button" class="mpcve-sidebar__x" data-act="close" title="Закрыть">✕</button>' +
        '</div>' +
        '<div class="mpcve-sidebar__list"></div>';
    document.body.appendChild(panel);
    panel.querySelector('[data-act=close]').addEventListener('click', closePanel);
    load();
}

function fmtTime(ts) {
    if (!ts) { return ''; }
    var d = new Date(ts * 1000);
    var p = function (n) { return (n < 10 ? '0' : '') + n; };
    return p(d.getDate()) + '.' + p(d.getMonth() + 1) + ' ' + p(d.getHours()) + ':' + p(d.getMinutes());
}

function trim(s, n) {
    s = String(s == null ? '' : s).replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
    return s.length > n ? (s.slice(0, n) + '…') : s;
}

function load() {
    var box = panel.querySelector('.mpcve-sidebar__list');
    box.innerHTML = '<div class="mpcve-hpanel__empty">Загрузка…</div>';
    api.post('log/list', { resourceId: S.cfg.resourceId || 0 }).then(function (r) {
        var entries = (r && r.success && r.data && r.data.entries) || [];
        if (!entries.length) {
            box.innerHTML = '<div class="mpcve-hpanel__empty">Изменений пока нет.</div>';
            return;
        }
        box.innerHTML = entries.map(function (e) {
            var label = e.action === 'field'
                ? (e.section ? (e.section + ' · ' + e.field) : e.field)
                : (e.action === 'row' ? ('список: ' + e.field) : ('секции: ' + e.field));
            var diff = (e.action === 'field')
                ? '<div class="mpcve-clog__diff"><span class="mpcve-clog__old">' + esc(trim(e.old, 60)) + '</span> → <span class="mpcve-clog__new">' + esc(trim(e.new, 60)) + '</span></div>'
                : '<div class="mpcve-clog__diff">' + esc(trim(e.new, 80)) + '</div>';
            var rev = e.revertable
                ? '<button type="button" class="mpcve-clog__revert" data-id="' + e.id + '">↩ Вернуть</button>'
                : (e.reverted ? '<span class="mpcve-clog__reverted">откачено</span>' : '');
            return '<div class="mpcve-clog__row">' +
                '<div class="mpcve-clog__meta">' + esc(e.user || '—') + ' · ' + fmtTime(e.ts) + '</div>' +
                '<div class="mpcve-clog__field">' + esc(label) + '</div>' +
                diff + rev +
            '</div>';
        }).join('');
        box.querySelectorAll('.mpcve-clog__revert').forEach(function (b) {
            b.addEventListener('click', function () {
                if (!window.confirm('Вернуть прежнее значение этого поля?')) { return; }
                b.disabled = true;
                api.post('log/revert', { id: b.getAttribute('data-id') }).then(function (r) {
                    if (r && r.success) {
                        toast('Откат выполнен — обновляю…');
                        setTimeout(function () { window.location.reload(); }, 1000);
                    } else {
                        toast((r && r.message) || 'Ошибка отката', true);
                        b.disabled = false;
                    }
                }).catch(function () { toast('Сетевая ошибка', true); b.disabled = false; });
            });
        });
    }).catch(function () {
        box.innerHTML = '<div class="mpcve-hpanel__empty">Ошибка загрузки.</div>';
    });
}
