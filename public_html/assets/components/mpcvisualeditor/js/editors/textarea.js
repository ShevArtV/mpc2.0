/**
 * mpcVisualEditor — редактор многострочного текста (textarea) в МОДАЛКЕ.
 * Инлайново правим только простые однострочные поля (text); многострочные —
 * в окне, чтобы было удобно и не ломалась вёрстка. Значение — plain text.
 */
import { S } from '../state.js';
import { toast } from '../dom.js';
import { fieldAddress } from '../address.js';
import { doSave } from '../save.js';
import { sanitizeHtml } from './rte.js';

export function openTextareaEditor(el) {
    if (document.querySelector('.mpcve-modal')) { return; }
    var addr = fieldAddress(el);
    if (!addr) { toast('Нет адреса поля', true); return; }
    // Поле с разметкой (<b>/<a>/…): правим ИСХОДНЫЙ HTML (теги видны как текст,
    // их можно удалить/заменить), на сохранении парсится обратно. Плоский текст —
    // как обычно (multiline plain). Симметрично инлайн-редактору text.js.
    var sourceMode = !!(el.querySelector && el.querySelector('*'));
    var cur = sourceMode ? el.innerHTML : el.innerText;

    var overlay = document.createElement('div');
    overlay.className = 'mpcve-modal';
    overlay.innerHTML =
        '<div class="mpcve-modal__card mpcve-modal__card--text">' +
            '<div class="mpcve-modal__head">Текст</div>' +
            '<textarea class="mpcve-ta__area" spellcheck="false"></textarea>' +
            '<div class="mpcve-modal__actions">' +
                '<button type="button" class="mpcve-btn" data-act="cancel">Отмена</button>' +
                '<button type="button" class="mpcve-btn mpcve-btn--primary" data-act="save">Сохранить</button>' +
            '</div>' +
        '</div>';
    document.body.appendChild(overlay);

    var ta = overlay.querySelector('.mpcve-ta__area');
    ta.value = cur;
    ta.focus();

    function close() { overlay.remove(); document.removeEventListener('keydown', onKey); }
    function onKey(e) { if (e.key === 'Escape') { close(); } }
    document.addEventListener('keydown', onKey);
    overlay.addEventListener('click', function (e) { if (e.target === overlay) { close(); } });
    overlay.querySelector('[data-act=cancel]').addEventListener('click', close);

    var saveBtn = overlay.querySelector('[data-act=save]');
    saveBtn.addEventListener('click', function () {
        var value = ta.value;
        doSave(addr, value, cur, { btn: saveBtn, onSuccess: function () {
            // source: сырой HTML → реальная разметка; plain: текст как есть.
            if (sourceMode) {
                // санитайз перед живым DOM (Sf2): on*/javascript: режутся.
                el.innerHTML = sanitizeHtml(value, (S.cfg.allowedTags && S.cfg.allowedTags.length) ? S.cfg.allowedTags : undefined);
            } else { el.textContent = value; }
            close();
        } });
    });
}
