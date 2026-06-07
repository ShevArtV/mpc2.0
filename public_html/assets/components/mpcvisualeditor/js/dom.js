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

// Базовая модалка-фабрика (confirm/prompt/choice). Создаёт overlay+card, msg,
// actions (cancel + переданные кнопки), вешает Escape/click-фон/cancel →
// done(cancelValue), Enter → onEnter. Тело/доп-кнопки/спецлогику настраивает
// onMount(ov, done). Singleton-гард по .mpcve-confirm НЕ даёт стек диалогов,
// поэтому в onKey НЕ нужен self-querySelector (его кривой вариант и дал V9 —
// сравнивал найденный .mpcve-confirm с самим собой). Возвращает Promise<значение
// от done()>; повторный вызов при открытом диалоге → resolve(cancelValue).
function buildModal(opts) {
    return new Promise(function (resolve) {
        if (document.querySelector('.mpcve-confirm')) { resolve(opts.cancelValue); return; }
        var ov = document.createElement('div');
        ov.className = 'mpcve-modal mpcve-confirm';
        var actionsCls = 'mpcve-modal__actions' + (opts.actionsClass ? ' ' + opts.actionsClass : '');
        ov.innerHTML =
            '<div class="mpcve-modal__card mpcve-confirm__card">' +
                (opts.title ? '<div class="mpcve-modal__head"></div>' : '') +
                '<div class="mpcve-confirm__msg"></div>' +
                (opts.bodyHtml || '') +
                '<div class="' + actionsCls + '">' +
                    '<button type="button" class="mpcve-btn" data-act="cancel"></button>' +
                    (opts.buttonsHtml || '') +
                '</div>' +
            '</div>';
        document.body.appendChild(ov);
        if (opts.title) { ov.querySelector('.mpcve-modal__head').textContent = opts.title; }
        ov.querySelector('.mpcve-confirm__msg').textContent = opts.message;
        ov.querySelector('[data-act=cancel]').textContent = opts.cancelLabel || 'Отмена';

        function done(v) { ov.remove(); document.removeEventListener('keydown', onKey, true); resolve(v); }
        // capture + stopImmediatePropagation — гасим клавишу для подлежащего
        // редактора (он тоже слушает keydown на document).
        function onKey(e) {
            if (e.key === 'Escape') { e.stopImmediatePropagation(); done(opts.cancelValue); }
            else if (e.key === 'Enter' && opts.onEnter) { e.preventDefault(); e.stopImmediatePropagation(); opts.onEnter(done, ov); }
        }
        document.addEventListener('keydown', onKey, true);
        ov.addEventListener('click', function (e) { if (e.target === ov) { done(opts.cancelValue); } });
        ov.querySelector('[data-act=cancel]').addEventListener('click', function () { done(opts.cancelValue); });
        if (opts.onMount) { opts.onMount(ov, done); }
    });
}

// Диалог выбора из нескольких вариантов (стиль редактора). Возвращает
// Promise<key|null>: key выбранной кнопки, null — отмена (Esc / фон / Отмена).
// opts: { title, choices:[{key,label,primary,danger}], cancelLabel }.
export function choiceDialog(message, opts) {
    opts = opts || {};
    var choices = opts.choices || [];
    var btns = choices.map(function (c, i) {
        var cls = 'mpcve-btn' + (c.primary ? ' mpcve-btn--primary' : '') + (c.danger ? ' mpcve-btn--danger' : '');
        return '<button type="button" class="' + cls + '" data-choice="' + i + '"></button>';
    }).join('');
    return buildModal({
        title: opts.title, message: message, cancelValue: null, cancelLabel: opts.cancelLabel,
        actionsClass: 'mpcve-confirm__actions', buttonsHtml: btns,
        // choice не реагирует на Enter (нет однозначного действия) — onEnter не задаём.
        onMount: function (ov, done) {
            choices.forEach(function (c, i) {
                var btn = ov.querySelector('[data-choice="' + i + '"]');
                btn.textContent = c.label;
                btn.addEventListener('click', function () { done(c.key); });
            });
            var first = ov.querySelector('[data-choice]');
            if (first) { first.focus(); }
        }
    });
}

// Модальный ввод строки (замена window.prompt). opts: { title, okLabel,
// cancelLabel, placeholder, value }. Возвращает Promise<string|null>: введённое
// значение (trim) или null (Esc / фон / Отмена / пустой ввод). Enter = OK.
export function promptDialog(message, opts) {
    opts = opts || {};
    function submit(done, ov) { var v = ov.querySelector('[data-f=val]').value.trim(); done(v || null); }
    return buildModal({
        title: opts.title, message: message, cancelValue: null, cancelLabel: opts.cancelLabel,
        bodyHtml: '<label class="mpcve-modal__field"><input type="text" data-f="val"></label>',
        buttonsHtml: '<button type="button" class="mpcve-btn mpcve-btn--primary" data-act="ok"></button>',
        onEnter: submit,
        onMount: function (ov, done) {
            ov.querySelector('[data-act=ok]').textContent = opts.okLabel || 'OK';
            var inp = ov.querySelector('[data-f=val]');
            if (opts.placeholder) { inp.placeholder = opts.placeholder; }
            if (opts.value) { inp.value = opts.value; }
            ov.querySelector('[data-act=ok]').addEventListener('click', function () { submit(done, ov); });
            inp.focus();
        }
    });
}

// Модальное подтверждение в стиле редактора (замена window.confirm).
// opts: { title, okLabel, cancelLabel, danger }. Возвращает Promise<boolean>.
// Enter = OK, Esc / клик по фону / Отмена = false.
export function confirmDialog(message, opts) {
    opts = opts || {};
    var okCls = 'mpcve-btn mpcve-btn--primary' + (opts.danger ? ' mpcve-btn--danger' : '');
    return buildModal({
        title: opts.title, message: message, cancelValue: false, cancelLabel: opts.cancelLabel,
        buttonsHtml: '<button type="button" class="' + okCls + '" data-act="ok"></button>',
        onEnter: function (done) { done(true); },
        onMount: function (ov, done) {
            var ok = ov.querySelector('[data-act=ok]');
            ok.textContent = opts.okLabel || 'OK';
            ok.addEventListener('click', function () { done(true); });
            ok.focus();
        }
    });
}
