/**
 * mpcVisualEditor — скрытые поля У БЛОКА (config-driven). Поля, вырезанные из
 * страницы (data-mpc-remove) или вспомогательные, не имеют DOM-маркера, но лежат
 * в mpc_config. Вешаем на КАЖДЫЙ блок (секцию / строку списка) с такими полями
 * кнопку-триггер; клик открывает панель ЭТОГО блока. Запись — field/save.
 */
import { S } from './state.js';
import { api } from './api.js';
import { esc, parseRecord, isScalar, fieldLabel, toast } from './dom.js';
import { SECTION_STYLE_FIELDS, STRUCTURAL } from './constants.js';
import { findSectionInLevel, sectionConfig } from './address.js';

// Значение лексикона по ключу (в режиме лексиконов конфиг хранит КЛЮЧ, перевод —
// в файле). Показываем перевод, а не ключ. Если v не ключ (или лексиконы выкл) —
// возвращаем как есть. Карты приходят из config/get (S.lexicons по уровням).
function lexValue(v, level) {
    if (typeof v !== 'string') { return v; }
    var map = (S.lexicons && S.lexicons[level]) || null;
    return (map && Object.prototype.hasOwnProperty.call(map, v)) ? map[v] : v;
}

// Поле принадлежит табу «Настройки секции» (или служебное/стилевое) → не в панель
// контентных скрытых. STRUCTURAL — служебные + css_file_path + стили (стили
// показываем отдельной веткой); S.settingsFields — таб «Настройки» из mpc_base.
function isSettingsField(fname) {
    return STRUCTURAL.indexOf(fname) !== -1 || S.settingsFields.indexOf(fname) !== -1;
}

// Стилевые поля секции (вкладка «Стили» mpc_base, кроме css_file_path).
// Показываем ВСЕГДА (даже пустые — чтобы можно было ЗАДАТЬ ещё не существующее
// значение, напр. CSS-класс для оформления).
//
// ВАЖНО (каскад): стилевые поля — каскад-поля mpc, на рендере РЕСУРС-уровень
// перекрывает global (см. Render::applyResourceCascade). Поэтому правим их на
// уровне РЕСУРСА (текущая страница), если секция есть в ресурс-конфиге; иначе
// global. Иначе для static-секции правка в global маскируется ресурсным
// переопределением — и «не обновляется» на странице.
function sectionStyleFields(sectionEl) {
    var name = sectionEl.getAttribute('data-mpc-section') || '';
    var sc = findSectionInLevel(name, 'resource') || findSectionInLevel(name, 'global');
    if (!sc) { return []; }
    return SECTION_STYLE_FIELDS.map(function (fname) {
        var value = sc.obj[fname];
        return {
            level: sc.level, section: sc.section,
            fieldName: fname, value: lexValue(value == null ? '' : String(value), sc.level),
            type: S.typesMap[fname] || 'text', label: fieldLabel(fname)
        };
    });
}

// Имена ВИДИМЫХ top-level полей секции (есть DOM-маркер прямо в этой секции).
// Контейнер списка несёт data-mpc-field → имя списка тоже «видимо». Под-поля
// строк (data-mpc-field-N) сюда не попадают — это не top-level ключи конфига.
function visibleSectionFields(sectionEl) {
    var seen = {};
    ['data-mpc-field', 'data-mpc-rfield', 'data-mpc-tv'].forEach(function (attr) {
        sectionEl.querySelectorAll('[' + attr + ']').forEach(function (el) {
            if (el.closest('[data-mpc-section]') === sectionEl) {
                seen[el.getAttribute(attr)] = true;
            }
        });
    });
    return seen;
}

// Скрытые поля СЕКЦИИ = стилевые поля (всегда) + скрытые КОНТЕНТНЫЕ поля.
// Контентные: ключи config-объекта секции (контент-уровень: static→global,
// иначе resource) минус «Настройки» + служебные + css_file_path + стили
// (= STRUCTURAL) минус ВИДИМЫЕ (с DOM-маркером). v1: только скаляры —
// скрытые migx-списки/медиа пропускаем (нет DOM-контейнера для редактора).
function sectionHidden(sectionEl) {
    var out = sectionStyleFields(sectionEl);
    var sc = sectionConfig(sectionEl); // контент-уровень: static→global, иначе resource
    if (sc) {
        var visible = visibleSectionFields(sectionEl);
        Object.keys(sc.obj).forEach(function (fname) {
            if (isSettingsField(fname) || visible[fname]) { return; }
            var v = sc.obj[fname];
            if (!isScalar(v) || parseRecord(v)) { return; }
            out.push({
                level: sc.level, section: sc.section,
                fieldName: fname, value: lexValue(v == null ? '' : String(v), sc.level),
                type: S.typesMap[fname] || 'text', label: fieldLabel(fname)
            });
        });
    }
    return out;
}

