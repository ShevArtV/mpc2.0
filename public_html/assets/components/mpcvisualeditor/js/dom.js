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

// Закрытие модалки кликом по подложке. Проверяем И mousedown, И click: браузер
// шлёт click ближайшему ОБЩЕМУ предку нажатия и отпускания, поэтому при drag-
// выделении текста внутри карточки с отпусканием мыши за её пределами target
// клика — подложка, и модалка закрывалась сама (жалоба оператора 02.09.2026).
export function closeOnBackdrop(overlay, close) {
    var downOnBackdrop = false;
    overlay.addEventListener('mousedown', function (e) { downOnBackdrop = (e.target === overlay); });
    // Обратный случай: выделение начато на подложке и закончено в карточке —
    // click снова прилетит подложке, закрывать тоже не надо.
    overlay.addEventListener('mouseup', function (e) { if (e.target !== overlay) { downOnBackdrop = false; } });
    overlay.addEventListener('click', function (e) {
        var wasDown = downOnBackdrop;
        downOnBackdrop = false;
        if (e.target === overlay && wasDown) { close(); }
    });
}

/**
 * Каркас модалки редактора: подложка + карточка + (заголовок) + тело +
 * (блок кнопок). Забирает ритуал, который раньше был скопирован в каждый
 * редактор: singleton-гард, Escape, закрытие по подложке и по кнопке отмены,
 * снятие слушателя при закрытии. Тело и кнопки остаются за вызывающим.
 *
 * opts:
 *   cardClass      доп. классы карточки (базовый mpcve-modal__card уже есть);
 *   overlayClass   доп. классы подложки (базовый mpcve-modal уже есть);
 *   title          текст шапки (экранируется здесь), пусто → без шапки;
 *   titleHtml      готовая разметка шапки, если нужен не просто текст;
 *   bodyHtml       разметка тела (экранирует вызывающий);
 *   actionsHtml    разметка блока кнопок, пусто → без блока;
 *   actionsClass   доп. классы блока кнопок;
 *   guard          селектор singleton-гарда, по умолчанию '.mpcve-modal';
 *                  null — гарда нет (слой поверх другой модалки);
 *   captureEsc     слушать Escape в capture-фазе и гасить его для нижних
 *                  слоёв (панели/менеджеры, под которыми открыт редактор);
 *   closeSelectors селекторы кнопок закрытия, по умолчанию ['[data-act=cancel]'];
 *   onKey          доп. обработчик клавиш (e, close) — Enter и прочее;
 *   onClose        вызывается ПЕРЕД снятием подложки (rte.destroy, сброс
 *                  состояния, resolve промиса).
 *
 * Возвращает { overlay, card, close, isClosed } либо null, если гард не пустил.
 */
export function openModal(opts) {
    opts = opts || {};
    var guard = (opts.guard === null) ? null : (opts.guard || '.mpcve-modal');
    if (guard && document.querySelector(guard)) { return null; }

    var overlay = document.createElement('div');
    overlay.className = 'mpcve-modal' + (opts.overlayClass ? ' ' + opts.overlayClass : '');
    var head = opts.titleHtml || (opts.title ? esc(opts.title) : '');
    overlay.innerHTML =
        '<div class="mpcve-modal__card' + (opts.cardClass ? ' ' + opts.cardClass : '') + '">' +
            (head ? '<div class="mpcve-modal__head">' + head + '</div>' : '') +
            (opts.bodyHtml || '') +
            (opts.actionsHtml
                ? '<div class="mpcve-modal__actions' + (opts.actionsClass ? ' ' + opts.actionsClass : '') +
                  '">' + opts.actionsHtml + '</div>'
                : '') +
        '</div>';
    document.body.appendChild(overlay);

    var closed = false;
    function close() {
        if (closed) { return; }
        closed = true;
        if (opts.onClose) { opts.onClose(); }
        overlay.remove();
        document.removeEventListener('keydown', onKey, !!opts.captureEsc);
    }
    function onKey(e) {
        if (e.key === 'Escape') {
            // Поверх нас может висеть confirm/prompt — Escape тогда его, не наш.
            // Сравниваем с собой: сам диалог подтверждения тоже .mpcve-confirm.
            var top = document.querySelector('.mpcve-confirm');
            if (top && top !== overlay) { return; }
            if (opts.captureEsc) { e.stopImmediatePropagation(); }
            close();
            return;
        }
        if (opts.onKey) { opts.onKey(e, close); }
    }
    document.addEventListener('keydown', onKey, !!opts.captureEsc);
    closeOnBackdrop(overlay, close);
    (opts.closeSelectors || ['[data-act=cancel]']).forEach(function (sel) {
        var btn = overlay.querySelector(sel);
        if (btn) { btn.addEventListener('click', function () { close(); }); }
    });

    return {
        overlay: overlay,
        card: overlay.querySelector('.mpcve-modal__card'),
        close: close,
        isClosed: function () { return closed; }
    };
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
        // Свой гард (.mpcve-confirm вместо .mpcve-modal) — диалог подтверждения
        // открывается ПОВЕРХ редактора, но не поверх другого подтверждения.
        var m = openModal({
            guard: '.mpcve-confirm',
            overlayClass: 'mpcve-confirm',
            cardClass: 'mpcve-confirm__card',
            title: opts.title,
            bodyHtml: '<div class="mpcve-confirm__msg"></div>' + (opts.bodyHtml || ''),
            actionsHtml: '<button type="button" class="mpcve-btn" data-act="cancel"></button>' +
                (opts.buttonsHtml || ''),
            actionsClass: opts.actionsClass,
            // capture — гасим клавишу для подлежащего редактора (он тоже слушает
            // keydown на document).
            captureEsc: true,
            onKey: function (e) {
                if (e.key === 'Enter' && opts.onEnter) {
                    e.preventDefault();
                    e.stopImmediatePropagation();
                    opts.onEnter(done, m.overlay);
                }
            },
            // Закрытие штатным путём (Escape / фон / Отмена) = отмена. Если до
            // этого вызвали done(v), промис уже разрешён — этот resolve холостой.
            onClose: function () { resolve(opts.cancelValue); }
        });
        if (!m) { resolve(opts.cancelValue); return; }
        var ov = m.overlay;
        ov.querySelector('.mpcve-confirm__msg').textContent = opts.message;
        ov.querySelector('[data-act=cancel]').textContent = opts.cancelLabel || 'Отмена';

        function done(v) { resolve(v); m.close(); }
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
