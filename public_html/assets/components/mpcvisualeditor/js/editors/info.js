/**
 * mpcVisualEditor — редактор служебной информации (data-mpc-info): ГЛОБАЛЬНАЯ
 * настройка сайта (системная / контекстная / ClientConfig). Правка в модалке-
 * textarea, запись через info/save (право mpcve_edit_global). Модалка несёт
 * предупреждение: значение меняется на ВСЕХ страницах.
 *
 * Значение по виду элемента — зеркало грабера (InformationUpdater): link → href,
 * img → src, иначе содержимое.
 */
import { api } from '../api.js';
import { toast, esc } from '../dom.js';

function tag(el) { return el.tagName ? el.tagName.toLowerCase() : ''; }

function currentValue(el) {
    var t = tag(el);
    if (t === 'link') { return el.getAttribute('href') || ''; }
    if (t === 'img') { return el.getAttribute('src') || ''; }
    return (el.querySelector && el.querySelector('*')) ? el.innerHTML : (el.textContent || '');
}
function applyValue(el, v) {
    var t = tag(el);
    if (t === 'link') { el.setAttribute('href', v); return; }
    if (t === 'img') { el.setAttribute('src', v); return; }
    if (el.querySelector && el.querySelector('*')) { el.innerHTML = v; } else { el.textContent = v; }
}

export function openInfoEditor(el) {
    if (document.querySelector('.mpcve-modal')) { return; }
    var key = el.getAttribute('data-mpc-info') || '';
    var hasCtx = el.hasAttribute('data-mpc-ctx');
    var ctxAttr = hasCtx ? (el.getAttribute('data-mpc-ctx') || '') : null;

    var overlay = document.createElement('div');
    overlay.className = 'mpcve-modal';
    overlay.innerHTML =
        '<div class="mpcve-modal__card mpcve-modal__card--text">' +
            '<div class="mpcve-modal__head">Настройка сайта: ' + esc(key) + '</div>' +
            '<div class="mpcve-modal__note">⚠ Значение меняется на ВСЕХ страницах сайта.' +
                (hasCtx ? ' Контекст: ' + esc(ctxAttr || 'текущий') + '.' : '') + '</div>' +
            '<textarea class="mpcve-ta__area" spellcheck="false"></textarea>' +
            '<div class="mpcve-modal__actions">' +
                '<button type="button" class="mpcve-btn" data-act="cancel">Отмена</button>' +
                '<button type="button" class="mpcve-btn mpcve-btn--primary" data-act="save">Сохранить</button>' +
            '</div>' +
        '</div>';
    document.body.appendChild(overlay);

    var ta = overlay.querySelector('.mpcve-ta__area');
    ta.value = currentValue(el);
    ta.focus();

    function close() { overlay.remove(); document.removeEventListener('keydown', onKey); }
    function onKey(e) { if (e.key === 'Escape') { close(); } }
    document.addEventListener('keydown', onKey);
    overlay.addEventListener('click', function (e) { if (e.target === overlay) { close(); } });
    overlay.querySelector('[data-act=cancel]').addEventListener('click', close);

    overlay.querySelector('[data-act=save]').addEventListener('click', function () {
        var value = ta.value;
        var payload = { key: key, value: value };
        if (hasCtx) { payload.ctx = ctxAttr; }
        api.post('info/save', payload).then(function (r) {
            if (r && r.success) {
                applyValue(el, value);
                toast('Настройка сохранена — «Обновить» для применения');
                close();
            } else {
                toast((r && r.message) || 'Ошибка сохранения', true);
            }
        }).catch(function () { toast('Сетевая ошибка', true); });
    });
}
