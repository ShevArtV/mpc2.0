/**
 * mpcVisualEditor — редактор АДРЕСА ссылки. Маркер (data-mpc-field/rfield/tv)
 * на самом теге <a>/<link> → каттер кладёт значение поля в href (Cutter.php),
 * значит правим именно href. Текст ссылки правится отдельным маркером на
 * элементе ВНУТРИ <a> (обычный текст-редактор). Сохранение — field/save:
 * значение поля = URL (бэк пишет его так же, как при нарезке, лексикон-aware).
 */
import { api } from '../api.js';
import { toast, esc } from '../dom.js';
import { fieldAddress } from '../address.js';

export function openLinkEditor(el) {
    if (document.querySelector('.mpcve-modal')) {
        return;
    }
    var curHref = el.getAttribute('href') || '';

    var overlay = document.createElement('div');
    overlay.className = 'mpcve-modal';
    overlay.innerHTML =
        '<div class="mpcve-modal__card">' +
            '<div class="mpcve-modal__head">Адрес ссылки</div>' +
            '<label class="mpcve-modal__field">URL' +
                '<input type="text" data-f="href" value="' + esc(curHref) + '" placeholder="https://… или /page">' +
            '</label>' +
            '<div class="mpcve-modal__actions">' +
                '<button type="button" class="mpcve-btn" data-act="cancel">Отмена</button>' +
                '<button type="button" class="mpcve-btn mpcve-btn--primary" data-act="save">Сохранить</button>' +
            '</div>' +
        '</div>';
    document.body.appendChild(overlay);

    var hrefInput = overlay.querySelector('[data-f=href]');
    var saveBtn   = overlay.querySelector('[data-act=save]');
    hrefInput.focus();
    hrefInput.select();

    function close() {
        overlay.remove();
        document.removeEventListener('keydown', onKey);
    }
    function onKey(e) {
        if (e.key === 'Escape') { close(); }
        else if (e.key === 'Enter') { e.preventDefault(); saveBtn.click(); }
    }
    document.addEventListener('keydown', onKey);
    overlay.addEventListener('click', function (e) { if (e.target === overlay) { close(); } });
    overlay.querySelector('[data-act=cancel]').addEventListener('click', close);

    saveBtn.addEventListener('click', function () {
        var addr = fieldAddress(el);
        if (!addr) {
            toast('У ссылки нет data-mpc-адреса — некуда сохранять', true);
            return;
        }
        var value = hrefInput.value;
        saveBtn.disabled = true;
        saveBtn.textContent = 'Сохранение…';
        api.post('field/save', { address: addr, value: value, old: curHref }).then(function (res) {
            if (res && res.success) {
                el.setAttribute('href', value);
                toast('Сохранено');
                close();
            } else {
                toast((res && res.message) || 'Ошибка сохранения', true);
                saveBtn.disabled = false;
                saveBtn.textContent = 'Сохранить';
            }
        }).catch(function () {
            toast('Сетевая ошибка', true);
            saveBtn.disabled = false;
            saveBtn.textContent = 'Сохранить';
        });
    });
}
