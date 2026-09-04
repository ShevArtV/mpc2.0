/**
 * mpcVisualEditor — РЕДАКТИРОВАНИЕ ПОЛЕЙ СЕКЦИИ ИЗ САЙДБАРА (config-driven).
 *
 * В отличие от panels.js (триггеры у блоков на странице, DOM-driven), этот модуль
 * строит список полей секции ПОЛНОСТЬЮ из конфига (S.configData) — поэтому правит
 * и поля, вырезанные из вёрстки (data-mpc-remove), и MIGX-СПИСКИ (строки + поля
 * строк, любой вложенности). DOM-элемента поля/списка не требуется.
 *
 * Адресация и запись — те же адресные эндпоинты, что у DOM-редакторов:
 *   field/save  — значение поля (address: section/level/fieldName[/path/parentField/idx]);
 *   row/op      — операции со строками (add/delete/move, address с path для вложенных).
 * Рендер строк/превью берём из значений конфига; после row/op рефрешим конфиг.
 *
 * Навигация модальная-форвардная (как у record-редакторов panels.js): открытие
 * вложенного уровня закрывает текущую панель (стек модалок не делаем).
 */
import { S } from '../state.js';
import { api, loadConfig } from '../api.js';
import { parseRecord, isScalar, fieldLabel, esc, toast, confirmDialog, openModal } from '../dom.js';
import { STRUCTURAL } from '../constants.js';
import { findSectionInLevel } from '../address.js';
import {
    openBlockPanel, isImgRecord, recordKind, isSectionExcluded, lexValue, MIGX_SERVICE, _setRowsOpener
} from '../panels.js';

function rid() { return S.cfg.resourceId || 0; }
function boolOf(v) { return v === true || v === 1 || v === '1' || v === 'true'; }

// Запись/список MIGX из значения конфига. ВАЖНО: на ВЛОЖЕННЫХ уровнях значение
// уже распарсено (массив/объект) — внешний JSON.parse раскрыл всё дерево; на
// уровне СЕКЦИИ оно ещё строка. dom.parseRecord парсит только строки → массивы
// здесь обрабатываем напрямую.
function recOf(v) {
    if (Array.isArray(v)) { return v; }
    return (typeof v === 'string') ? parseRecord(v) : null;
}

// Ключи, из которых состоит media-ЗАПИСЬ (picture/video/audio/img). Всё, что вне
// набора, — пользовательское поле строки MIGX-списка.
var MEDIA_KEYS = [
    'MIGX_id', 'src', 'srcset', 'sources', 'img', 'alt', 'title', 'width', 'height',
    'poster', 'type', 'media', 'class', 'id', 'style', 'loading', 'controls', 'autoplay',
    'loop', 'muted', 'playsinline', 'preload'
];

// Список СТРОК, а не media-запись. Media-запись всегда одна (rec.length === 1) и
// состоит только из MEDIA_KEYS; список строк — либо длиннее одной, либо несёт
// собственные поля. Проверять ДО recordKind: строка с подполем `img` (частый
// случай карточки с картинкой) иначе опознаётся как <picture> и список
// становится нередактируемым.
function isRowsRecord(rec) {
    if (!Array.isArray(rec) || !rec.length || !rec[0] || typeof rec[0] !== 'object') { return false; }
    if (rec.length > 1) { return true; }
    return Object.keys(rec[0]).some(function (k) { return MEDIA_KEYS.indexOf(k) === -1; });
}

// Тип поля для панели: сначала карта типов mpc_base (надёжнее формы значения),
// затем — форма записи как фолбэк. Возвращает: rows|image|picture|video|audio|scalar.
function fieldKind(fname, rec) {
    var t = String(S.typesMap[fname] || '');
    if (t.indexOf('list') === 0) { return 'rows'; }
    if (t === 'img' || t === 'bg_img') { return 'image'; }
    if (t === 'picture') { return 'picture'; }
    if (t === 'video') { return 'video'; }
    if (t === 'audio') { return 'audio'; }
    if (isRowsRecord(rec)) { return 'rows'; }
    if (isImgRecord(rec)) { return 'image'; }
    var k = recordKind(rec);
    if (k === 'video' || k === 'audio') {
        // recordKind различает video/audio по poster/width/height, которых может не
        // быть → уточняем по MIME источника (video/mp4 → video).
        var s0 = (rec[0] && rec[0].sources && rec[0].sources[0]) || {};
        return /^video\//i.test(String(s0.type || '')) ? 'video' : k;
    }
    if (k) { return k; }
    if (Array.isArray(rec)) { return 'rows'; } // массив строк без типа → список
    return 'scalar';
}

// Контент-объект секции в конфиге: static → global, иначе resource (с фолбэком).
function sectionContent(name, isStatic) {
    var level = isStatic ? 'global' : 'resource';
    return findSectionInLevel(name, level)
        || findSectionInLevel(name, 'type')
        || findSectionInLevel(name, 'resource')
        || findSectionInLevel(name, 'global');
}

