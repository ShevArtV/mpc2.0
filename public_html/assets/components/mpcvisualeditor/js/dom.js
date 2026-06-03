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
