/**
 * mpcVisualEditor — редактор цвета (config/TV типа color/colorpicker). Нативный
 * <input type="color"> + текст-инпут для hex, синхронизированы (как виджет
 * colorpickerfield настроек data-mpc-info). Значение пишем сырым (raw=1): hex-код
 * не переводимый, лексиконить нечего (как enum-ключи в listbox.js).
 */
import { toast, esc } from '../dom.js';
import { fieldAddress } from '../address.js';
import { doSave } from '../save.js';

// Любая запись цвета (#RRGGBB / RRGGBB) → #RRGGBB для <input type=color>. Он
// принимает только полный 6-значный hex; rgb()/var()/короткий hex невалидны →
// fallback #000000 для пикера, но текст-инпут хранит ИСХОДНОЕ значение как есть.
function toColorInput(v) {
    v = String(v || '').trim();
    return /^#?[0-9a-fA-F]{6}$/.test(v) ? (v.charAt(0) === '#' ? v : '#' + v) : '#000000';
}

export function openColorEditor(el) {
    if (document.querySelector('.mpcve-modal')) { return; }
    var addr = fieldAddress(el);
    if (!addr) { toast('Нет адреса поля', true); return; }
    addr.raw = 1; // цвет — код, не лексиконим (как listbox enum-ключи)

    var cur = (el.textContent || '').trim();

    var overlay = document.createElement('div');
    overlay.className = 'mpcve-modal';
    overlay.innerHTML =
        '<div class="mpcve-modal__card mpcve-modal__card--text">' +
            '<div class="mpcve-modal__head">Цвет</div>' +
            '<div class="mpcve-set__widget">' +
                '<input type="color" class="mpcve-set__color" value="' + esc(toColorInput(cur)) + '">' +
                '<input type="text" class="mpcve-set__input" value="' + esc(cur) + '" spellcheck="false">' +
            '</div>' +
            '<div class="mpcve-modal__actions">' +
                '<button type="button" class="mpcve-btn" data-act="cancel">Отмена</button>' +
                '<button type="button" class="mpcve-btn mpcve-btn--primary" data-act="save">Сохранить</button>' +
            '</div>' +
        '</div>';
    document.body.appendChild(overlay);

    var card = overlay.querySelector('.mpcve-modal__card');
    var color = card.querySelector('.mpcve-set__color');
    var text = card.querySelector('.mpcve-set__input');
    // Двусторонняя синхронизация: пик цвета → текст; ручной валидный hex → пикер
    // (невалидную/нестандартную запись — rgb()/var() — пикер не трогает).
    color.addEventListener('input', function () { text.value = color.value; });
    text.addEventListener('input', function () {
        if (/^#?[0-9a-fA-F]{6}$/.test(text.value.trim())) { color.value = toColorInput(text.value); }
    });
    text.focus();

    function close() { overlay.remove(); document.removeEventListener('keydown', onKey); }
    function onKey(e) { if (e.key === 'Escape') { close(); } }
    document.addEventListener('keydown', onKey);
    overlay.addEventListener('click', function (e) { if (e.target === overlay) { close(); } });
    card.querySelector('[data-act=cancel]').addEventListener('click', close);

    var saveBtn = card.querySelector('[data-act=save]');
    saveBtn.addEventListener('click', function () {
        // MODX-админка (colorpicker) хранит hex БЕЗ ведущего # — срезаем, чтобы
        // значение совпадало с тем, что пишет нативный инпут настройки.
        var value = text.value.trim().replace(/^#/, '');
        doSave(addr, value, cur, { btn: saveBtn, onSuccess: function () {
            el.textContent = value;
            close();
        } });
    });
}
