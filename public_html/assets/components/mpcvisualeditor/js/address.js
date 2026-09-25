/**
 * mpcVisualEditor — адресация полей из DOM («one address space») + поиск записей
 * в конфиге (источник правды для лексикон-ключей и числа строк).
 */
import { S } from './state.js';
import { isMedia, hasBg, parseRecord } from './dom.js';
import { hasMpc, mpcAttr, mpcAttrName, mpcSel, closestMpc, fieldLevelOf } from './constants.js';

// Тип редактора для стандартных полей ресурса MODX (rfield). Не перечисленные
// (pagetitle/longtitle/menutitle/…) → text по умолчанию. data-mpc-ftype на
// маркере переопределяет (проверяется раньше в editorTypeFor).
var RFIELD_TYPES = {
    content: 'richtext',
    introtext: 'textarea',
    description: 'textarea'
};

// Ключ секции для адресации в конфиге. data-mpc-name — имя ЭТОЙ записи конфига
// (section_name, уникально), data-mpc-section — имя ТИПА секции (MIGX_formname,
// общее у копий: три «card_grid» на странице). Берём первое, иначе правки всех
// копий уходили бы в первую запись. Сервер матчит section_name || MIGX_formname,
// поэтому оба значения адресуют корректно, а старый mpc (без подстановки имени
// в рендере) просто продолжает работать по прежнему ключу.
export function sectionKeyOf(sectionEl) {
    if (!sectionEl || !sectionEl.getAttribute) { return ''; }
    return sectionEl.getAttribute('data-mpc-name')
        || sectionEl.getAttribute('data-mpc-section')
        || '';
}

// --- адрес поля из DOM -------------------------------------------------
export function resolveAddress(el) {
    var type = null, fieldName = null;
    // Произвольный лексиконный ключ: data-mpc-lexicon="topic:key" (топик опционален,
    // двоеточие — разделитель). Привязки к секции/ресурсу/полю нет — топик и ключ
    // самодостаточны для записи (FieldWriter type=lexicon).
    // На секции data-mpc-lexicon = префикс лексикона секции, не произвольный ключ.
    if (el.hasAttribute('data-mpc-lexicon') && !el.hasAttribute('data-mpc-section')) {
        var raw = (el.getAttribute('data-mpc-lexicon') || '').trim();
        var topic = '', key = raw;
        var ci = raw.indexOf(':');
        if (ci !== -1) {
            topic = raw.slice(0, ci).trim();
            key = raw.slice(ci + 1).trim();
        }
        if (!key) { return null; }
        return { type: 'lexicon', fieldName: key, topic: topic, key: key };
    }
    if (el.hasAttribute('data-mpc-rfield')) {
        type = 'rfield';
        fieldName = el.getAttribute('data-mpc-rfield');
    } else if (el.hasAttribute('data-mpc-tv')) {
        type = 'tv';
        fieldName = el.getAttribute('data-mpc-tv');
    } else {
        for (var i = 0; i < el.attributes.length; i++) {
            var a = el.attributes[i];
            if (fieldLevelOf(a.name) >= 0) { // data-mpc(ve)-field / -field-N
                type = 'field';
                fieldName = a.value;
                break;
            }
        }
    }
    if (!type) {
        return null;
    }
    return { type: type, fieldName: fieldName };
}

