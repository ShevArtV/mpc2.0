/**
 * mpcVisualEditor — редактор полей-списков значений: listbox / listbox-multiple
 * (выпадайка), option (радио, одиночный), checkbox (чекбоксы, множественный).
 *
 * Опции берём из двух источников:
 *   • data-mpc-values (секционное поле, migx-формат) — статику "Caption==key||…"
 *     парсим на фронте; "@SELECT …" резолвим бэком (fields/options, raw);
 *   • TV без data-mpc-values — опции из elements самой TV (fields/options, tv).
 * Нерезолвенные опции → ручной ввод.
 *
 * Лексиконизация (M25.1): у одиночного listbox значение в БД — ключ опции, а на
 * странице рендерится КАПШЕН (через {('prefix_field_'~$value)|lexicon}). Поэтому
 * текущий выбор определяем и по капшену (label), и по ключу (value); при
 * сохранении обновляем DOM тем же видом, что был (капшены или сырые ключи).
 */
import { api } from '../api.js';
import { S } from '../state.js';
import { toast, esc, closeOnBackdrop } from '../dom.js';
import { fieldAddress } from '../address.js';

// "Caption==key||Caption2==key2" → [{label,value}]. @SELECT/динамика/неключевой → null.
function parseOptions(raw) {
    raw = (raw == null ? '' : String(raw)).trim();
    if (raw === '' || raw.charAt(0) === '@' || raw.indexOf('==') === -1) { return null; }
    return raw.split('||').map(function (pair) {
        var p = pair.split('==');
        return { label: (p[0] || '').trim(), value: (p.length > 1 ? p[1] : p[0] || '').trim() };
    });
}

// Опции через бэк (fields/options): TV по имени или сырой elements (@SELECT).
function fetchOptions(addr, rawValues) {
    var payload = { resourceId: addr.resourceId || S.cfg.resourceId || 0 };
    if (rawValues) {
        payload.raw = rawValues;            // секционный @SELECT (data-mpc-values)
    } else if (addr.type === 'tv') {
        payload.tv = addr.fieldName;        // опции из самой TV
    } else {
        return Promise.resolve(null);
    }
    return api.post('fields/options', payload).then(function (r) {
        return (r && r.success && r.data && r.data.options) ? r.data.options : null;
    }).catch(function () { return null; });
}

export function openListboxEditor(el) {
    if (document.querySelector('.mpcve-modal')) { return; }
    var addr = fieldAddress(el);
    if (!addr) { toast('Нет адреса поля', true); return; }

    var etype = el.getAttribute('data-mpcve-type') || el.getAttribute('data-mpc-ftype') || 'listbox';
    var multiple = etype === 'listbox-multiple' || etype === 'checkbox';
    var rawValues = el.getAttribute('data-mpc-values') || '';
    var localOpts = parseOptions(rawValues);

    // Источник: локальная статика → сразу; иначе бэк (TV-elements или @SELECT).
    var needFetch = !localOpts && (addr.type === 'tv' || (rawValues && rawValues.charAt(0) === '@'));
    if (needFetch) {
        var loader = openLoader();
        fetchOptions(addr, rawValues && rawValues.charAt(0) === '@' ? rawValues : '').then(function (opts) {
            loader.remove();
            render(el, addr, etype, multiple, (opts && opts.length) ? opts : null);
        });
        return;
    }
    render(el, addr, etype, multiple, localOpts);
}

function openLoader() {
    var ov = document.createElement('div');
    ov.className = 'mpcve-modal mpcve-modal--loader';
    ov.innerHTML = '<div class="mpcve-modal__card mpcve-modal__card--text">' +
        '<div class="mpcve-rows__hint">Загрузка опций…</div></div>';
    document.body.appendChild(ov);
    return ov;
}

