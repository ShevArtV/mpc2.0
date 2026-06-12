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
import { openFileManager } from '../filemanager.js';

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
function isImage(x) { return /image/i.test(x || ''); }
function isMultiline(x, v) {
    return x === 'textarea' || x === 'code' || /textarea/i.test(x || '') || (v && v.indexOf('\n') !== -1);
}

function previewHtml(url) {
    return url ? '<img src="' + esc(url) + '" alt="" style="max-width:100%;max-height:120px;margin-top:8px;border-radius:6px">' : '';
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
    // image-настройка (modx-panel-tv-image / image-xtype): путь + выбор через
    // file manager + превью. Значение читается из текст-инпута, как у прочих.
    if (isImage(xtype)) {
        return '<input type="text" class="mpcve-set__input" value="' + esc(value) + '">' +
               '<button type="button" class="mpcve-btn mpcve-set__browse">Выбрать изображение</button>' +
               '<div class="mpcve-set__preview">' + previewHtml(value) + '</div>';
    }
    if (isMultiline(xtype, value)) {
        return '<textarea class="mpcve-set__input mpcve-ta__area" spellcheck="false"></textarea>';
    }
    return '<input type="text" class="mpcve-set__input" value="' + esc(value) + '">';
}

export function openInfoEditor(el, meta) {
    if (document.querySelector('.mpcve-modal')) { return; }
    if (meta) { open(el, meta); return; }
    // Инлайн: тип/значение/таргет тянем из БД (settings/list, эффективная строка).
    var key = el.getAttribute('data-mpc-info') || '';
    loadSettingsList().then(function () {
        var m = findSetting(key);
        open(el, m || { key: key, ctx: null, target: 'system', value: domValue(el), xtype: 'textfield', protected: false });
    });
}

function open(el, meta) {
    if (document.querySelector('.mpcve-modal')) { return; }
    // Таргет: 'context' (есть контекстная запись — правим её, без глобального
    // предупреждения) vs 'system' (только глобальная — предупреждаем).
    var isCtx = meta.target === 'context';
    var note = isCtx
        ? ('Контекстная настройка' + (meta.ctx ? ' (' + esc(meta.ctx) + ')' : '') + ' — меняется в этом контексте.')
        : '⚠ ГЛОБАЛЬНАЯ настройка — меняется на ВСЕХ контекстах и страницах сайта.';
    var overlay = document.createElement('div');
    overlay.className = 'mpcve-modal';
    overlay.innerHTML =
        '<div class="mpcve-modal__card mpcve-modal__card--text">' +
            '<div class="mpcve-modal__head">Настройка: ' + esc(meta.key) +
                (isCtx && meta.ctx ? ' @' + esc(meta.ctx) : '') + '</div>' +
            '<div class="mpcve-modal__note">' + note + '</div>' +
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
    // image-настройка: выбор файла через file manager → путь в инпут + превью.
    var browse = card.querySelector('.mpcve-set__browse');
    if (browse) {
        var imgInput = card.querySelector('.mpcve-set__input');
        var preview = card.querySelector('.mpcve-set__preview');
        browse.addEventListener('click', function () {
            openFileManager({ accept: 'image', title: 'Настройка: изображение' }).then(function (file) {
                if (!file) { return; }
                imgInput.value = file.url;
                if (preview) { preview.innerHTML = previewHtml(file.url); }
            });
        });
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
        // colorpicker MODX хранит hex БЕЗ ведущего # — срезаем перед сохранением.
        if (meta.xtype === 'colorpickerfield') { value = value.replace(/^#/, ''); }
        // Пишем в эффективный таргет: контекстная запись существует → в неё
        // (payload.ctx); иначе глобальная (без ctx — saveSetting в modSystemSetting).
        var payload = { key: meta.key, value: value };
        if (meta.target === 'context' && meta.ctx) { payload.ctx = meta.ctx; }
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
