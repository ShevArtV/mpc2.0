/**
 * mpcVisualEditor — редактор текста С ФОРМАТИРОВАНИЕМ (richtext) в МОДАЛКЕ с RTE.
 * Тулбар (общий, editors/rte.js) поверх contenteditable. Значение — HTML
 * (innerHTML), пишется через field/save (лексикон-round-trip — как у инлайна).
 */
import { api } from '../api.js';
import { toast } from '../dom.js';
import { fieldAddress } from '../address.js';
import { rteToolbarHtml, wireRteToolbar } from './rte.js';

export function openRichtextEditor(el) {
    if (document.querySelector('.mpcve-modal')) { return; }
    var addr = fieldAddress(el);
    if (!addr) { toast('Нет адреса поля', true); return; }
    var orig = el.innerHTML;

    var overlay = document.createElement('div');
    overlay.className = 'mpcve-modal';
    overlay.innerHTML =
        '<div class="mpcve-modal__card mpcve-modal__card--text">' +
            '<div class="mpcve-modal__head">Текст с форматированием</div>' +
            rteToolbarHtml() +
            '<div class="mpcve-rte__area" contenteditable="true" spellcheck="false"></div>' +
            '<div class="mpcve-modal__actions">' +
                '<button type="button" class="mpcve-btn" data-act="cancel">Отмена</button>' +
                '<button type="button" class="mpcve-btn mpcve-btn--primary" data-act="save">Сохранить</button>' +
            '</div>' +
        '</div>';
    document.body.appendChild(overlay);

    var area = overlay.querySelector('.mpcve-rte__area');
    area.innerHTML = orig;
    area.focus();

    function close() { overlay.remove(); document.removeEventListener('keydown', onKey); }
    function onKey(e) { if (e.key === 'Escape') { close(); } }
    document.addEventListener('keydown', onKey);
    overlay.addEventListener('click', function (e) { if (e.target === overlay) { close(); } });
    overlay.querySelector('[data-act=cancel]').addEventListener('click', close);

    wireRteToolbar(overlay.querySelector('.mpcve-rte__toolbar'), area);

    var saveBtn = overlay.querySelector('[data-act=save]');
    saveBtn.addEventListener('click', function () {
        var value = area.innerHTML;
        saveBtn.disabled = true; saveBtn.textContent = 'Сохранение…';
        api.post('field/save', { address: addr, value: value }).then(function (r) {
            if (r && r.success) {
                el.innerHTML = value; // обновляем страницу без перезагрузки
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
