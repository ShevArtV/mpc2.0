/**
 * mpcVisualEditor — редактор СТРОК списка (add / delete / move), вкл. ВЛОЖЕННЫЕ
 * списки. Операции пишутся в mpc_config (row/op → ConfigFieldWriter, по path для
 * вложенных). Изменения отражаем в DOM средствами JS, БЕЗ автоперезагрузки:
 *   delete — убираем узел строки; move — переставляем узлы;
 *   add    — клонируем первую строку как ШАБЛОН, чистим значения (каждое поле →
 *            плейсхолдер «клик — заполнить»), вставляем; вложенная структура
 *            сохраняется (бэк сидирует строку тем же deep-clear) → дочерний
 *            список нового элемента сразу заполняем.
 * Перезагрузка — только вручную (кнопка «Обновить»), когда нужно увидеть точный
 * рендер шаблона (напр. лексикон/условные блоки).
 * Img-список даёт 📷 на строку (открывает редактор картинки rows[idx].img).
 */
import { api } from '../api.js';
import { toast, esc, isMedia } from '../dom.js';
import { listAddress, listRows, configRowCount, rowPreview, isListEl } from '../address.js';
import { markFieldsWithin } from '../mark.js';
import { openImageEditor } from './image.js';

var BLANK_IMG = 'data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw==';

// Очистка картинки клона: прозрачный 1x1 + снятие lazyload/expand-триггеров
// (data-lazy/data-svg), иначе скрипты сайта (expand.js) спотыкаются на пустой
// картинке (fetch несуществующего → нет <svg> → краш).
function blankImg(m) {
    if (!m || !m.removeAttribute) { return; }
    m.removeAttribute('srcset');
    m.removeAttribute('data-lazy');
    m.removeAttribute('data-svg');
    if (m.tagName && m.tagName.toLowerCase() === 'img') { m.setAttribute('src', BLANK_IMG); }
}

// Клон строки-шаблона с очищенными значениями: текстовые поля → пусто (CSS-
// плейсхолдер), медиа → прозрачный 1x1 (клик = загрузка). Контейнеры вложенных
// списков НЕ чистим (сохраняем их строки-структуру); их поля чистятся как
// отдельные маркеры. Снимаем contenteditable/editing с клона.
function cloneBlankRow(templateRow) {
    var clone = templateRow.cloneNode(true);
    var markers = [];
    if (clone.matches && clone.matches('[data-mpc-field],[data-mpc-rfield],[data-mpc-tv],[data-mpc-field-1],[data-mpc-field-2],[data-mpc-field-3]')) {
        markers.push(clone);
    }
    Array.prototype.push.apply(markers,
        clone.querySelectorAll('[data-mpc-field],[data-mpc-rfield],[data-mpc-tv],[data-mpc-field-1],[data-mpc-field-2],[data-mpc-field-3]'));
    markers.forEach(function (el) {
        if (isMedia(el)) {
            if (el.tagName.toLowerCase() === 'img') {
                blankImg(el);
            } else if (el.querySelectorAll) {
                el.querySelectorAll('img,source').forEach(blankImg);
            }
        } else if (!isListEl(el)) {
            el.textContent = ''; // скаляр-лист → пусто → плейсхолдер
        }
        el.removeAttribute('contenteditable');
        el.classList.remove('mpcve-editing');
    });
    return clone;
}

