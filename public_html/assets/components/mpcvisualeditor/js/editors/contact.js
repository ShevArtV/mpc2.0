/**
 * mpcVisualEditor — редактор контакта (data-mpc-cfield: value / caption /
 * attributes / icon). Контакты ГЛОБАЛЬНЫЕ (ресурс «Контакты»), правка требует
 * data-mpc-key на контейнере data-mpc-contact. Текстовые поля — модалка-textarea;
 * icon — выбор картинки через file manager. Запись через contact/save
 * (право mpcve_edit_global).
 */
import { api } from '../api.js';
import { toast, esc, openModal } from '../dom.js';
import { openFileManager } from '../filemanager.js';

var LABEL = { value: 'Значение', caption: 'Подпись', attributes: 'Доп. данные', icon: 'Иконка' };

function readVal(el) { return el ? (el.textContent || '').replace(/\s+/g, ' ').trim() : ''; }

// Классы элемента, РАЗДЕЛЁННЫЕ на пользовательские и служебные редакторские
// (mpcve-*, навешивает mark.js в edit-mode). Служебные не должны попадать в
// сохраняемое значение attributes, но в DOM их сохраняем (элемент остаётся
// помеченным редактируемым).
function splitClasses(el) {
    var all = (el.getAttribute('class') || '').split(/\s+/).filter(Boolean);
    var user = [], svc = [];
    all.forEach(function (c) { (c.indexOf('mpcve-') === 0 ? svc : user).push(c); });
    return { user: user, svc: svc };
}

export function openContactEditor(el) {
    var box = el.closest('[data-mpc-contact]');
    if (!box) { return; }
    var key = box.getAttribute('data-mpc-key') || '';
    if (!key) { toast('Контакт без data-mpc-key — добавьте ключ, чтобы править', true); return; }

    var parts = (box.getAttribute('data-mpc-contact') || '').split('|');
    var type = (parts[0] || '').trim();
    var placement = (parts[1] || 'default').trim() || 'default';
    var field = el.getAttribute('data-mpc-cfield') || '';
    var contactValue = readVal(box.querySelector('[data-mpc-cfield="value"]'));

    // Иконка — отдельное image-поле: выбор картинки через file manager (а не
    // textarea с сырым путём). Значение = URL, идёт тем же contact/save.
    if (field === 'icon') {
        openFileManager({ accept: 'image', title: 'Иконка контакта' }).then(function (file) {
            if (!file) { return; }
            api.post('contact/save', {
                key: key, type: type, placement: placement, field: 'icon',
                value: file.url, currentValue: contactValue
            }).then(function (r) {
                if (r && r.success) {
                    if (el.tagName === 'IMG') { el.setAttribute('src', file.url); }
                    toast('Иконка сохранена — «Обновить» для применения');
                } else {
                    toast((r && r.message) || 'Ошибка сохранения', true);
                }
            }).catch(function () { toast('Сетевая ошибка', true); });
        });
        return;
    }

    // Иконка-классом: не-value поле (типично attributes) на пустом элементе
    // (нет текста/html), но с атрибутом class — значение хранится в class
    // (симметрично грабежу/каттеру, см. ContactUpdater/Cutter). Правим class-строку,
    // а не текст внутри элемента.
    var classMode = field !== 'value' && readVal(el) === '' && el.hasAttribute('class');
    var headLabel = classMode ? 'CSS-классы' : (LABEL[field] || field);

    var m = openModal({
        cardClass: 'mpcve-modal__card--text',
        titleHtml: 'Контакт: ' + esc(headLabel) + ' (' + esc(type) + ')',
        bodyHtml:
            '<div class="mpcve-modal__note">⚠ Контакт меняется на ВСЕХ страницах сайта.' +
                (field !== 'value' ? ' Размещение: ' + esc(placement) + '.' : '') +
                (classMode ? ' Значение — CSS-классы элемента.' : '') + '</div>' +
            '<textarea class="mpcve-ta__area" spellcheck="false"></textarea>',
        actionsHtml:
            '<button type="button" class="mpcve-btn" data-act="cancel">Отмена</button>' +
            '<button type="button" class="mpcve-btn mpcve-btn--primary" data-act="save">Сохранить</button>'
    });
    if (!m) { return; }
    var overlay = m.overlay;
    var close = m.close;
    var ta = overlay.querySelector('.mpcve-ta__area');
    // В class-режиме показываем ТОЛЬКО пользовательские классы (без mpcve-*).
    ta.value = classMode ? splitClasses(el).user.join(' ') : readVal(el);
    ta.focus();

    overlay.querySelector('[data-act=save]').addEventListener('click', function () {
        var value = ta.value;
        api.post('contact/save', {
            key: key, type: type, placement: placement, field: field,
            value: value, currentValue: contactValue
        }).then(function (r) {
            if (r && r.success) {
                if (classMode) {
                    // Новые пользовательские классы + сохранённые служебные mpcve-*
                    // (элемент остаётся помеченным редактируемым до перезагрузки).
                    var svc = splitClasses(el).svc;
                    el.setAttribute('class', value.split(/\s+/).filter(Boolean).concat(svc).join(' '));
                } else {
                    el.textContent = value;
                }
                toast('Контакт сохранён — «Обновить» для применения');
                close();
            } else {
                toast((r && r.message) || 'Ошибка сохранения', true);
            }
        }).catch(function () { toast('Сетевая ошибка', true); });
    });
}
