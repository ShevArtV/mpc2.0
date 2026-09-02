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
import { toast, esc, isMedia, confirmDialog, openModal } from '../dom.js';
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
    var addr = listAddress(listEl);
    if (!addr.section || !addr.parentField) {
        toast('Не удалось определить адрес списка', true);
        return;
    }
    // data-mpc-max на контейнере списка → лимит числа строк. Фронт не даёт
    // добавлять сверх лимита (migx тоже ограничивает через extended.maxRecords).
    // 0 / нет атрибута = без лимита.
    var maxRows = parseInt(listEl.getAttribute('data-mpc-max'), 10) || 0;

    var m = openModal({
        cardClass: 'mpcve-modal__card--wide',
        title: 'Строки списка · ' + addr.parentField,
        bodyHtml:
            '<div class="mpcve-rows__hint">Перетащите строку за ⋮⋮ (или в любом месте), чтобы изменить порядок</div>' +
            '<div class="mpcve-rows"></div>',
        actionsHtml:
            '<button type="button" class="mpcve-btn mpcve-btn--primary" data-act="add">+ Добавить строку</button>' +
            '<button type="button" class="mpcve-btn" data-act="reload" title="Перезагрузить страницу — увидеть точный рендер">Обновить</button>' +
            '<button type="button" class="mpcve-btn" data-act="close">Закрыть</button>',
        closeSelectors: ['[data-act=close]'],
        onClose: function () { dragFrom = null; }
    });
    if (!m) { return; }
    var overlay = m.overlay;
    var close = m.close;
    var rowsBox = overlay.querySelector('.mpcve-rows');
    var dragFrom = null; // индекс перетаскиваемой строки (drag-drop переупорядочивание)

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
                return '<div class="mpcve-rows__row" data-idx="' + idx + '" draggable="true">' +
                    '<span class="mpcve-rows__grip" title="Перетащите, чтобы переставить">⋮⋮</span>' +
                    '<span class="mpcve-rows__num">' + (idx + 1) + '</span>' +
                    '<span class="mpcve-rows__prev">' + esc(rowPreview(it)) + '</span>' +
                    '<span class="mpcve-rows__act">' + upload +
                        '<button type="button" class="mpcve-rows__btn mpcve-rows__btn--del" data-op="del" title="Удалить">✕</button>' +
                    '</span></div>';
            }).join('')
            : '<div class="mpcve-hpanel__empty">Строк пока нет — добавьте первую.</div>';
        wireRows(items, sub);
        updateAddState(items.length);
    }

    // Блокируем «+ Добавить» при достижении data-mpc-max (с подсказкой-причиной).
    function updateAddState(count) {
        if (!maxRows) { return; }
        var addBtn = overlay.querySelector('[data-act=add]');
        if (!addBtn) { return; }
        var atMax = count >= maxRows;
        addBtn.disabled = atMax;
        addBtn.title = atMax ? ('Достигнут максимум строк: ' + maxRows) : '';
    }

    function wireRows(items, sub) {
        rowsBox.querySelectorAll('.mpcve-rows__row').forEach(function (rowEl) {
            var idx = parseInt(rowEl.getAttribute('data-idx'), 10);
            wireDrag(rowEl, idx, items);
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
                        confirmDialog('Удалить строку #' + (idx + 1) + '? Откат удаления не предусмотрен.', {
                            title: 'Удаление строки', okLabel: 'Удалить', cancelLabel: 'Отмена', danger: true
                        }).then(function (yes) {
                            if (!yes) { return; }
                            serverOp({ op: 'delete', idx: idx }, btn).then(function (ok) {
                                if (!ok) { return; }
                                if (items.length === 1) {
                                    // Бэк оставляет последнюю строку пустым шаблоном
                                    // (структура под-полей — образец для add). Зеркалим
                                    // в DOM: очищаем строку, а не удаляем, иначе образец
                                    // пропадёт и список нельзя будет наполнить снова.
                                    var blank = cloneBlankRow(items[0]);
                                    items[0].parentNode.replaceChild(blank, items[0]);
                                    markFieldsWithin(blank);
                                } else {
                                    items[idx].remove();
                                }
                                renderRows();
                            });
                        });
                    }
                });
            });
        });
    }

    // Drag-drop переупорядочивание строки: серверный move(from→to) (array_splice,
    // значения/лексикон-ключи едут со строкой) + перестановка узла страницы.
    function wireDrag(rowEl, idx, items) {
        rowEl.addEventListener('dragstart', function (e) {
            dragFrom = idx;
            rowEl.classList.add('mpcve-rows__row--drag');
            if (e.dataTransfer) {
                e.dataTransfer.effectAllowed = 'move';
                try { e.dataTransfer.setData('text/plain', String(idx)); } catch (_) {}
            }
        });
        rowEl.addEventListener('dragend', function () {
            dragFrom = null;
            rowsBox.querySelectorAll('.mpcve-rows__row--drag, .mpcve-rows__row--over').forEach(function (r) {
                r.classList.remove('mpcve-rows__row--drag', 'mpcve-rows__row--over');
            });
        });
        rowEl.addEventListener('dragover', function (e) {
            if (dragFrom === null || dragFrom === idx) { return; }
            e.preventDefault();
            if (e.dataTransfer) { e.dataTransfer.dropEffect = 'move'; }
            rowEl.classList.add('mpcve-rows__row--over');
        });
        rowEl.addEventListener('dragleave', function () {
            rowEl.classList.remove('mpcve-rows__row--over');
        });
        rowEl.addEventListener('drop', function (e) {
            e.preventDefault();
            rowEl.classList.remove('mpcve-rows__row--over');
            var from = dragFrom, to = idx;
            dragFrom = null;
            if (from === null || from === to) { return; }
            serverOp({ op: 'move', fromIdx: from, toIdx: to }).then(function (ok) {
                if (!ok) { return; }
                var src = items[from], ref = items[to];
                // splice-move: to>from → после ref; to<from → перед ref.
                if (to > from) { ref.parentNode.insertBefore(src, ref.nextSibling); }
                else { ref.parentNode.insertBefore(src, ref); }
                renderRows();
            });
        });
    }

    overlay.querySelector('[data-act=add]').addEventListener('click', function (e) {
        var btn = e.currentTarget;
        var items = listRows(listEl, addr.parentField);
        if (maxRows && items.length >= maxRows) {
            toast('Достигнут максимум строк: ' + maxRows, true);
            return;
        }
        serverOp({ op: 'add' }, btn).then(function (ok) {
            // НЕ снимаем disabled до обновления UI (иначе двойной клик до ререндера).
            if (!ok) { btn.disabled = false; return; }
            if (items.length) {
                // JS-рендер новой строки: клон первой строки с очисткой значений.
                var clone = cloneBlankRow(items[0]);
                var last = items[items.length - 1];
                last.parentNode.insertBefore(clone, last.nextSibling);
                markFieldsWithin(clone);
                renderRows(); // сам выставит addBtn.disabled = atMax (корректное состояние)
                toast('Строка добавлена — заполните поля');
            } else {
                // Пустой список: шаблона в DOM нет (структура полей — на нарезке).
                btn.disabled = false;
                toast('Строка добавлена в конфиг. Обновите страницу, чтобы заполнить.');
            }
        });
    });

    renderRows();
}
