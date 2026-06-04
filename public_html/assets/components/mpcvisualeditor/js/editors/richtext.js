/**
 * mpcVisualEditor — редактор текста С ФОРМАТИРОВАНИЕМ (richtext) в МОДАЛКЕ.
 * Сам редактор — через реестр RTE (createRte), по умолчанию наш execCommand-
 * провайдер; тулбар из allowedTags, картинки через загрузчик. На сохранении html
 * чистится по allowedTags (sanitizeHtml — зеркалит strip_tags бэка). Значение
 * пишется обычным field/save (лексикон-round-trip — как у инлайна).
 *
 * Переключатель «</> Код / Визуально»: визуальный RTE ↔ правка СЫРОГО HTML в
 * textarea (теги видны, их можно удалить/заменить). Содержимое синхронизируется
 * при каждом переключении; сохраняем то, что в активном режиме.
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
    var allowed = S.cfg.allowedTags;
    var uploader = function (file) { return uploadMedia(file, 'image'); };

    var overlay = document.createElement('div');
    overlay.className = 'mpcve-modal';
    overlay.innerHTML =
        '<div class="mpcve-modal__card mpcve-modal__card--text">' +
            '<div class="mpcve-modal__head">Текст с форматированием</div>' +
            '<div class="mpcve-rte__host"></div>' +
            '<textarea class="mpcve-ta__area mpcve-rte__source" spellcheck="false" style="display:none"></textarea>' +
            '<div class="mpcve-modal__actions">' +
                '<button type="button" class="mpcve-btn" data-act="toggle" title="Переключить сырая вёрстка / визуальный редактор">&lt;/&gt; Код</button>' +
                '<span class="mpcve-modal__spacer"></span>' +
                '<button type="button" class="mpcve-btn" data-act="cancel">Отмена</button>' +
                '<button type="button" class="mpcve-btn mpcve-btn--primary" data-act="save">Сохранить</button>' +
            '</div>' +
        '</div>';
    document.body.appendChild(overlay);

    var host = overlay.querySelector('.mpcve-rte__host');
    var source = overlay.querySelector('.mpcve-rte__source');
    var toggleBtn = overlay.querySelector('[data-act=toggle]');
    var mode = 'visual';
    var rte = createRte(host, { value: orig, allowedTags: allowed, upload: uploader });
    rte.focus();

    // HTML текущего активного режима (для сохранения и для переключения).
    function currentHtml() {
        return mode === 'source' ? source.value : sanitizeHtml(rte.getHTML(), allowed);
    }

    function toSource() {
        source.value = sanitizeHtml(rte.getHTML(), allowed);
        rte.destroy();
        host.style.display = 'none';
        source.style.display = '';
        source.focus();
        mode = 'source';
        toggleBtn.textContent = 'Визуально';
    }

    function toVisual() {
        var html = source.value;
        source.style.display = 'none';
        host.style.display = '';
        rte = createRte(host, { value: html, allowedTags: allowed, upload: uploader });
        rte.focus();
        mode = 'visual';
        toggleBtn.innerHTML = '&lt;/&gt; Код';
    }

    toggleBtn.addEventListener('click', function () {
        if (mode === 'visual') { toSource(); } else { toVisual(); }
    });

    function close() {
        if (mode === 'visual' && rte) { rte.destroy(); }
        overlay.remove();
        document.removeEventListener('keydown', onKey);
    }
    function onKey(e) { if (e.key === 'Escape') { close(); } }
    document.addEventListener('keydown', onKey);
    overlay.addEventListener('click', function (e) { if (e.target === overlay) { close(); } });
    overlay.querySelector('[data-act=cancel]').addEventListener('click', close);

    var saveBtn = overlay.querySelector('[data-act=save]');
    saveBtn.addEventListener('click', function () {
        var value = currentHtml();
        saveBtn.disabled = true; saveBtn.textContent = 'Сохранение…';
        api.post('field/save', { address: addr, value: value, old: orig }).then(function (r) {
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
