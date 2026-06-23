/**
 * mpcVisualEditor — компактная кнопка «загрузить по ссылке» для медиа-редакторов.
 * Стилизована как «Выбрать существующий» (текст-ссылка), чтобы не расширять
 * горизонтальные ряды слотов. По клику открывает диалог для вставки URL: файл
 * скачивается на сервер (тем же механизмом, что грабер вёрстки) и отдаётся
 * локальным url через onResolved.
 *
 * Одна кнопка на каждый слот: основной файл, poster и каждый вложенный <source>
 * в picture/video/audio — у каждого свои getCurrentUrl/onResolved.
 */
import { downloadMedia } from '../api.js';
import { toast, promptDialog } from '../dom.js';

/**
 * @param {Object} opts
 * @param {string} opts.accept      image|video|audio|media|any
 * @param {Function} [opts.getCurrentUrl] () => string — текущее значение (папка-цель)
 * @param {Function} opts.onResolved (localUrl) => void — что сделать со скачанным файлом
 * @returns {HTMLButtonElement}
 */
export function makeUrlButton(opts) {
    opts = opts || {};
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'mpcve-pick-existing';
    var label = '🔗 По ссылке';
    btn.textContent = label;

    btn.addEventListener('click', function () {
        promptDialog('Ссылка на файл (http/https):', {
            title: 'Загрузить по ссылке', okLabel: 'Скачать', placeholder: 'https://…'
        }).then(function (url) {
            url = (url || '').trim();
            if (!url) { return; }
            if (!/^https?:\/\//i.test(url)) { toast('Укажите корректную http(s)-ссылку', true); return; }
            btn.disabled = true;
            btn.textContent = 'Скачиваем…';
            downloadMedia(url, opts.accept || 'any', opts.getCurrentUrl ? opts.getCurrentUrl() : '')
                .then(function (localUrl) {
                    toast('Файл скачан');
                    if (opts.onResolved) { opts.onResolved(localUrl); }
                })
                .catch(function (e) {
                    toast((e && e.message) || 'Не удалось скачать по ссылке', true);
                })
                .then(function () { btn.disabled = false; btn.textContent = label; });
        });
    });
    return btn;
}
