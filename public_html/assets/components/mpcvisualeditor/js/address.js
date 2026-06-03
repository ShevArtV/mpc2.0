/**
 * mpcVisualEditor — адресация полей из DOM («one address space») + поиск записей
 * в конфиге (источник правды для лексикон-ключей и числа строк).
 */
import { S } from './state.js';
import { isMedia, hasBg, parseRecord } from './dom.js';

// Тип редактора для стандартных полей ресурса MODX (rfield). Не перечисленные
// (pagetitle/longtitle/menutitle/…) → text по умолчанию. data-mpc-ftype на
// маркере переопределяет (проверяется раньше в editorTypeFor).
var RFIELD_TYPES = {
    content: 'richtext',
    introtext: 'textarea',
    description: 'textarea'
};

// --- адрес поля из DOM -------------------------------------------------
export function resolveAddress(el) {
    var type = null, fieldName = null;
    if (el.hasAttribute('data-mpc-rfield')) {
        type = 'rfield';
        fieldName = el.getAttribute('data-mpc-rfield');
    } else if (el.hasAttribute('data-mpc-tv')) {
        type = 'tv';
        fieldName = el.getAttribute('data-mpc-tv');
    } else {
        for (var i = 0; i < el.attributes.length; i++) {
            var a = el.attributes[i];
            if (a.name === 'data-mpc-field' || /^data-mpc-field-\d+$/.test(a.name)) {
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
    var sectionEl = el.closest('[data-mpc-section]');
    addr.section = sectionEl ? sectionEl.getAttribute('data-mpc-section') : '';
    addr.level = (sectionEl && sectionEl.hasAttribute('data-mpc-static')) ? 'global' : 'resource';
    addr.resourceId = S.cfg.resourceId || 0;

    // Кросс-ресурс: обёртка data-mpc-res="<id>" (поле другого ресурса,
    // выведенного сниппетом) → пишем в ТОТ ресурс, а не в текущую страницу.
    var resEl = el.closest('[data-mpc-res]');
    if (resEl) {
        var rid = parseInt(resEl.getAttribute('data-mpc-res'), 10);
        if (rid > 0) {
            addr.resourceId = rid;
        }
    }

    // Вложенное поле строки списка: data-mpc-field-N. Собираем ПОЛНЫЙ путь
    // [{field,idx}, …] от секции к строке (вложенность любой глубины), т.к.
    // поле уровня 2 лежит на 2 уровня глубже: cfg[sec][L1][i][L2][j][field].
    var nestAttr = null;
    for (var i = 0; i < el.attributes.length; i++) {
        if (/^data-mpc-field-\d+$/.test(el.attributes[i].name)) {
            nestAttr = el.attributes[i].name;
            break;
        }
    }
    if (nestAttr) {
        var lvl = parseInt(nestAttr.replace('data-mpc-field-', ''), 10);
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

// Путь [{field,idx}, …] от секции к строке для поля уровня lvl (data-mpc-field-lvl).
// Уровень N: ряд = data-mpc-item-(N-1) (data-mpc-item для N=1), контейнер
// списка = data-mpc-field-(N-1) (data-mpc-field для N=1).
export function buildRowPath(el, lvl) {
    var path = [];
    var base = el;
    for (var L = lvl; L >= 1; L--) {
        var itemAttr = L > 1 ? 'data-mpc-item-' + (L - 1) : 'data-mpc-item';
        var listAttr = L > 1 ? 'data-mpc-field-' + (L - 1) : 'data-mpc-field';
        var itemEl = base.closest('[' + itemAttr + ']');
        if (!itemEl) { return null; }
        var listEl = itemEl.closest('[' + listAttr + ']');
        if (!listEl || listEl === itemEl) { return null; }
        var idx = 0, sib = itemEl.previousElementSibling;
        while (sib) {
            if (sib.hasAttribute(itemAttr)) { idx++; }
            sib = sib.previousElementSibling;
        }
        path.unshift({ field: listEl.getAttribute(listAttr), idx: idx });
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
    if (ftype.indexOf('list') === 0) { return 'rows'; }
    return 'text'; // text/number/checkbox — инлайн-текст
}

// Атрибут-маркер списка + его уровень вложенности (0 = top-level data-mpc-field,
// N = data-mpc-field-N). Ряды списка уровня N помечены data-mpc-item-N
// (data-mpc-item для top). null — не список-контейнер.
export function listFieldAttr(el) {
    if (el.hasAttribute && el.hasAttribute('data-mpc-field')) {
        return { attr: 'data-mpc-field', lvl: 0 };
    }
    for (var n = 1; n <= 3; n++) {
        if (el.hasAttribute && el.hasAttribute('data-mpc-field-' + n)) {
            return { attr: 'data-mpc-field-' + n, lvl: n };
        }
    }
    return null;
}

// Имя item-атрибута строк списка уровня lvl.
export function itemAttrForLevel(lvl) {
    return lvl > 0 ? 'data-mpc-item-' + lvl : 'data-mpc-item';
}

// Контейнер-список? = есть СВОИ строки (data-mpc-item уровня этого поля).
// Пустой список (0 строк) детектится по data-mpc-ftype="list*" (ftypeToEditor),
// см. editorTypeFor — поэтому здесь только непустые.
export function isListEl(el) {
    var fa = listFieldAttr(el);
    if (!fa) {
        return !!(el.querySelector && el.querySelector('[data-mpc-item]'));
    }
    return !!(el.querySelector && el.querySelector('[' + itemAttrForLevel(fa.lvl) + ']'));
}

export function editorTypeFor(el, addr) {
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
    var byFtype = ftypeToEditor(el.getAttribute('data-mpc-ftype'));
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
        section: sectionEl ? sectionEl.getAttribute('data-mpc-section') : '',
        parentField: fa ? (listEl.getAttribute(fa.attr) || '') : '',
        level: (sectionEl && sectionEl.hasAttribute('data-mpc-static')) ? 'global' : 'resource',
        resourceId: rid
    };
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
    var itemAttr = itemAttrForLevel(fa ? fa.lvl : 0);
    var listSel = fa ? fa.attr : 'data-mpc-field';
    var items = Array.prototype.slice.call(el.querySelectorAll('[' + itemAttr + ']'))
        .filter(function (it) { return it.closest('[' + listSel + ']') === el; });
    if (!items.length && isMedia(el) && el.parentElement) {
        items = Array.prototype.slice.call(el.parentElement.children).filter(function (c) {
            return c.getAttribute && c.getAttribute('data-mpc-field') === field;
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

// Конфиг-объект секции для КОНТЕНТА/строк: static→global, иначе resource.
export function sectionConfig(sectionEl) {
    var name = sectionEl.getAttribute('data-mpc-section') || '';
    var level = sectionEl.hasAttribute('data-mpc-static') ? 'global' : 'resource';
    return findSectionInLevel(name, level);
}

// Конфиг-запись поля (picture/video) по адресу: первая строка [{…}] или null.
export function fieldConfigRecord(addr) {
    if (!S.configData) { return null; }
    var levelCfg = S.configData[addr.level] || {};
    var keys = Object.keys(levelCfg);
    for (var i = 0; i < keys.length; i++) {
        var s = levelCfg[keys[i]];
        if (s && (s.section_name === addr.section || s.MIGX_formname === addr.section)) {
            var rec = parseRecord(s[addr.fieldName]);
            return rec ? rec[0] : null;
        }
    }
    return null;
}
