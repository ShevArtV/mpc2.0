/**
 * mpcVisualEditor — редактор текста С ФОРМАТИРОВАНИЕМ (richtext) в МОДАЛКЕ.
 * Сам редактор — через реестр RTE (createRte), по умолчанию наш execCommand-
 * провайдер; тулбар из allowedTags, картинки через загрузчик. На сохранении html
 * чистится по allowedTags (sanitizeHtml — зеркалит strip_tags бэка). Значение
 * пишется обычным field/save (лексикон-round-trip — как у инлайна).
 */
import { S } from '../state.js';
import { api, uploadMedia } from '../api.js';
import { toast } from '../dom.js';
import { fieldAddress } from '../address.js';
import { createRte, sanitizeHtml } from './rte.js';

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
            '<div class="mpcve-rte__host"></div>' +
            '<div class="mpcve-modal__actions">' +
                '<button type="button" class="mpcve-btn" data-act="cancel">Отмена</button>' +
                '<button type="button" class="mpcve-btn mpcve-btn--primary" data-act="save">Сохранить</button>' +
            '</div>' +
        '</div>';
    document.body.appendChild(overlay);

    var rte = createRte(overlay.querySelector('.mpcve-rte__host'), {
        value: orig,
        allowedTags: S.cfg.allowedTags,
        upload: function (file) { return uploadMedia(file, 'image'); }
    });
    rte.focus();

    function close() { rte.destroy(); overlay.remove(); document.removeEventListener('keydown', onKey); }
    function onKey(e) { if (e.key === 'Escape') { close(); } }
    document.addEventListener('keydown', onKey);
    overlay.addEventListener('click', function (e) { if (e.target === overlay) { close(); } });
    overlay.querySelector('[data-act=cancel]').addEventListener('click', close);

    var saveBtn = overlay.querySelector('[data-act=save]');
    saveBtn.addEventListener('click', function () {
        var value = sanitizeHtml(rte.getHTML(), S.cfg.allowedTags);
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
