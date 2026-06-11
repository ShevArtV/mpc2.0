/**
 * mpcVisualEditor — редактор контакта (data-mpc-cfield: value / caption /
 * attributes). Контакты ГЛОБАЛЬНЫЕ (ресурс «Контакты»), правка требует
 * data-mpc-key на контейнере data-mpc-contact. Модалка-textarea + предупреждение;
 * запись через contact/save (право mpcve_edit_global).
 */
import { api } from '../api.js';
import { toast, esc } from '../dom.js';

var LABEL = { value: 'Значение', caption: 'Подпись', attributes: 'Доп. данные' };

function readVal(el) { return el ? (el.textContent || '').replace(/\s+/g, ' ').trim() : ''; }

export function openContactEditor(el) {
    if (document.querySelector('.mpcve-modal')) { return; }
    var box = el.closest('[data-mpc-contact]');
    if (!box) { return; }
    var key = box.getAttribute('data-mpc-key') || '';
    if (!key) { toast('Контакт без data-mpc-key — добавьте ключ, чтобы править', true); return; }

    var parts = (box.getAttribute('data-mpc-contact') || '').split('|');
    var type = (parts[0] || '').trim();
    var placement = (parts[1] || 'default').trim() || 'default';
    var field = el.getAttribute('data-mpc-cfield') || '';
    var contactValue = readVal(box.querySelector('[data-mpc-cfield="value"]'));

    var overlay = document.createElement('div');
    overlay.className = 'mpcve-modal';
    overlay.innerHTML =
        '<div class="mpcve-modal__card mpcve-modal__card--text">' +
            '<div class="mpcve-modal__head">Контакт: ' + esc(LABEL[field] || field) + ' (' + esc(type) + ')</div>' +
            '<div class="mpcve-modal__note">⚠ Контакт меняется на ВСЕХ страницах сайта.' +
                (field !== 'value' ? ' Размещение: ' + esc(placement) + '.' : '') + '</div>' +
            '<textarea class="mpcve-ta__area" spellcheck="false"></textarea>' +
            '<div class="mpcve-modal__actions">' +
                '<button type="button" class="mpcve-btn" data-act="cancel">Отмена</button>' +
                '<button type="button" class="mpcve-btn mpcve-btn--primary" data-act="save">Сохранить</button>' +
            '</div>' +
        '</div>';
    document.body.appendChild(overlay);
    var ta = overlay.querySelector('.mpcve-ta__area');
    ta.value = readVal(el);
    ta.focus();

    function close() { overlay.remove(); document.removeEventListener('keydown', onKey); }
    function onKey(e) { if (e.key === 'Escape') { close(); } }
    document.addEventListener('keydown', onKey);
    overlay.addEventListener('click', function (e) { if (e.target === overlay) { close(); } });
    overlay.querySelector('[data-act=cancel]').addEventListener('click', close);

    overlay.querySelector('[data-act=save]').addEventListener('click', function () {
        var value = ta.value;
        api.post('contact/save', {
            key: key, type: type, placement: placement, field: field,
            value: value, currentValue: contactValue
        }).then(function (r) {
            if (r && r.success) {
                el.textContent = value;
                toast('Контакт сохранён — «Обновить» для применения');
                close();
            } else {
                toast((r && r.message) || 'Ошибка сохранения', true);
            }
        }).catch(function () { toast('Сетевая ошибка', true); });
    });
}