export function openRowsEditor(listEl) {
    if (document.querySelector('.mpcve-modal')) { return; }
    var addr = listAddress(listEl);
    if (!addr.section || !addr.parentField) {
        toast('Не удалось определить адрес списка', true);
        return;
    }

    var overlay = document.createElement('div');
    overlay.className = 'mpcve-modal';
    overlay.innerHTML =
        '<div class="mpcve-modal__card mpcve-modal__card--wide">' +
            '<div class="mpcve-modal__head">Строки списка · ' + esc(addr.parentField) + '</div>' +
            '<div class="mpcve-rows"></div>' +
            '<div class="mpcve-modal__actions">' +
                '<button type="button" class="mpcve-btn mpcve-btn--primary" data-act="add">+ Добавить строку</button>' +
                '<button type="button" class="mpcve-btn" data-act="reload" title="Перезагрузить страницу — увидеть точный рендер">Обновить</button>' +
                '<button type="button" class="mpcve-btn" data-act="close">Закрыть</button>' +
            '</div>' +
        '</div>';
    document.body.appendChild(overlay);
    var rowsBox = overlay.querySelector('.mpcve-rows');

    function close() { overlay.remove(); document.removeEventListener('keydown', onKey); }
    function onKey(e) { if (e.key === 'Escape') { close(); } }
    document.addEventListener('keydown', onKey);
    overlay.addEventListener('click', function (e) { if (e.target === overlay) { close(); } });
    overlay.querySelector('[data-act=close]').addEventListener('click', close);
    overlay.querySelector('[data-act=reload]').addEventListener('click', function () { window.location.reload(); });

    // Строки страницы (источник для панели и DOM-мутаций). Img-список капим по
    // числу строк конфига (DOM-слоты нарезки могут превышать).
    function pageRows() {
        var items = listRows(listEl, addr.parentField);
        if (items[0] && items[0].tagName && items[0].tagName.toLowerCase() === 'img') {
            var cnt = configRowCount(addr);
            if (cnt != null && cnt < items.length) { items = items.slice(0, cnt); }
        }
        return items;
    }
    function mediaSub(items) {
        return (items[0] && items[0].tagName && items[0].tagName.toLowerCase() === 'img') ? 'img' : '';
    }

    // Серверная row-операция (address несёт path для вложенных списков).
    function serverOp(extra, btn) {
        var a = {
            type: 'row', section: addr.section, parentField: addr.parentField,
            level: addr.level, resourceId: addr.resourceId
        };
        if (addr.path) { a.path = addr.path; }
        Object.keys(extra).forEach(function (k) { a[k] = extra[k]; });
        if (btn) { btn.disabled = true; }
        return api.post('row/op', { address: a }).then(function (r) {
            if (!r || !r.success) {
                toast((r && r.message) || 'Ошибка операции со строкой', true);
                if (btn) { btn.disabled = false; }
                return false;
            }
            return true;
        }).catch(function () {
            toast('Сетевая ошибка', true);
            if (btn) { btn.disabled = false; }
            return false;
        });
    }

    function renderRows() {
        var items = pageRows();
        var sub = mediaSub(items);
        rowsBox.innerHTML = items.length
            ? items.map(function (it, idx) {
                var upload = sub
                    ? '<button type="button" class="mpcve-rows__btn" data-op="img" title="Загрузить/заменить картинку">📷</button>'
                    : '';
                return '<div class="mpcve-rows__row" data-idx="' + idx + '">' +
                    '<span class="mpcve-rows__num">' + (idx + 1) + '</span>' +
                    '<span class="mpcve-rows__prev">' + esc(rowPreview(it)) + '</span>' +
                    '<span class="mpcve-rows__act">' + upload +
                        '<button type="button" class="mpcve-rows__btn" data-op="up" title="Вверх">↑</button>' +
                        '<button type="button" class="mpcve-rows__btn" data-op="down" title="Вниз">↓</button>' +
                        '<button type="button" class="mpcve-rows__btn mpcve-rows__btn--del" data-op="del" title="Удалить">✕</button>' +
                    '</span></div>';
            }).join('')
            : '<div class="mpcve-hpanel__empty">Строк пока нет — добавьте первую.</div>';
        wireRows(items, sub);
    }

    function wireRows(items, sub) {
        var n = items.length;
        rowsBox.querySelectorAll('.mpcve-rows__row').forEach(function (rowEl) {
            var idx = parseInt(rowEl.getAttribute('data-idx'), 10);
            rowEl.querySelectorAll('[data-op]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var op = btn.getAttribute('data-op');
                    if (op === 'img') {
                        close();
                        var imgAddr = {
                            type: 'field', section: addr.section, parentField: addr.parentField,
                            idx: idx, level: addr.level, resourceId: addr.resourceId, fieldName: sub
                        };
                        if (addr.path) { imgAddr.path = addr.path.concat([{ field: addr.parentField, idx: idx }]); imgAddr.fieldName = sub; }
                        openImageEditor(items[idx], imgAddr);
                    } else if (op === 'del') {
                        serverOp({ op: 'delete', idx: idx }, btn).then(function (ok) {
                            if (ok) { items[idx].remove(); renderRows(); }
                        });
                    } else if (op === 'up' && idx > 0) {
                        serverOp({ op: 'move', fromIdx: idx, toIdx: idx - 1 }, btn).then(function (ok) {
                            if (ok) {
                                var a = items[idx], b = items[idx - 1];
                                b.parentNode.insertBefore(a, b);
                                renderRows();
                            }
                        });
                    } else if (op === 'down' && idx < n - 1) {
                        serverOp({ op: 'move', fromIdx: idx, toIdx: idx + 1 }, btn).then(function (ok) {
                            if (ok) {
                                var a = items[idx], b = items[idx + 1];
                                b.parentNode.insertBefore(a, b.nextSibling);
                                renderRows();
                            }
                        });
                    }
                });
            });
        });
    }

    overlay.querySelector('[data-act=add]').addEventListener('click', function (e) {
        var btn = e.currentTarget;
        var items = listRows(listEl, addr.parentField);
        serverOp({ op: 'add' }, btn).then(function (ok) {
            btn.disabled = false;
            if (!ok) { return; }
            if (items.length) {
                // JS-рендер новой строки: клон первой строки с очисткой значений.
                var clone = cloneBlankRow(items[0]);
                var last = items[items.length - 1];
                last.parentNode.insertBefore(clone, last.nextSibling);
                markFieldsWithin(clone);
                renderRows();
                toast('Строка добавлена — заполните поля');
            } else {
                // Пустой список: шаблона в DOM нет (структура полей — на нарезке).
                toast('Строка добавлена в конфиг. Обновите страницу, чтобы заполнить.');
            }
        });
    });

    renderRows();
}
