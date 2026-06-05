/**
 * mpcVisualEditor — редактор file-TV: выбор/загрузка файла через файловый
 * менеджер (M28). Сохраняем URL файла от корня сайта (как image-редактор).
 */
import { api } from '../api.js';
import { toast } from '../dom.js';
import { fieldAddress } from '../address.js';
import { openFileManager } from '../filemanager.js';

export function openFileEditor(el) {
    var addr = fieldAddress(el);
    if (!addr) { toast('Нет адреса поля', true); return; }
    var cur = (el.textContent || '').trim();

    openFileManager({ accept: 'any', title: 'Выбрать файл' }).then(function (file) {
        if (!file) { return; }
        // НЕ raw: путь файла — контентное значение; лексиконизировано → writer
        // пишет в лексикон под существующим ключом, иначе литералом.
        api.post('field/save', { address: addr, value: file.url, old: cur }).then(function (r) {
            if (r && r.success) {
                if (el.tagName && el.tagName.toLowerCase() === 'a') {
                    el.setAttribute('href', file.url);
                    if (!el.textContent.trim()) { el.textContent = file.name; }
                } else {
                    el.textContent = file.name;
                }
                toast('Сохранено');
            } else {
                toast((r && r.message) || 'Ошибка сохранения', true);
            }
        }).catch(function () { toast('Сетевая ошибка', true); });
    });
}