export function fieldAddress(el) {
    var addr = resolveAddress(el);
    if (!addr) {
        return null;
    }
    // Произвольный лексикон: адрес самодостаточен (topic/key), секция/уровень/
    // path не нужны — язык сервер берёт из cookie mpc_lang.
    if (addr.type === 'lexicon') {
        addr.resourceId = S.cfg.resourceId || 0;
        return addr;
    }
    var sectionEl = el.closest('[data-mpc-section]');
    addr.section = sectionKeyOf(sectionEl);
    addr.level = (sectionEl && sectionEl.hasAttribute('data-mpc-static')) ? 'global' : 'resource';
    addr.resourceId = S.cfg.resourceId || 0;
    applySectionScope(addr, !!(sectionEl && sectionEl.hasAttribute('data-mpc-static')));

    // Кросс-ресурс: обёртка data-mpc-res="<id>" (поле другого ресурса,
    // выведенного сниппетом) → пишем в ТОТ ресурс, а не в текущую страницу.
    var resEl = el.closest('[data-mpc-res]');
    if (resEl) {
        var rid = parseInt(resEl.getAttribute('data-mpc-res'), 10);
        if (rid > 0) {
            addr.resourceId = rid;
        }
    }

    // Вложенное поле строки списка: data-mpc(ve)-field-N. Собираем ПОЛНЫЙ путь
    // [{field,idx}, …] от секции к строке (вложенность любой глубины), т.к.
    // поле уровня 2 лежит на 2 уровня глубже: cfg[sec][L1][i][L2][j][field].
    var lvl = 0;
    for (var i = 0; i < el.attributes.length; i++) {
        lvl = fieldLevelOf(el.attributes[i].name);
        if (lvl > 0) { break; }
    }
    if (lvl > 0) {
        var path = buildRowPath(el, lvl);
        if (path && path.length) {
            addr.path = path;
            // back-compat: ближайший (самый глубокий) уровень → parentField/idx
            var deepest = path[path.length - 1];
            addr.parentField = deepest.field;
            addr.idx = deepest.idx;
        }
    }
    return addr;
}

// «Призрачные» строки: копии, которые вставляют в тот же контейнер сторонние
// скрипты (Swiper в режиме loop, slick). Несут те же data-mpc-* и потому сдвигают
// подсчёт индексов. Селектор переопределяется настройкой mpcve_row_ignore_selectors.
export var GHOST_ROW_SEL = '.swiper-slide-duplicate, .slick-cloned';

export function isGhostRow(el) {
    if (!el || !el.matches) { return false; }
    var sel = (S.cfg && S.cfg.rowIgnoreSelectors) || GHOST_ROW_SEL;
    try { return el.matches(sel); } catch (e) { return false; }
}

// Индекс строки в списке. Считать соседей «как есть» нельзя: клон слайда перед
// первой строкой смещает весь список на +1, и правка уходит в соседнюю строку.
// Swiper проставляет каждому слайду data-swiper-slide-index — это индекс
// ОРИГИНАЛА, верный и на клоне; поэтому он в приоритете. Фолбэк — счёт соседей
// без призраков (для клона slick, у которого нет своего индекса, остаётся
// неточность — он адресуется как ближайший оригинал).
// itemName — имя маркера строки без префикса: 'item' | 'item-N'.
export function rowIndexOf(itemEl, itemName) {
    var si = itemEl.getAttribute ? itemEl.getAttribute('data-swiper-slide-index') : null;
    if (si !== null && si !== '' && !isNaN(parseInt(si, 10))) { return parseInt(si, 10); }
    var idx = 0, sib = itemEl.previousElementSibling;
    while (sib) {
        if (hasMpc(sib, itemName) && !isGhostRow(sib)) { idx++; }
        sib = sib.previousElementSibling;
    }
    return idx;
}

// Путь [{field,idx}, …] от секции к строке для поля уровня lvl (field-lvl).
// Уровень N: ряд = item-(N-1) (item для N=1), контейнер списка = field-(N-1)
// (field для N=1). Префикс каждого звена любой: data-mpc-* или data-mpcve-*.
export function buildRowPath(el, lvl) {
    var path = [];
    var base = el;
    for (var L = lvl; L >= 1; L--) {
        var itemName = L > 1 ? 'item-' + (L - 1) : 'item';
        var listName = L > 1 ? 'field-' + (L - 1) : 'field';
        var itemEl = closestMpc(base, itemName);
        if (!itemEl) { return null; }
        var listEl = closestMpc(itemEl, listName);
        if (!listEl || listEl === itemEl) { return null; }
        path.unshift({ field: mpcAttr(listEl, listName), idx: rowIndexOf(itemEl, itemName) });
        base = listEl;
    }
    return path;
}