// opts: [{label,value}] либо null (ручной ввод).
function render(el, addr, etype, multiple, opts) {
    var cur = (el.textContent || '').trim();

    // Текущие ключи + режим отображения (страница показывает капшены или ключи).
    var showLabels = false;
    function keyOf(text) {
        if (opts) {
            for (var i = 0; i < opts.length; i++) { if (opts[i].label === text) { showLabels = true; return opts[i].value; } }
            for (var j = 0; j < opts.length; j++) { if (opts[j].value === text) { return opts[j].value; } }
        }
        return text;
    }
    // В DOM multiple рендерится через запятую (капшены ", " при лексиконах или
    // ключи "," при join), одиночный — один токен. Разбиваем по запятой.
    var curSet = {};
    (cur === '' ? [] : cur.split(/\s*,\s*/)).forEach(function (t) { if (t) { curSet[keyOf(t.trim())] = true; } });

    var controlHtml;
    if (opts) {
        if (etype === 'checkbox' || etype === 'option') {
            // Чекбоксы (множественный) / радио (одиночный) — список с переносами.
            var inputType = etype === 'checkbox' ? 'checkbox' : 'radio';
            controlHtml = '<div class="mpcve-lb__list">' + opts.map(function (o) {
                return '<label class="mpcve-lb__opt">' +
                    '<input type="' + inputType + '" name="mpcve-lb" value="' + esc(o.value) + '"' +
                    (curSet[o.value] ? ' checked' : '') + '> ' + esc(o.label || o.value) + '</label>';
            }).join('') + '</div>';
        } else {
            // listbox / listbox-multiple — нативный <select>.
            var hasEmpty = opts.some(function (o) { return o.value === ''; });
            var size = multiple ? ' multiple size="' + Math.min(Math.max(opts.length, 3), 10) + '"' : '';
            controlHtml = '<select class="mpcve-lb__sel"' + size + '>' +
                (multiple || hasEmpty ? '' : '<option value=""></option>') +
                opts.map(function (o) {
                    return '<option value="' + esc(o.value) + '"' + (curSet[o.value] ? ' selected' : '') + '>' +
                        esc(o.label || o.value) + '</option>';
                }).join('') +
                '</select>';
        }
    } else {
        // Опции не резолвены (@SELECT без доступа / пусто / динамика) — ручной ввод.
        controlHtml = '<input type="text" class="mpcve-lb__input mpcve-ta__area" value="' + esc(cur) + '">' +
            '<div class="mpcve-rows__hint">Опции не удалось получить — введите значение вручную или выберите в админке.</div>';
    }

    var overlay = document.createElement('div');
    overlay.className = 'mpcve-modal';
    overlay.innerHTML =
        '<div class="mpcve-modal__card mpcve-modal__card--text">' +
            '<div class="mpcve-modal__head">Выбор значения' + (multiple ? ' (несколько)' : '') + '</div>' +
            controlHtml +
            '<div class="mpcve-modal__actions">' +
                '<button type="button" class="mpcve-btn" data-act="cancel">Отмена</button>' +
                '<button type="button" class="mpcve-btn mpcve-btn--primary" data-act="save">Сохранить</button>' +
            '</div>' +
        '</div>';
    document.body.appendChild(overlay);

    function close() { overlay.remove(); document.removeEventListener('keydown', onKey); }
    function onKey(e) { if (e.key === 'Escape') { close(); } }
    document.addEventListener('keydown', onKey);
    closeOnBackdrop(overlay, function () { close(); });
    overlay.querySelector('[data-act=cancel]').addEventListener('click', close);

    var saveBtn = overlay.querySelector('[data-act=save]');
    saveBtn.addEventListener('click', function () {
        var keys;
        if (!opts) {
            keys = [overlay.querySelector('.mpcve-lb__input').value];
        } else if (etype === 'checkbox' || etype === 'option') {
            keys = Array.prototype.slice.call(overlay.querySelectorAll('.mpcve-lb__opt input:checked'))
                .map(function (i) { return i.value; });
            if (!multiple) { keys = keys.slice(0, 1); }
        } else {
            var sel = overlay.querySelector('.mpcve-lb__sel');
            keys = multiple
                ? Array.prototype.slice.call(sel.selectedOptions).map(function (o) { return o.value; })
                : [sel.value];
        }
        // multiple → строка ключей через "||" (migx/MODX-формат; admin round-trip);
        // одиночный → строка-ключ. Рендер итерирует через split (см. каттер).
        var value = multiple ? keys.join('||') : (keys[0] || '');

        // Отображение на странице: если рендерились капшены — показываем капшены.
        var labelFor = {};
        if (opts) { opts.forEach(function (o) { labelFor[o.value] = o.label; }); }
        var display = keys.map(function (k) {
            return (showLabels && labelFor[k] != null && labelFor[k] !== '') ? labelFor[k] : k;
        }).join(', ');

        // raw=1: значение списка — сырой ключ(и), бэк НЕ лексиконит (капшены опций
        // уже в лексиконе с нарезки), пишет строку как есть.
        addr.raw = 1;
        saveBtn.disabled = true; saveBtn.textContent = 'Сохранение…';
        api.post('field/save', { address: addr, value: value, old: cur }).then(function (r) {
            if (r && r.success) {
                el.textContent = display;
                toast('Сохранено');
                close();
            } else {
                toast((r && r.message) || 'Ошибка сохранения', true);
                saveBtn.disabled = false; saveBtn.textContent = 'Сохранить';
            }
        }).catch(function () {
            toast('Сетевая ошибка', true);
            saveBtn.disabled = false; saveBtn.textContent = 'Сохранить';
        });
    });
}
