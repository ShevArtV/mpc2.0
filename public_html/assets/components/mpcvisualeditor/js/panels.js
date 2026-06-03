/**
 * mpcVisualEditor — скрытые поля У БЛОКА (config-driven). Поля, вырезанные из
 * страницы (data-mpc-remove) или вспомогательные, не имеют DOM-маркера, но лежат
 * в mpc_config. Вешаем на КАЖДЫЙ блок (секцию / строку списка) с такими полями
 * кнопку-триггер; клик открывает панель ЭТОГО блока. Запись — field/save.
 */
import { S } from './state.js';
import { api, uploadMedia } from './api.js';
import { esc, parseRecord, isScalar, fieldLabel, toast } from './dom.js';
import { SECTION_STYLE_FIELDS, STRUCTURAL } from './constants.js';
import { findSectionInLevel, sectionConfig } from './address.js';
import { createRte, sanitizeHtml } from './editors/rte.js';
import { openPictureEditor } from './editors/picture.js';
import { openMediaEditor } from './editors/media.js';

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

// Одиночная img-ЗАПИСЬ [{MIGX_id,src,alt,title,width,height}] — есть src, НЕТ
// sources/img (так отличаем от picture/video/audio-записей со sources/вложенным
// img, которые панель пока не редактирует). Такие записи правим value-based
// (превью+загрузка), сохраняя структуру записи.
function isImgRecord(rec) {
    return !!(rec && rec.length === 1 && rec[0] &&
        rec[0].src !== undefined && rec[0].sources === undefined && rec[0].img === undefined);
}

// Тип media-ЗАПИСИ (picture/video/audio) по форме первой строки — для открытия
// нужного редактора value-based из панели. null — не такая запись.
function recordKind(rec) {
    if (!Array.isArray(rec) || !rec.length || !rec[0] || typeof rec[0] !== 'object') { return null; }
    var r = rec[0];
    var src0 = (Array.isArray(r.sources) && r.sources.length) ? r.sources[0] : null;
    if (r.img !== undefined) { return 'picture'; }                 // вложенный <img>
    if (src0 && src0.srcset !== undefined) { return 'picture'; }   // <source srcset>
    if (src0 && src0.src !== undefined) {                          // <source src> → video/audio
        return (r.poster !== undefined || r.width !== undefined || r.height !== undefined) ? 'video' : 'audio';
    }
    return null;
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
            var rec = parseRecord(v);
            if (isImgRecord(rec)) {
                out.push({
                    level: sc.level, section: sc.section, fieldName: fname,
                    type: 'image', record: rec, label: fieldLabel(fname),
                    value: lexValue(rec[0].src == null ? '' : String(rec[0].src), sc.level)
                });
                return;
            }
            var kind = recordKind(rec);
            if (kind) {
                // picture/video/audio-запись → кнопка-открыватель полного редактора.
                out.push({
                    level: sc.level, section: sc.section, fieldName: fname,
                    type: (kind === 'picture') ? 'picture' : 'media',
                    isVideo: (kind === 'video'), recordEditor: true, label: fieldLabel(fname)
                });
                return;
            }
            if (!isScalar(v) || rec) { return; } // прочие записи/не-скаляры пока пропускаем
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
        var rec = parseRecord(sv);
        if (isImgRecord(rec)) {
            out.push({
                level: info.level, section: info.section,
                parentField: info.parentField, idx: info.idx,
                fieldName: sub, type: 'image', record: rec, label: fieldLabel(sub),
                value: lexValue(rec[0].src == null ? '' : String(rec[0].src), info.level)
            });
            return;
        }
        if (!isScalar(sv) || rec) { return; } // прочие записи/не-скаляры пока пропускаем
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
    if (f.recordEditor) {
        // picture/video/audio-запись — кнопка открытия полного редактора (value-based).
        return '<button type="button" class="mpcve-btn" data-rec-edit="1">✎ Редактировать ' +
            (f.type === 'picture' ? 'картинку' : 'медиа') + '</button>';
    }
    if (f.type === 'image') {
        return '<div class="mpcve-hpanel__img">' +
                 '<div class="mpcve-hpanel__thumb"></div>' +
                 '<label class="mpcve-pic__pick">Заменить<input type="file" accept="image/*" hidden></label>' +
               '</div>';
    }
    if (f.type === 'richtext') {
        // RTE через реестр (createRte при wire); тулбар из allowedTags. Хост — пустой.
        return '<div class="mpcve-hpanel__rtebox"></div>';
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
        // У record-редактора своя кнопка-открыватель; общий «Сохранить» не нужен.
        (f.recordEditor ? '' : '<button type="button" class="mpcve-btn mpcve-btn--primary" data-act="save">Сохранить</button>') +
        '</div></div>';
}

// Привязывает контрол строки по типу и возвращает getValue() → значение к записи.
function wireControl(rowEl, f, btn) {
    if (f.type === 'image') {
        var box = rowEl.querySelector('.mpcve-hpanel__img');
        var thumb = box.querySelector('.mpcve-hpanel__thumb');
        var fileInput = box.querySelector('input[type=file]');
        var curUrl = f.value || '';  // путь/URL для превью (для записи — резолвнутый src)
        var changed = false;         // загрузили ли новый файл
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
                if (res && res.success && res.data && res.data.url) { curUrl = res.data.url; changed = true; draw(); }
                else { toast((res && res.message) || 'Ошибка загрузки', true); }
            }).catch(function () { toast('Сетевая ошибка', true); })
              .then(function () { btn.disabled = false; btn.textContent = 'Сохранить'; });
        });
        return function () {
            // img-ЗАПИСЬ: сохраняем структуру записи, меняем только src. Если не
            // грузили — оставляем исходный src (лексикон-ключ → бэк его не трогает).
            if (f.record) {
                var row0 = {};
                Object.keys(f.record[0]).forEach(function (k) { row0[k] = f.record[0][k]; });
                if (changed) { row0.src = curUrl; }
                return JSON.stringify([row0]);
            }
            return curUrl;  // путь-строка (bg_img и т.п.)
        };
    }
    if (f.type === 'richtext') {
        var inst = createRte(rowEl.querySelector('.mpcve-hpanel__rtebox'), {
            value: f.value,
            allowedTags: S.cfg.allowedTags,
            upload: function (file) { return uploadMedia(file, 'image'); }
        });
        return function () { return sanitizeHtml(inst.getHTML(), S.cfg.allowedTags); };
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
        // picture/video/audio-ЗАПИСЬ → открываем полноценный редактор value-based.
        // Панель — сама .mpcve-modal, поэтому СНАЧАЛА закрываем её (иначе guard
        // редактора заблокирует открытие), затем открываем редактор.
        if (f.recordEditor) {
            rowEl.querySelector('[data-rec-edit]').addEventListener('click', function () {
                close();
                var raddr = {
                    type: 'field', level: f.level, section: f.section,
                    fieldName: f.fieldName, resourceId: S.cfg.resourceId || 0
                };
                if (f.type === 'picture') { openPictureEditor(null, { addr: raddr }); }
                else { openMediaEditor(null, { addr: raddr, isVideo: !!f.isVideo }); }
            });
            return;
        }
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