// --- выбор типа редактора ----------------------------------------------
// Тип редактора по значению data-mpc-ftype (имя типа-прототипа mpc_base).
export function ftypeToEditor(ftype) {
    if (!ftype) { return ''; }
    if (ftype === 'richtext') { return 'richtext'; }   // модалка RTE
    if (ftype === 'textarea') { return 'textarea'; }   // модалка textarea
    if (ftype === 'img' || ftype === 'bg_img') { return 'image'; }
    if (ftype === 'picture') { return 'picture'; }
    if (ftype === 'video' || ftype === 'audio') { return 'media'; }
    if (ftype === 'listbox') { return 'listbox'; }
    if (ftype === 'listbox-multiple') { return 'listbox-multiple'; }
    if (ftype === 'option') { return 'option'; }       // радио (одиночный)
    if (ftype === 'checkbox') { return 'checkbox'; }   // чекбоксы (множественный)
    if (ftype === 'number') { return 'number'; }
    if (ftype === 'date') { return 'date'; }
    if (ftype === 'color' || ftype === 'colorpicker') { return 'color'; }
    if (ftype === 'tag' || ftype === 'tags' || ftype === 'autotag') { return 'tags'; }
    if (ftype === 'file') { return 'file'; }
    if (ftype.indexOf('list') === 0) { return 'rows'; }
    return 'text'; // text/email/url — инлайн-текст
}

// Маркер списка + его уровень вложенности (0 = top-level field, N = field-N).
// attr — реальное имя атрибута на el (data-mpc-* или data-mpcve-*), name — без
// префикса. Ряды списка уровня N помечены item-N (item для top). null — не список.
export function listFieldAttr(el) {
    for (var n = 0; n <= 3; n++) {
        var name = n > 0 ? 'field-' + n : 'field';
        var attr = mpcAttrName(el, name);
        if (attr) {
            return { attr: attr, name: name, lvl: n };
        }
    }
    return null;
}

// Имя маркера строк списка уровня lvl (без префикса): item | item-N.
export function itemAttrForLevel(lvl) {
    return lvl > 0 ? 'item-' + lvl : 'item';
}

// Контейнер-список? = есть СВОИ строки (data-mpc-item уровня этого поля).
// Пустой список (0 строк) детектится по data-mpc-ftype="list*" (ftypeToEditor),
// см. editorTypeFor — поэтому здесь только непустые.
export function isListEl(el) {
    var fa = listFieldAttr(el);
    if (!fa) {
        return !!(el.querySelector && el.querySelector(mpcSel('item')));
    }
    return !!(el.querySelector && el.querySelector(mpcSel(itemAttrForLevel(fa.lvl))));
}

export function editorTypeFor(el, addr) {
    // Произвольный лексикон: инлайн-правка содержимого (текст/HTML). Автор может
    // переопределить редактор через data-mpc-ftype (textarea/richtext); иначе text.
    if (addr && addr.type === 'lexicon') {
        return ftypeToEditor(mpcAttr(el, 'ftype')) || 'text';
    }
    // Маркер НА самом теге <a>/<link> → каттер кладёт плейсхолдер в href
    // (Cutter.php), значит значение поля — это АДРЕС ссылки. Правим href
    // (редактор link), а не текст. Текст ссылки правится отдельным маркером
    // на элементе ВНУТРИ <a>. Сигнал тега детерминирован — приоритет над ftype.
    var tag = el.tagName ? el.tagName.toLowerCase() : '';
    if (tag === 'a' || tag === 'link') {
        return 'link';
    }
    // Тип, заявленный автором через data-mpc-ftype (в edit-mode маркеры
    // сохраняются), — самый точный сигнал, важнее карты mpc_base.
    var byFtype = ftypeToEditor(mpcAttr(el, 'ftype'));
    if (byFtype) {
        return byFtype;
    }
    // Структурный список без ftype (динамический) → редактор строк.
    if (isListEl(el)) {
        return 'rows';
    }
    // Тип берём из карты, СООТВЕТСТВУЮЩЕЙ типу адреса (иначе TV/rfield ловили тип
    // одноимённого config-поля — коллизия имён): tv → своя карта типов TV;
    // rfield → стандартные типы ресурс-полей MODX; field → карта mpc_base.
    var mapped = '';
    if (addr.fieldName) {
        if (addr.type === 'tv') {
            mapped = S.tvTypes[addr.fieldName] || '';
        } else if (addr.type === 'rfield') {
            mapped = RFIELD_TYPES[addr.fieldName] || '';
        } else {
            mapped = S.typesMap[addr.fieldName] || '';
        }
    }
    // Явный не-картиночный тип из mpc_base (richtext/media/rows) — приоритет.
    if (mapped && mapped !== 'text' && mapped !== 'image') {
        return mapped;
    }
    if (isMedia(el)) {
        var t = el.tagName.toLowerCase();
        if (t === 'picture') { return 'picture'; } // главный img + источники
        return t === 'img' ? 'image' : 'media';
    }
    // Картинка по типу поля ИЛИ фон через inline style (data-mpc-field + style).
    if (mapped === 'image' || hasBg(el)) {
        return 'image';
    }
    return 'text';
}

