/**
 * mpcVisualEditor — общий помощник сохранения поля (дедуп save-boilerplate
 * редакторов: text/scalar/tags/textarea/link повторяли disabled→'Сохранение…'→
 * api.post('field/save')→toast/onSuccess/re-enable). Поведение идентично прежнему
 * инлайну, включая .catch сетевой ошибки.
 *
 * @param {string} addr  адрес поля (fieldAddress)
 * @param {*}      value значение для записи
 * @param {*}      old   прежнее значение (для аудита); null → ''
 * @param {{btn?:HTMLButtonElement, onSuccess?:function}} opts
 * @returns {Promise} ответ коннектора
 */
import { api } from './api.js';
import { toast } from './dom.js';

export function doSave(addr, value, old, opts) {
    opts = opts || {};
    var btn = opts.btn;
    function reenable() {
        if (btn) { btn.disabled = false; btn.textContent = 'Сохранить'; }
    }
    if (btn) { btn.disabled = true; btn.textContent = 'Сохранение…'; }

    return api.post('field/save', { address: addr, value: value, old: old == null ? '' : old })
        .then(function (r) {
            if (r && r.success) {
                toast('Сохранено');
                if (opts.onSuccess) { opts.onSuccess(r); }
            } else {
                toast((r && r.message) || 'Ошибка сохранения', true);
                reenable();
            }
            return r;
        })
        .catch(function () {
            toast('Сетевая ошибка', true);
            reenable();
        });
}
