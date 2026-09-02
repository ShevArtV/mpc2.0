/**
 * mpcVisualEditor — простые скалярные TV-контролы в модалке: number, date.
 * Один редактор, ветвление по data-mpcve-type. Значение пишем сырым (raw=1) —
 * число/дата не лексиконятся.
 */
import { toast, esc, openModal } from '../dom.js';
import { fieldAddress } from '../address.js';
import { doSave } from '../save.js';

// "YYYY-MM-DD[ HH:MM:SS]" → значение <input type=datetime-local> ("…THH:MM").
// Непарсящийся формат → '' (тогда показываем обычный текст-инпут, не портим).
function toLocalInput(s) {
    var m = String(s || '').trim().match(/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?)?/);
    if (!m) { return ''; }
    return m[1] + '-' + m[2] + '-' + m[3] + 'T' + (m[4] || '00') + ':' + (m[5] || '00');
}
// Обратно в формат MODX-даты "YYYY-MM-DD HH:MM:SS".
function fromLocalInput(v) {
    var m = String(v || '').match(/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})/);
    return m ? (m[1] + '-' + m[2] + '-' + m[3] + ' ' + m[4] + ':' + m[5] + ':00') : String(v || '');
}

export function openScalarEditor(el) {
    var addr = fieldAddress(el);
    if (!addr) { toast('Нет адреса поля', true); return; }

    var type = el.getAttribute('data-mpcve-type') || 'text';
    var cur = (el.textContent || '').trim();

    var controlHtml, toStore;
    if (type === 'date') {
        var local = toLocalInput(cur);
        if (cur !== '' && local === '') {
            // Нестандартный формат даты — правим как текст, чтобы не исказить.
            controlHtml = '<input type="text" class="mpcve-lb__input mpcve-ta__area" value="' + esc(cur) + '">';
            toStore = function (root) { return root.querySelector('input').value.trim(); };
        } else {
            controlHtml = '<input type="datetime-local" class="mpcve-lb__input mpcve-ta__area" value="' + esc(local) + '">';
            toStore = function (root) { return fromLocalInput(root.querySelector('input').value); };
        }
    } else {
        // number
        controlHtml = '<input type="number" step="any" class="mpcve-lb__input mpcve-ta__area" value="' + esc(cur) + '">';
        toStore = function (root) { return root.querySelector('input').value.trim(); };
    }

    var m = openModal({
        cardClass: 'mpcve-modal__card--text',
        title: type === 'date' ? 'Дата' : 'Число',
        bodyHtml: controlHtml,
        actionsHtml:
            '<button type="button" class="mpcve-btn" data-act="cancel">Отмена</button>' +
            '<button type="button" class="mpcve-btn mpcve-btn--primary" data-act="save">Сохранить</button>'
    });
    if (!m) { return; }
    var overlay = m.overlay;
    var close = m.close;

    var card = m.card;
    var input = overlay.querySelector('input');
    input.focus();

    var saveBtn = overlay.querySelector('[data-act=save]');
    saveBtn.addEventListener('click', function () {
        var value = toStore(card);
        // НЕ raw: number/date — контентное значение (не enum-ключ). Если поле
        // лексиконизировано — writer пишет в лексикон под существующим ключом
        // (как text.js); иначе литералом. raw=1 затёр бы ключ в конфиге.
        doSave(addr, value, cur, { btn: saveBtn, onSuccess: function () {
            el.textContent = value;
            close();
        } });
    });
}