// Инфо строки списка по её DOM-элементу (level-1 item): section/level/obj/parentField/idx.
function itemInfo(itemEl) {
    var sc = sectionConfig(itemEl.closest('[data-mpc-section]'));
    if (!sc) { return null; }
    var listEl = itemEl.closest('[data-mpc-field]'); // контейнер списка (предок)
    if (!listEl) { return null; }
    var idx = 0, sib = itemEl.previousElementSibling;
    while (sib) {
        if (sib.hasAttribute('data-mpc-item')) { idx++; }
        sib = sib.previousElementSibling;
    }
    return {
        section: sc.section, level: sc.level, obj: sc.obj,
        parentField: listEl.getAttribute('data-mpc-field'), idx: idx
    };
}

// Имена под-полей строки, имеющих DOM-маркер внутри ЭТОГО item (level-1).
function visibleItemSubs(itemEl) {
    var seen = {};
    itemEl.querySelectorAll('[data-mpc-field-1]').forEach(function (el) {
        if (el.closest('[data-mpc-item]') === itemEl) {
            seen[el.getAttribute('data-mpc-field-1')] = true;
        }
    });
    return seen;
}

// Скрытые скалярные под-поля строки списка (level-1) + её инфо.
function itemHidden(itemEl) {
    var info = itemInfo(itemEl);
    if (!info) { return null; }
    var rows = parseRecord(info.obj[info.parentField]);
    var row = rows && rows[info.idx];
    if (!row) { return null; }
    var vis = visibleItemSubs(itemEl);
    var out = [];
    Object.keys(row).forEach(function (sub) {
        if (sub === 'MIGX_id' || STRUCTURAL.indexOf(sub) !== -1 || vis[sub]) { return; }
        var sv = row[sub];
        if (!isScalar(sv) || parseRecord(sv)) { return; }
        out.push({
            level: info.level, section: info.section,
            parentField: info.parentField, idx: info.idx,
            fieldName: sub, value: lexValue(sv == null ? '' : String(sv), info.level),
            type: S.typesMap[sub] || 'text', label: fieldLabel(sub)
        });
    });
    return { info: info, fields: out };
}

// --- триггеры у блоков + панель ----------------------------------------
function attachTrigger(blockEl, title, descriptors, btnText) {
    // Якорим кнопку абсолютно в углу блока; если блок position:static —
    // временно делаем relative (откатываем в removeHiddenTriggers).
    if (window.getComputedStyle(blockEl).position === 'static') {
        blockEl.style.position = 'relative';
        blockEl.setAttribute('data-mpcve-posfix', '1');
    }
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'mpcve-hidden-trigger';
    btn.textContent = btnText || ('⊕ ' + descriptors.length);
    btn.title = title;
    btn.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        openBlockPanel(title, descriptors);
    });
    blockEl.appendChild(btn);
}

export function buildHiddenTriggers() {
    removeHiddenTriggers();
    if (!S.configData) {
        console.warn('[mpcVE] скрытые поля: конфиг не загружен (config/get). Кнопок не будет.');
        return;
    }
    var sections = document.querySelectorAll('[data-mpc-section]');
    var items = document.querySelectorAll('[data-mpc-item]');
    var triggers = 0;
    sections.forEach(function (sectionEl) {
        var fields = sectionHidden(sectionEl);
        if (fields.length) {
            attachTrigger(sectionEl, 'Скрытые поля секции «' + sectionEl.getAttribute('data-mpc-section') + '»', fields);
            triggers++;
        }
    });
    items.forEach(function (itemEl) {
        var h = itemHidden(itemEl);
        if (h && h.fields.length) {
            attachTrigger(itemEl, 'Скрытые поля строки ' + h.info.parentField + ' #' + (h.info.idx + 1), h.fields);
            triggers++;
        }
    });
    console.info('[mpcVE] скрытые поля: секций в DOM=' + sections.length +
        ', строк списков=' + items.length + ', кнопок навешено=' + triggers +
        (sections.length === 0 ? ' — нет data-mpc-section: проверь mpc_edit_mode (маркеры в рендере)' : ''));
}

export function removeHiddenTriggers() {
    document.querySelectorAll('.mpcve-hidden-trigger').forEach(function (b) { b.remove(); });
    document.querySelectorAll('[data-mpcve-posfix]').forEach(function (el) {
        el.style.position = '';
        el.removeAttribute('data-mpcve-posfix');
    });
}

// Контрол по ТИПУ поля (тип решает, как поле выглядит в панели):
//   image    — превью + загрузка файла (значение = путь);
//   richtext — contenteditable (форматирование видно), значение = innerHTML;
//   иначе    — input / textarea (длинное/многострочное → textarea).
function controlHtml(f) {
    if (f.type === 'image') {
        return '<div class="mpcve-hpanel__img">' +
                 '<div class="mpcve-hpanel__thumb"></div>' +
                 '<label class="mpcve-pic__pick">Заменить<input type="file" accept="image/*" hidden></label>' +
               '</div>';
    }
    if (f.type === 'richtext') {
        return '<div class="mpcve-hpanel__rte" contenteditable="true" spellcheck="false"></div>';
    }
    var multiline = f.type === 'textarea' || f.value.length > 80 || f.value.indexOf('\n') !== -1;
    return multiline
        ? '<textarea>' + esc(f.value) + '</textarea>'
        : '<input type="text" value="' + esc(f.value) + '">';
}