// --- адрес и ряды списка -----------------------------------------------
// Адрес списка для row-операций. Для ВЛОЖЕННОГО списка (data-mpc-field-N) добавляет
// path — спуск к строке-контейнеру [{field,idx},…] (buildRowPath по уровню N);
// parentField — имя самого списка. Top-level — без path.
export function listAddress(listEl) {
    var sectionEl = listEl.closest('[data-mpc-section]');
    var rid = S.cfg.resourceId || 0;
    var resEl = listEl.closest('[data-mpc-res]');
    if (resEl) {
        var r = parseInt(resEl.getAttribute('data-mpc-res'), 10);
        if (r > 0) { rid = r; }
    }
    var fa = listFieldAttr(listEl);
    var addr = {
        section: sectionKeyOf(sectionEl),
        parentField: fa ? (listEl.getAttribute(fa.attr) || '') : '',
        level: (sectionEl && sectionEl.hasAttribute('data-mpc-static')) ? 'global' : 'resource',
        resourceId: rid
    };
    applySectionScope(addr, !!(sectionEl && sectionEl.hasAttribute('data-mpc-static')));
    if (fa && fa.lvl > 0) {
        var path = buildRowPath(listEl, fa.lvl); // спуск к родительской строке
        if (path && path.length) { addr.path = path; }
    }
    return addr;
}

export function rowPreview(itemEl) {
    var img = (itemEl.tagName && itemEl.tagName.toLowerCase() === 'img')
        ? itemEl
        : (itemEl.querySelector ? itemEl.querySelector('img') : null);
    if (img) {
        return img.getAttribute('alt') || (img.getAttribute('src') || '').split('/').pop() || '(медиа)';
    }
    var t = (itemEl.textContent || '').replace(/\s+/g, ' ').trim();
    return t.length > 50 ? (t.slice(0, 50) + '…') : (t || '(пусто)');
}

// Ряды списка: ПРЯМЫЕ строки контейнера (data-mpc-item уровня этого поля —
// data-mpc-item для top, data-mpc-item-N для вложенного), отфильтрованные так,
// чтобы не захватить строки более глубоких списков. ИЛИ медиа-список
// (повторяющиеся одноимённые соседи img/picture/video/audio — у них нет item).
export function listRows(el, field) {
    var fa = listFieldAttr(el);
    var itemName = itemAttrForLevel(fa ? fa.lvl : 0);
    var listName = fa ? fa.name : 'field';
    // Клоны слайдеров отбрасываем: иначе строк «больше», чем в конфиге, а порядок
    // для add/move/delete не совпадает с данными (см. isGhostRow).
    var items = Array.prototype.slice.call(el.querySelectorAll(mpcSel(itemName)))
        .filter(function (it) { return closestMpc(it, listName) === el && !isGhostRow(it); });
    if (!items.length && isMedia(el) && el.parentElement) {
        items = Array.prototype.slice.call(el.parentElement.children).filter(function (c) {
            return mpcAttr(c, 'field') === field && !isGhostRow(c);
        });
    }
    return items;
}

