/**
 * mpcVisualEditor — DOM/формат-хелперы и уведомления.
 */
import { S } from './state.js';
import { FIELD_LABELS } from './constants.js';

export function esc(s) {
    return String(s == null ? '' : s)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;')
        .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

// JSON-строка migx-рядов [{…},…] → массив объектов, иначе null.
export function parseRecord(v) {
    if (typeof v !== 'string') { return null; }
    var d;
    try { d = JSON.parse(v); } catch (e) { return null; }
    return (Array.isArray(d) && d.length && d.every(function (r) {
        return r && typeof r === 'object';
    })) ? d : null;
}

export function isScalar(v) {
    return v == null || typeof v === 'string' || typeof v === 'number' || typeof v === 'boolean';
}

export function isMedia(el) {
    var t = el.tagName.toLowerCase();
    return t === 'img' || t === 'picture' || t === 'video' || t === 'audio';
}

// URL фоновой картинки из INLINE style. Пусто, если фона нет.
export function bgUrl(el) {
    var raw = (el.style && el.style.backgroundImage) || '';
    var m = raw.match(/url\((['"]?)(.*?)\1\)/i);
    return m ? m[2] : '';
}
export function hasBg(el) {
    return !!bgUrl(el);
}

// Подпись поля: ручная → caption конфигуратора → ключ (как fallback).
export function fieldLabel(name) {
    return FIELD_LABELS[name] || S.labelsMap[name] || name;
}

export function toast(message, isError) {
    var node = document.createElement('div');
    node.className = 'mpcve-toast' + (isError ? ' mpcve-toast--error' : '');
    node.textContent = message;
    document.body.appendChild(node);
    requestAnimationFrame(function () { node.classList.add('mpcve-toast--show'); });
    setTimeout(function () {
        node.classList.remove('mpcve-toast--show');
        setTimeout(function () { node.remove(); }, 250);
    }, 2400);
}

// Диалог выбора из нескольких вариантов (стиль редактора). Возвращает
// Promise<key|null>: key выбранной кнопки, null — отмена (Esc / фон / Отмена).
// opts: { title, choices:[{key,label,primary,danger}], cancelLabel }.
export function choiceDialog(message, opts) {
    opts = opts || {};
    var choices = opts.choices || [];
    return new Promise(function (resolve) {
        if (document.querySelector('.mpcve-confirm')) { resolve(null); return; }
        var ov = document.createElement('div');
        ov.className = 'mpcve-modal mpcve-confirm';
        var btns = choices.map(function (c, i) {
            var cls = 'mpcve-btn' + (c.primary ? ' mpcve-btn--primary' : '') + (c.danger ? ' mpcve-btn--danger' : '');
            return '<button type="button" class="' + cls + '" data-choice="' + i + '"></button>';
        }).join('');
        ov.innerHTML =
            '<div class="mpcve-modal__card mpcve-confirm__card">' +
                (opts.title ? '<div class="mpcve-modal__head"></div>' : '') +
                '<div class="mpcve-confirm__msg"></div>' +
                '<div class="mpcve-modal__actions mpcve-confirm__actions">' +
                    '<button type="button" class="mpcve-btn" data-act="cancel"></button>' +
                    btns +
                '</div>' +
            '</div>';
        document.body.appendChild(ov);
        if (opts.title) { ov.querySelector('.mpcve-modal__head').textContent = opts.title; }
        ov.querySelector('.mpcve-confirm__msg').textContent = message;
        ov.querySelector('[data-act=cancel]').textContent = opts.cancelLabel || 'Отмена';
        choices.forEach(function (c, i) {
            ov.querySelector('[data-choice="' + i + '"]').textContent = c.label;
        });

        function done(v) { ov.remove(); document.removeEventListener('keydown', onKey, true); resolve(v); }
        // capture + stopImmediatePropagation: Escape гасит ТОЛЬКО этот диалог,
        // не подлежащий редактор (он тоже слушает Escape на document). Если сверху
        // confirm/prompt — отдаём Escape ему.
        function onKey(e) {
            if (e.key !== 'Escape') { return; }
            // choiceDialog сам имеет класс .mpcve-confirm → исключаем себя, иначе
            // querySelector находит собственный оверлей и Escape не закрывает (V9).
            var top = document.querySelector('.mpcve-confirm');
            if (top && top !== ov) { return; } // сверху ДРУГОЙ confirm/prompt — ему
            e.stopImmediatePropagation();
            done(null);
        }
        document.addEventListener('keydown', onKey, true);
        ov.addEventListener('click', function (e) { if (e.target === ov) { done(null); } });
        ov.querySelector('[data-act=cancel]').addEventListener('click', function () { done(null); });
        choices.forEach(function (c, i) {
            ov.querySelector('[data-choice="' + i + '"]').addEventListener('click', function () { done(c.key); });
        });
        var first = ov.querySelector('[data-choice]');
        if (first) { first.focus(); }
    });
}

// Модальный ввод строки (замена window.prompt). opts: { title, okLabel,
// cancelLabel, placeholder, value }. Возвращает Promise<string|null>: введённое
// значение (trim) или null (Esc / фон / Отмена / пустой ввод). Enter = OK.
export function promptDialog(message, opts) {
    opts = opts || {};
    return new Promise(function (resolve) {
        if (document.querySelector('.mpcve-confirm')) { resolve(null); return; }
        var ov = document.createElement('div');
        ov.className = 'mpcve-modal mpcve-confirm';
        ov.innerHTML =
            '<div class="mpcve-modal__card mpcve-confirm__card">' +
                (opts.title ? '<div class="mpcve-modal__head"></div>' : '') +
                '<div class="mpcve-confirm__msg"></div>' +
                '<label class="mpcve-modal__field"><input type="text" data-f="val"></label>' +
                '<div class="mpcve-modal__actions">' +
                    '<button type="button" class="mpcve-btn" data-act="cancel"></button>' +
                    '<button type="button" class="mpcve-btn mpcve-btn--primary" data-act="ok"></button>' +
                '</div>' +
            '</div>';
        document.body.appendChild(ov);
        if (opts.title) { ov.querySelector('.mpcve-modal__head').textContent = opts.title; }
        ov.querySelector('.mpcve-confirm__msg').textContent = message;
        ov.querySelector('[data-act=cancel]').textContent = opts.cancelLabel || 'Отмена';
        ov.querySelector('[data-act=ok]').textContent = opts.okLabel || 'OK';
        var inp = ov.querySelector('[data-f=val]');
        if (opts.placeholder) { inp.placeholder = opts.placeholder; }
        if (opts.value) { inp.value = opts.value; }

        function done(v) { ov.remove(); document.removeEventListener('keydown', onKey, true); resolve(v); }
        function submit() { var v = inp.value.trim(); done(v || null); }
        // capture + stopImmediatePropagation — гасим клавишу для подлежащего редактора.
        function onKey(e) {
            if (e.key === 'Escape') { e.stopImmediatePropagation(); done(null); }
            else if (e.key === 'Enter') { e.preventDefault(); e.stopImmediatePropagation(); submit(); }
        }
        document.addEventListener('keydown', onKey, true);
        ov.addEventListener('click', function (e) { if (e.target === ov) { done(null); } });
        ov.querySelector('[data-act=cancel]').addEventListener('click', function () { done(null); });
        ov.querySelector('[data-act=ok]').addEventListener('click', submit);
        inp.focus();
    });
}

// Модальное подтверждение в стиле редактора (замена window.confirm).
// opts: { title, okLabel, cancelLabel, danger }. Возвращает Promise<boolean>.
// Enter = OK, Esc / клик по фону / Отмена = false.
export function confirmDialog(message, opts) {
    opts = opts || {};
    return new Promise(function (resolve) {
        if (document.querySelector('.mpcve-confirm')) { resolve(false); return; }
        var ov = document.createElement('div');
        ov.className = 'mpcve-modal mpcve-confirm';
        ov.innerHTML =
            '<div class="mpcve-modal__card mpcve-confirm__card">' +
                (opts.title ? '<div class="mpcve-modal__head"></div>' : '') +
                '<div class="mpcve-confirm__msg"></div>' +
                '<div class="mpcve-modal__actions">' +
                    '<button type="button" class="mpcve-btn" data-act="cancel"></button>' +
                    '<button type="button" class="mpcve-btn mpcve-btn--primary' + (opts.danger ? ' mpcve-btn--danger' : '') + '" data-act="ok"></button>' +
                '</div>' +
            '</div>';
        document.body.appendChild(ov);
        if (opts.title) { ov.querySelector('.mpcve-modal__head').textContent = opts.title; }
        ov.querySelector('.mpcve-confirm__msg').textContent = message;
        ov.querySelector('[data-act=cancel]').textContent = opts.cancelLabel || 'Отмена';
        ov.querySelector('[data-act=ok]').textContent = opts.okLabel || 'OK';

        function done(v) { ov.remove(); document.removeEventListener('keydown', onKey, true); resolve(v); }
        // capture + stopImmediatePropagation — гасим клавишу для подлежащего редактора.
        function onKey(e) {
            if (e.key === 'Escape') { e.stopImmediatePropagation(); done(false); }
            else if (e.key === 'Enter') { e.preventDefault(); e.stopImmediatePropagation(); done(true); }
        }
        document.addEventListener('keydown', onKey, true);
        ov.addEventListener('click', function (e) { if (e.target === ov) { done(false); } });
        ov.querySelector('[data-act=cancel]').addEventListener('click', function () { done(false); });
        ov.querySelector('[data-act=ok]').addEventListener('click', function () { done(true); });
        ov.querySelector('[data-act=ok]').focus();
    });
}
