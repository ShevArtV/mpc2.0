/**
 * mpcVisualEditor — редактор служебной информации (data-mpc-info): ГЛОБАЛЬНАЯ
 * настройка сайта. Виджет подбирается по xtype ИЗ БД (settings/list → settingMeta),
 * единый источник типа — не вёрстка (работает и для data-mpc-remove настроек).
 * Запись через info/save (право mpcve_edit_global). Предупреждение о глобальности.
 *
 * openInfoEditor(el, meta): el — DOM-элемент (инлайн) или null (из панели);
 * meta — {key, ctx, value, xtype, protected}. Без meta (инлайн) — подтягиваем из БД.
 */
import { api, loadSettingsList, findSetting } from '../api.js';
import { toast, esc } from '../dom.js';

function tag(el) { return el && el.tagName ? el.tagName.toLowerCase() : ''; }
function domValue(el) {
    var t = tag(el);
    if (t === 'link') { return el.getAttribute('href') || ''; }
    if (t === 'img') { return el.getAttribute('src') || ''; }
    return el ? (el.textContent || '').trim() : '';
}
function applyDom(el, v) {
    if (!el) { return; }
    var t = tag(el);
    if (t === 'link') { el.setAttribute('href', v); return; }
    if (t === 'img') { el.setAttribute('src', v); return; }
    el.textContent = v;
}

function isBool(x) { return /boolean/i.test(x || ''); }
function isMultiline(x, v) {
    return x === 'textarea' || x === 'code' || /textarea/i.test(x || '') || (v && v.indexOf('\n') !== -1);
}

function widgetHtml(xtype, value) {
    if (isBool(xtype)) {
        var on = (value === '1' || value === 'true' || value === 'yes' || value === true);
        return '<select class="mpcve-set__input">' +
            '<option value="1"' + (on ? ' selected' : '') + '>Да</option>' +
            '<option value="0"' + (on ? '' : ' selected') + '>Нет</option></select>';
    }
    if (xtype === 'colorpickerfield') {
        var c = /^#?[0-9a-fA-F]{3,8}$/.test(value) ? (value.charAt(0) === '#' ? value : '#' + value) : '#000000';
        return '<input type="color" class="mpcve-set__color" value="' + esc(c) + '">' +
               '<input type="text" class="mpcve-set__input" value="' + esc(value) + '">';
    }
    if (isMultiline(xtype, value)) {
        return '<textarea class="mpcve-set__input mpcve-ta__area" spellcheck="false"></textarea>';
    }
    return '<input type="text" class="mpcve-set__input" value="' + esc(value) + '">';
}

export function openInfoEditor(el, meta) {
    if (document.querySelector('.mpcve-modal')) { return; }
    if (meta) { open(el, meta); return; }
    // Инлайн: тип/значение тянем из БД (settings/list).
    var key = el.getAttribute('data-mpc-info') || '';
    var hasCtx = el.hasAttribute('data-mpc-ctx');
    loadSettingsList().then(function () {
        var m = findSetting(key, hasCtx);
        open(el, m || { key: key, ctx: hasCtx ? '' : null, value: domValue(el), xtype: 'textfield', protected: false });
    });
}

function open(el, meta) {
    if (document.querySelector('.mpcve-modal')) { return; }
    var overlay = document.createElement('div');
    overlay.className = 'mpcve-modal';
    overlay.innerHTML =
        '<div class="mpcve-modal__card mpcve-modal__card--text">' +
            '<div class="mpcve-modal__head">Настройка: ' + esc(meta.key) +
                (meta.ctx != null ? ' @' + esc(meta.ctx || 'web') : '') + '</div>' +
            '<div class="mpcve-modal__note">⚠ Меняется на ВСЕХ страницах сайта. Тип: ' + esc(meta.xtype) + '.</div>' +
            (meta.protected ? '<div class="mpcve-modal__note">🔒 Защищённая настройка — править нельзя.</div>' : '') +
            '<div class="mpcve-set__widget">' + widgetHtml(meta.xtype, meta.value) + '</div>' +
            '<div class="mpcve-modal__actions">' +
                '<button type="button" class="mpcve-btn" data-act="cancel">Отмена</button>' +
                (meta.protected ? '' : '<button type="button" class="mpcve-btn mpcve-btn--primary" data-act="save">Сохранить</button>') +
            '</div>' +
        '</div>';
    document.body.appendChild(overlay);
    var card = overlay.querySelector('.mpcve-modal__card');

    var ta = card.querySelector('textarea.mpcve-set__input');
    if (ta) { ta.value = meta.value; }
    var color = card.querySelector('.mpcve-set__color');
    if (color) {
        var txt = card.querySelector('input.mpcve-set__input');
        color.addEventListener('input', function () { txt.value = color.value; });
    }
    var firstInput = card.querySelector('.mpcve-set__input');
    if (firstInput) { firstInput.focus(); }

    function close() { overlay.remove(); document.removeEventListener('keydown', onKey); }
    function onKey(e) { if (e.key === 'Escape') { close(); } }
    document.addEventListener('keydown', onKey);
    overlay.addEventListener('click', function (e) { if (e.target === overlay) { close(); } });
    card.querySelector('[data-act=cancel]').addEventListener('click', close);

    var saveBtn = card.querySelector('[data-act=save]');
    if (!saveBtn) { return; }
    saveBtn.addEventListener('click', function () {
        var input = card.querySelector('.mpcve-set__input');
        var value = input ? input.value : '';
        var payload = { key: meta.key, value: value };
        if (meta.ctx != null) { payload.ctx = meta.ctx; }
        api.post('info/save', payload).then(function (r) {
            if (r && r.success) {
                applyDom(el, value);
                toast('Настройка сохранена — «Обновить» для применения');
                close();
            } else {
                toast((r && r.message) || 'Ошибка сохранения', true);
            }
        }).catch(function () { toast('Сетевая ошибка', true); });
    });
}
