/**
 * mpcVisualEditor — редактор многострочного текста (textarea) в МОДАЛКЕ.
 * Инлайново правим только простые однострочные поля (text); многострочные —
 * в окне, чтобы было удобно и не ломалась вёрстка. Значение — plain text.
 */
import { api } from '../api.js';
import { toast } from '../dom.js';
import { fieldAddress } from '../address.js';

export function openTextareaEditor(el) {
    if (document.querySelector('.mpcve-modal')) { return; }
    var addr = fieldAddress(el);
    if (!addr) { toast('Нет адреса поля', true); return; }
    var cur = el.innerText; // текущий многострочный текст (как видно на странице)

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
        saveBtn.disabled = true; saveBtn.textContent = 'Сохранение…';
        api.post('field/save', { address: addr, value: value, old: cur }).then(function (r) {
            if (r && r.success) {
                el.textContent = value; // обновляем страницу без перезагрузки
                toast('Сохранено');
                close();
            } else {
                toast((r && r.message) || 'Ошибка сохранения', true);
                saveBtn.disabled = false; saveBtn.textContent = 'Сохранить';
            }
        }).catch(function () {
            toast('Сетевая ошибка', true);
            saveBtn.disabled = false; saveBtn.textContent = 'Сохранить';
        });
    });
}
