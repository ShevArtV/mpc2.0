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
    // richtext всегда HTML. Простой text-field тоже может содержать инлайн-
    // разметку (<b>/<i>/<a>…) — грабер сохраняет её (FieldValueExtractor::getValue:
    // есть дочерние теги → HTML детей). Если в поле есть разметка, отдаём innerHTML,
    // чтобы не срезать её на innerText; чистый текст — innerText (без экранирования &/<).
    var keepHtml = el.getAttribute('data-mpcve-type') === 'richtext'
        || (el.querySelector && el.querySelector('*'));
    var value = keepHtml ? el.innerHTML : el.innerText;
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
    // Поле с разметкой (<b>/<i>/<a>…): правим ИСХОДНЫЙ HTML — показываем теги как
    // редактируемый текст (`<b>Текст</b>`), чтобы их можно было удалить/заменить.
    // Плоский текст правится как обычно (WYSIWYG, тегов нет). На закрытии сырой
    // HTML парсится обратно в реальную разметку.
    var sourceMode = !!(el.querySelector && el.querySelector('*'));
    if (sourceMode) {
        el.textContent = orig;
    }
    el.setAttribute('contenteditable', 'true');
    el.setAttribute('spellcheck', 'false');
    el.classList.add('mpcve-editing');
    el.focus();
    var sel = window.getSelection();
    if (sel && el.childNodes.length) {
        var range = document.createRange();
        range.selectNodeContents(el);
        // В source-режиме НЕ выделяем всё — каретка в конец, иначе первый ввод
        // затрёт весь сырой HTML. Плоский текст — select-all (удобно перепечатать).
        if (sourceMode) {
            range.collapse(false);
        }
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
            el.innerHTML = orig; // вернуть исходный рендер
            cleanup();
        }
    }
    function onBlur() {
        cleanup();
        if (sourceMode) {
            // Введённый сырой HTML → реальная разметка (парсинг браузером).
            var src = el.innerText;
            el.innerHTML = src;
            // saveField прочитает el.innerHTML (теперь = реальная разметка).
            if (el.innerHTML !== orig) {
                saveField(el, orig);
            }
        } else if (el.innerHTML !== orig) {
            saveField(el, orig);
        }
    }
    el.addEventListener('keydown', onKey);
    el.addEventListener('blur', onBlur);
}