// Спуск по path [{field,idx},…] к объекту-строке (или к самой секции при пустом path).
function navigate(obj, path) {
    var cur = obj;
    for (var i = 0; i < path.length; i++) {
        var rows = recOf(cur[path[i].field]);
        if (!rows || !rows[path[i].idx]) { return null; }
        cur = rows[path[i].idx];
    }
    return cur;
}

// Строки списка по адресу: секция → спуск по path → parseRecord(parentField).
function readRows(listAddr) {
    var sc = sectionContent(listAddr.section, listAddr.level === 'global');
    if (!sc) { return []; }
    var parent = navigate(sc.obj, listAddr.path || []);
    if (!parent) { return []; }
    var rows = recOf(parent[listAddr.parentField]);
    return Array.isArray(rows) ? rows : [];
}

// Дескрипторы полей объекта (секции или строки) для openBlockPanel.
//   ctx = { level, section, path }  (path — спуск к ЭТОМУ объекту-строке; [] для секции)
// path>0 → row-уровень (исключаем структурные), path=0 → секция (исключаем
// настройки/стили/служебку через isSectionExcluded).
function fieldDescriptors(obj, ctx) {
    var out = [];
    Object.keys(obj).forEach(function (fname) {
        if (fname === 'MIGX_id' || MIGX_SERVICE.indexOf(fname) !== -1) { return; }
        if (ctx.path.length === 0) {
            if (isSectionExcluded(fname)) { return; }
        } else if (STRUCTURAL.indexOf(fname) !== -1) {
            return;
        }
        var v = obj[fname];
        var rec = recOf(v);
        var kind = fieldKind(fname, rec);
        var base = { level: ctx.level, section: ctx.section, fieldName: fname, label: fieldLabel(fname) };
        if (ctx.path.length) {
            base.path = ctx.path.slice();
            base.parentField = ctx.path[ctx.path.length - 1].field;
            base.idx = ctx.path[ctx.path.length - 1].idx;
        }
        if (kind === 'rows') {
            base.type = 'rows';
            base.count = Array.isArray(rec) ? rec.length : 0;
            base.openRows = (function (pf, parentPath) {
                return function () {
                    openRowsPanel({
                        level: ctx.level, section: ctx.section, parentField: pf,
                        path: parentPath, resourceId: rid()
                    }, fieldLabel(pf));
                };
            })(fname, ctx.path.slice());
            out.push(base);
        } else if (kind === 'image') {
            base.type = 'image';
            if (isImgRecord(rec)) {
                base.record = rec;
                base.value = lexValue(rec[0].src == null ? '' : String(rec[0].src), ctx.level);
            } else {
                base.value = lexValue(isScalar(v) ? (v == null ? '' : String(v)) : '', ctx.level);
            }
            out.push(base);
        } else if (kind === 'picture') {
            base.type = 'picture'; base.recordEditor = true; out.push(base);
        } else if (kind === 'video' || kind === 'audio') {
            base.type = 'media'; base.recordEditor = true; base.isVideo = (kind === 'video'); out.push(base);
        } else {
            if (!isScalar(v)) { return; } // не-скаляр, не распознан как запись/список — пропускаем
            base.type = S.typesMap[fname] || 'text';
            base.value = lexValue(v == null ? '' : String(v), ctx.level);
            out.push(base);
        }
    });
    return out;
}

// Превью строки = первое непустое скалярное под-поле (обрезанное).
function rowPreview(row, level) {
    var keys = Object.keys(row);
    for (var i = 0; i < keys.length; i++) {
        var k = keys[i];
        if (k === 'MIGX_id' || STRUCTURAL.indexOf(k) !== -1) { continue; }
        var v = row[k];
        if (typeof v === 'string' && v && !parseRecord(v)) {
            var t = String(lexValue(v, level));
            return t.length > 50 ? (t.slice(0, 50) + '…') : t;
        }
    }
    return '(строка)';
}