// Число строк поля-списка в КОНФИГЕ (источник правды). null если не нашли.
export function configRowCount(addr) {
    if (!S.configData) { return null; }
    var levelCfg = S.configData[addr.level] || {};
    var keys = Object.keys(levelCfg);
    for (var i = 0; i < keys.length; i++) {
        var s = levelCfg[keys[i]];
        if (s && (s.section_name === addr.section || s.MIGX_formname === addr.section)) {
            var rows = parseRecord(s[addr.parentField]);
            return rows ? rows.length : 0;
        }
    }
    return null;
}

// --- поиск секций/записей в конфиге ------------------------------------
// Найти секцию по имени в конфиге указанного уровня (resource|global).
export function findSectionInLevel(name, level) {
    if (!S.configData || !name) { return null; }
    var cfgObj = S.configData[level] || {};
    var keys = Object.keys(cfgObj);
    for (var i = 0; i < keys.length; i++) {
        var s = cfgObj[keys[i]];
        if (s && (s.section_name === name || s.MIGX_formname === name)) {
            return { level: level, section: name, obj: s };
        }
    }
    return null;
}

// Область изменения секции в текущем экране редактора.
// type-resource — открыт сам ресурс-типа; inherited — обычная страница без
// локальной секции; local/global говорят сами за себя.
export function sectionScope(name, isStatic) {
    if (isStatic) { return 'global'; }
    if (!S.configData) { return 'local'; }
    if (S.configData.isType) { return 'type-resource'; }
    if (findSectionInLevel(name, 'resource')) { return 'local'; }
    if (findSectionInLevel(name, 'type')) { return 'inherited'; }
    return 'local';
}

function applySectionScope(addr, isStatic) {
    var scope = sectionScope(addr.section || '', isStatic);
    addr.scope = scope;
    // MPC уже умеет писать уровень type. resourceId остаётся id открытой
    // страницы: LevelResolver найдёт тип через эффективный staticBlocksPageId.
    if (scope === 'inherited') { addr.level = 'type'; }
    return addr;
}

// Конфиг-объект секции для КОНТЕНТА/строк: static→global, иначе resource.
export function sectionConfig(sectionEl) {
    var name = sectionKeyOf(sectionEl);
    var scope = sectionScope(name, sectionEl.hasAttribute('data-mpc-static'));
    var level = scope === 'global' ? 'global' : (scope === 'inherited' ? 'type' : 'resource');
    return findSectionInLevel(name, level);
}

// Конфиг-запись поля (picture/video) по адресу: первая строка [{…}] или null.
// Учитывает ВЛОЖЕННЫЕ адреса: addr.path [{field,idx},…] — спуск к строке-владельцу
// поля (для медиа-полей внутри строк списка), иначе берём поле прямо у секции.
export function fieldConfigRecord(addr) {
    if (!S.configData) { return null; }
    var levelCfg = S.configData[addr.level] || {};
    var keys = Object.keys(levelCfg);
    for (var i = 0; i < keys.length; i++) {
        var s = levelCfg[keys[i]];
        if (s && (s.section_name === addr.section || s.MIGX_formname === addr.section)) {
            // На уровне секции значение — строка (parseRecord парсит), на вложенных
            // уровнях оно уже распарсено в массив (внешний JSON.parse) → берём как есть.
            var recAny = function (v) { return Array.isArray(v) ? v : parseRecord(v); };
            var container = s;
            if (addr.path && addr.path.length) {
                for (var j = 0; j < addr.path.length; j++) {
                    var rows = recAny(container[addr.path[j].field]);
                    if (!rows || !rows[addr.path[j].idx]) { return null; }
                    container = rows[addr.path[j].idx];
                }
            }
            var rec = recAny(container[addr.fieldName]);
            return rec ? rec[0] : null;
        }
    }
    return null;
}
