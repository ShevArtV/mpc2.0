/**
 * Предупреждение об области правки секции и явная локализация наследуемой.
 * Единая точка нужна и модальным, и inline-редакторам.
 */
import { S } from './state.js';
import { api, loadConfig } from './api.js';
import { choiceDialog, confirmDialog, toast } from './dom.js';
import { sectionScope, sectionKeyOf } from './address.js';

function localize(name) {
    return api.post('section/op', {
        op: 'copy_one',
        section: name,
        resourceId: (S.cfg && S.cfg.resourceId) || 0
    }).then(function (r) {
        if (!r || !r.success) {
            toast((r && r.message) || 'Не удалось локализовать секцию', true);
            return false;
        }
        return loadConfig().then(function () {
            toast('Секция локализована для этой страницы');
            return true;
        });
    }).catch(function () {
        toast('Сетевая ошибка', true);
        return false;
    });
}

export function openForSection(name, isStatic, opener) {
    var scope = sectionScope(name, !!isStatic);
    if (scope === 'local' || scope === 'global') {
        opener();
        return Promise.resolve(true);
    }
    if (scope === 'type-resource') {
        return confirmDialog('Изменения затронут все страницы данного типа', {
            title: 'Область изменений',
            okLabel: 'Продолжить'
        }).then(function (ok) {
            if (ok) { opener(); }
            return ok;
        });
    }
    return choiceDialog('Изменения затронут все страницы этого типа', {
        title: 'Область изменений',
        choices: [
            { key: 'continue', label: 'Продолжить', primary: true },
            { key: 'localize', label: 'Локализовать секцию' }
        ],
        cancelLabel: 'Отмена'
    }).then(function (choice) {
        if (choice === 'continue') {
            opener();
            return true;
        }
        if (choice === 'localize') {
            return localize(name).then(function (ok) {
                if (ok) { opener(); }
                return ok;
            });
        }
        return false;
    });
}

export function openForElement(el, opener) {
    var section = el && el.closest ? el.closest('[data-mpc-section]') : null;
    if (!section) {
        opener();
        return Promise.resolve(true);
    }
    return openForSection(
        sectionKeyOf(section),
        section.hasAttribute('data-mpc-static'),
        opener
    );
}