// Редактор строк MIGX-списка (config-driven). listAddr: {level,section,parentField,path,resourceId}.
export function openRowsPanel(listAddr, label) {
    var m = openModal({
        cardClass: 'mpcve-modal__card--wide',
        title: 'Строки списка · ' + label,
        bodyHtml: '<div class="mpcve-rows"></div>',
        actionsHtml:
            '<button type="button" class="mpcve-btn mpcve-btn--primary" data-act="add">+ Добавить строку</button>' +
            '<button type="button" class="mpcve-btn" data-act="reload" title="Перезагрузить страницу — увидеть рендер">Обновить</button>' +
            '<button type="button" class="mpcve-btn" data-act="close">Закрыть</button>',
        closeSelectors: ['[data-act=close]'],
        captureEsc: true
    });
    if (!m) { return; }
    var overlay = m.overlay;
    var close = m.close;
    var rowsBox = overlay.querySelector('.mpcve-rows');

    overlay.querySelector('[data-act=reload]').addEventListener('click', function () { window.location.reload(); });

    // row/op (адресный; path несёт спуск к родительской строке для вложенных).
    function rowOp(extra, btn) {
        var a = {
            type: 'row', section: listAddr.section, parentField: listAddr.parentField,
            level: listAddr.level, resourceId: listAddr.resourceId
        };
        if (listAddr.scope) { a.scope = listAddr.scope; }
        if (listAddr.path && listAddr.path.length) { a.path = listAddr.path; }
        Object.keys(extra).forEach(function (k) { a[k] = extra[k]; });
        if (btn) { btn.disabled = true; }
        return api.post('row/op', { address: a }).then(function (r) {
            if (!r || !r.success) {
                toast((r && r.message) || 'Ошибка операции со строкой', true);
                if (btn) { btn.disabled = false; }
                return false;
            }
            return loadConfig().then(function () { return true; }); // обновляем конфиг → корректный ререндер
        }).catch(function () {
            toast('Сетевая ошибка', true);
            if (btn) { btn.disabled = false; }
            return false;
        });
    }

    function render() {
        var rows = readRows(listAddr);
        rowsBox.innerHTML = rows.length
            ? rows.map(function (row, idx) {
                return '<div class="mpcve-rows__row" data-idx="' + idx + '">' +
                    '<span class="mpcve-rows__num">' + (idx + 1) + '</span>' +
                    '<span class="mpcve-rows__prev">' + esc(rowPreview(row, listAddr.level)) + '</span>' +
                    '<span class="mpcve-rows__act">' +
                        '<button type="button" class="mpcve-rows__btn" data-op="fields" title="Поля строки">✎</button>' +
                        '<button type="button" class="mpcve-rows__btn" data-op="up" title="Выше">↑</button>' +
                        '<button type="button" class="mpcve-rows__btn" data-op="down" title="Ниже">↓</button>' +
                        '<button type="button" class="mpcve-rows__btn mpcve-rows__btn--del" data-op="del" title="Удалить">✕</button>' +
                    '</span></div>';
            }).join('')
            : '<div class="mpcve-hpanel__empty">Строк пока нет — добавьте первую.</div>';
        wire(rows);
    }

    function wire(rows) {
        rowsBox.querySelectorAll('.mpcve-rows__row').forEach(function (rowEl) {
            var idx = parseInt(rowEl.getAttribute('data-idx'), 10);
            rowEl.querySelectorAll('[data-op]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var op = btn.getAttribute('data-op');
                    if (op === 'fields') {
                        var rowPath = (listAddr.path || []).concat([{ field: listAddr.parentField, idx: idx }]);
                        var descriptors = fieldDescriptors(rows[idx], {
                            level: listAddr.level, section: listAddr.section, path: rowPath
                        });
                        if (!descriptors.length) { toast('В строке нет редактируемых полей', true); return; }
                        close(); // форвардная навигация (стек модалок не держим)
                        openBlockPanel('Поля строки #' + (idx + 1) + ' · ' + label, descriptors);
                    } else if (op === 'del') {
                        confirmDialog('Удалить строку #' + (idx + 1) + '? Откат не предусмотрен.', {
                            title: 'Удаление строки', okLabel: 'Удалить', cancelLabel: 'Отмена', danger: true
                        }).then(function (yes) {
                            if (!yes) { return; }
                            rowOp({ op: 'delete', idx: idx }, btn).then(function (ok) { if (ok) { render(); } });
                        });
                    } else if (op === 'up' || op === 'down') {
                        var to = op === 'up' ? idx - 1 : idx + 1;
                        if (to < 0 || to >= rows.length) { return; }
                        rowOp({ op: 'move', fromIdx: idx, toIdx: to }, btn).then(function (ok) { if (ok) { render(); } });
                    }
                });
            });
        });
    }

    overlay.querySelector('[data-act=add]').addEventListener('click', function (e) {
        rowOp({ op: 'add' }, e.currentTarget).then(function (ok) {
            if (ok) { render(); toast('Строка добавлена'); }
        });
    });

    render();
}

// Регистрируем config-driven редактор строк в panels.js — чтобы скрытые MIGX-списки
// в per-block панели «Скрытые поля» тоже открывали его (избегаем цикл-импорта).
_setRowsOpener(openRowsPanel);

// Точка входа из сайдбара: панель полей секции (s — объект секции из S.configData).
export function openSectionFields(s) {
    var name = s.section_name || s.MIGX_formname || '';
    var sc = sectionContent(name, boolOf(s.is_static));
    if (!sc) { toast('Секция не найдена в конфиге', true); return; }
    var descriptors = fieldDescriptors(sc.obj, { level: sc.level, section: sc.section, path: [] });
    if (!descriptors.length) { toast('У секции нет редактируемых контент-полей', true); return; }
    openBlockPanel('Поля секции «' + name + '»', descriptors);
}
