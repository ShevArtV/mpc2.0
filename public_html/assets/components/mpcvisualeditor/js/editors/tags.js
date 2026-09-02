/**
 * mpcVisualEditor — редактор тегов (TV tag/tags/autotag). Чипсы с удалением +
 * поле ввода (Enter/запятая добавляют). Хранение — список через запятую (дефолтный
 * разделитель MODX tag-TV). Пишем сырым (raw=1).
 */
import { toast, esc, closeOnBackdrop } from '../dom.js';
import { fieldAddress } from '../address.js';
import { doSave } from '../save.js';

function splitTags(s) {
    return String(s || '').split(/\s*[,|]\s*/).map(function (t) { return t.trim(); }).filter(Boolean);
}

export function openTagsEditor(el) {
    if (document.querySelector('.mpcve-modal')) { return; }
    var addr = fieldAddress(el);
    if (!addr) { toast('Нет адреса поля', true); return; }

    var cur = (el.textContent || '').trim();
    var tags = splitTags(cur);

    var overlay = document.createElement('div');
    overlay.className = 'mpcve-modal';
    overlay.innerHTML =
        '<div class="mpcve-modal__card mpcve-modal__card--text">' +
            '<div class="mpcve-modal__head">Теги</div>' +
            '<div class="mpcve-tags"></div>' +
            '<input type="text" class="mpcve-tags__input mpcve-ta__area" placeholder="Добавить тег и нажать Enter…">' +
            '<div class="mpcve-modal__actions">' +
                '<button type="button" class="mpcve-btn" data-act="cancel">Отмена</button>' +
                '<button type="button" class="mpcve-btn mpcve-btn--primary" data-act="save">Сохранить</button>' +
            '</div>' +
        '</div>';
    document.body.appendChild(overlay);

    var box = overlay.querySelector('.mpcve-tags');
    var input = overlay.querySelector('.mpcve-tags__input');

    function renderChips() {
        box.innerHTML = tags.map(function (t, i) {
            return '<span class="mpcve-tags__chip">' + esc(t) +
                '<button type="button" class="mpcve-tags__del" data-i="' + i + '">×</button></span>';
        }).join('');
        box.querySelectorAll('.mpcve-tags__del').forEach(function (b) {
            b.addEventListener('click', function () {
                tags.splice(parseInt(b.getAttribute('data-i'), 10), 1);
                renderChips();
            });
        });
    }
    function addFromInput() {
        splitTags(input.value).forEach(function (t) { if (tags.indexOf(t) === -1) { tags.push(t); } });
        input.value = '';
        renderChips();
    }
    renderChips();
    input.focus();
    input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ',') { e.preventDefault(); addFromInput(); }
    });

    function close() { overlay.remove(); document.removeEventListener('keydown', onKey); }
    function onKey(e) { if (e.key === 'Escape') { close(); } }
    document.addEventListener('keydown', onKey);
    closeOnBackdrop(overlay, function () { close(); });
    overlay.querySelector('[data-act=cancel]').addEventListener('click', close);

    var saveBtn = overlay.querySelector('[data-act=save]');
    saveBtn.addEventListener('click', function () {
        addFromInput(); // подхватить недоподтверждённый ввод
        var value = tags.join(',');
        // НЕ raw: теги — контентное значение; лексиконизировано → writer пишет в
        // лексикон под существующим ключом (как text.js), иначе литералом.
        doSave(addr, value, cur, { btn: saveBtn, onSuccess: function () {
            el.textContent = tags.join(', ');
            close();
        } });
    });
}