function rowHtml(f, idx) {
    return '<div class="mpcve-hpanel__row" data-i="' + idx + '">' +
        '<div class="mpcve-hpanel__label">' + esc(f.label) + '</div>' +
        '<div class="mpcve-hpanel__ctrl">' + controlHtml(f) +
        '<button type="button" class="mpcve-btn mpcve-btn--primary" data-act="save">Сохранить</button>' +
        '</div></div>';
}

// Привязывает контрол строки по типу и возвращает getValue() → значение к записи.
function wireControl(rowEl, f, btn) {
    if (f.type === 'image') {
        var box = rowEl.querySelector('.mpcve-hpanel__img');
        var thumb = box.querySelector('.mpcve-hpanel__thumb');
        var fileInput = box.querySelector('input[type=file]');
        var curUrl = f.value || '';
        var draw = function () {
            thumb.innerHTML = curUrl ? '<img alt="">' : '<span class="mpcve-modal__empty">нет</span>';
            if (curUrl) { thumb.querySelector('img').src = curUrl; }
        };
        draw();
        fileInput.addEventListener('change', function () {
            var file = fileInput.files[0];
            if (!file || file.type.indexOf('image/') !== 0) { return; }
            btn.disabled = true; btn.textContent = '⇧';
            api.upload('image/upload', file).then(function (res) {
                if (res && res.success && res.data && res.data.url) { curUrl = res.data.url; draw(); }
                else { toast((res && res.message) || 'Ошибка загрузки', true); }
            }).catch(function () { toast('Сетевая ошибка', true); })
              .then(function () { btn.disabled = false; btn.textContent = 'Сохранить'; });
        });
        return function () { return curUrl; };
    }
    if (f.type === 'richtext') {
        var rte = rowEl.querySelector('.mpcve-hpanel__rte');
        rte.innerHTML = f.value;
        return function () { return rte.innerHTML; };
    }
    var ctrl = rowEl.querySelector('input, textarea');
    return function () { return ctrl.value; };
}

function openBlockPanel(title, descriptors) {
    if (document.querySelector('.mpcve-modal')) { return; }
    var body = descriptors.map(function (f, i) { return rowHtml(f, i); }).join('');

    var overlay = document.createElement('div');
    overlay.className = 'mpcve-modal';
    overlay.innerHTML =
        '<div class="mpcve-modal__card mpcve-modal__card--wide">' +
            '<div class="mpcve-modal__head">Скрытые поля · ' + esc(title) + '</div>' +
            '<div class="mpcve-hpanel">' + body + '</div>' +
            '<div class="mpcve-modal__actions">' +
                '<button type="button" class="mpcve-btn" data-act="close">Закрыть</button>' +
            '</div>' +
        '</div>';
    document.body.appendChild(overlay);

    function close() { overlay.remove(); document.removeEventListener('keydown', onKey); }
    function onKey(e) { if (e.key === 'Escape') { close(); } }
    document.addEventListener('keydown', onKey);
    overlay.addEventListener('click', function (e) { if (e.target === overlay) { close(); } });
    overlay.querySelector('[data-act=close]').addEventListener('click', close);

    overlay.querySelectorAll('.mpcve-hpanel__row').forEach(function (rowEl) {
        var f = descriptors[parseInt(rowEl.getAttribute('data-i'), 10)];
        var btn = rowEl.querySelector('[data-act=save]');
        var getValue = wireControl(rowEl, f, btn);
        btn.addEventListener('click', function () {
            var addr = {
                type: 'field', level: f.level, section: f.section,
                fieldName: f.fieldName, resourceId: S.cfg.resourceId || 0
            };
            if (f.parentField != null) { addr.parentField = f.parentField; addr.idx = f.idx; }
            var value = getValue();
            btn.disabled = true; btn.textContent = '…';
            api.post('field/save', { address: addr, value: value }).then(function (r) {
                if (r && r.success) {
                    f.value = value;
                    rowEl.classList.add('mpcve-hpanel__row--saved');
                    btn.textContent = '✓';
                    setTimeout(function () { btn.textContent = 'Сохранить'; btn.disabled = false; }, 1200);
                } else {
                    toast((r && r.message) || 'Ошибка сохранения', true);
                    btn.textContent = 'Сохранить'; btn.disabled = false;
                }
            }).catch(function () {
                toast('Сетевая ошибка', true);
                btn.textContent = 'Сохранить'; btn.disabled = false;
            });
        });
    });
}
