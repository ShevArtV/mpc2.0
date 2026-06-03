/**
 * mpcVisualEditor — инлайн-редактор текста (contenteditable) + пер-полевое
 * сохранение через field/save.
 */
import { api } from '../api.js';
import { toast } from '../dom.js';
import { fieldAddress } from '../address.js';

// Пер-полевое сохранение: адрес поля + значение → field/save → FieldWriter.
// old — прежнее значение (для журнала/отката).
export function saveField(el, old) {
    var addr = fieldAddress(el);
    if (!addr) {
        return;
    }
    var value = el.getAttribute('data-mpcve-type') === 'richtext' ? el.innerHTML : el.innerText;
    api.post('field/save', { address: addr, value: value, old: old == null ? '' : old }).then(function (res) {
        if (res && res.success) {
            toast('Сохранено');
        } else {
            toast((res && res.message) || 'Ошибка сохранения', true);
        }
    }).catch(function () {
        toast('Сетевая ошибка', true);
    });
}

export function openTextEditor(el) {
    if (el.getAttribute('contenteditable') === 'true') {
        return;
    }
    var orig = el.innerHTML;
    el.setAttribute('contenteditable', 'true');
    el.setAttribute('spellcheck', 'false');
    el.classList.add('mpcve-editing');
    el.focus();
    var sel = window.getSelection();
    if (sel && el.childNodes.length) {
        var range = document.createRange();
        range.selectNodeContents(el);
        sel.removeAllRanges();
        sel.addRange(range);
    }

    function cleanup() {
        el.removeAttribute('contenteditable');
        el.removeAttribute('spellcheck');
        el.classList.remove('mpcve-editing');
        el.removeEventListener('keydown', onKey);
        el.removeEventListener('blur', onBlur);
    }
    function onKey(e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            el.blur();
        } else if (e.key === 'Escape') {
            e.preventDefault();
            el.innerHTML = orig;
            cleanup();
        }
    }
    function onBlur() {
        cleanup();
        if (el.innerHTML !== orig) {
            saveField(el, orig);
        }
    }
    el.addEventListener('keydown', onKey);
    el.addEventListener('blur', onBlur);
}
