/**
 * mpcVisualEditor — разметка одного поля как редактируемого. Вынесено отдельно,
 * чтобы и app.js (разметка всей страницы), и editors/rows.js (разметка полей
 * только что вставленной JS-строки) использовали одну логику без циклов импорта.
 */
import { SELECTOR, TYPE_HINT } from './constants.js';
import { isMedia, fieldLabel } from './dom.js';
import { resolveAddress, editorTypeFor } from './address.js';

// Пометить один элемент-маркер редактируемым (класс + тип + подсказка).
export function markEl(el) {
    var addr = resolveAddress(el);
    if (!addr) { return; }
    var type = editorTypeFor(el, addr);
    el.classList.add('mpcve-editable');
    el.setAttribute('data-mpcve-type', type);
    el.setAttribute('title', TYPE_HINT[type] || 'клик — редактировать');
    // Плейсхолдер пустого поля = его подпись (caption из конфигуратора, иначе имя).
    el.setAttribute('data-mpcve-ph', fieldLabel(addr.fieldName));
    if (isMedia(el)) {
        el.classList.add('mpcve-editable--media');
    }
    // <audio> без controls не имеет видимой области (0×0) → по нему нельзя
    // кликнуть, чтобы открыть редактор. В режиме правки временно включаем
    // нативные controls (видимый кликабельный бар); снимаем при выходе
    // (unmarkEditable). Видео и так видно (кадр/постер), его не трогаем.
    if (el.tagName.toLowerCase() === 'audio' && !el.hasAttribute('controls')) {
        el.setAttribute('controls', '');
        el.setAttribute('data-mpcve-controls', '1');
    }
}

// Пометить все поля внутри root (вкл. сам root, если он поле). Для JS-строк.
export function markFieldsWithin(root) {
    if (root.matches && root.matches(SELECTOR)) { markEl(root); }
    if (root.querySelectorAll) {
        root.querySelectorAll(SELECTOR).forEach(markEl);
    }
}

// Пометить служебную информацию (data-mpc-info) редактируемой. Это ГЛОБАЛЬНАЯ
// настройка сайта, а не поле ресурса → свой тип 'info', без resolveAddress.
export function markInfo(el) {
    if (el.classList.contains('mpcve-editable')) { return; }
    el.classList.add('mpcve-editable', 'mpcve-editable--info');
    el.setAttribute('data-mpcve-type', 'info');
    el.setAttribute('title', 'Настройка сайта — клик (меняется на всех страницах)');
}
