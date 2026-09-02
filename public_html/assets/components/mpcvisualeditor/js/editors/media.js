/**
 * mpcVisualEditor — редактор VIDEO/AUDIO (одиночные): основной файл (src),
 * poster (video), булевы атрибуты (controls/autoplay/loop/muted), preload и
 * массив источников <source> ({type, media, src}). Конфиг — источник правды
 * (лексикон-ключи src/poster/sources[].src), DOM — превью/имена файлов.
 *
 * Бэк mergeMediaRecord лексиконит ТОЛЬКО src/poster/sources[].src: неизменённые
 * шлём КЛЮЧАМИ (из конфига) — бэк их не трогает; изменённые → загруженный URL.
 * Прочие ключи (controls/title/width/…) хранятся литералом — переносим запись
 * целиком (Object.assign), чтобы ничего не потерять.
 *
 * Булевы атрибуты (controls/autoplay/loop/muted) шлём как 1/0 — каттер рендерит
 * их УСЛОВНО (`{if $rec.controls}controls{/if}`, см. PlaceholderProcessor
 * renderBoolAttrs), поэтому 0 реально выключает атрибут. Работает только если
 * атрибут присутствовал в разметке на нарезке (иначе {if} не сгенерён).
 */
import { api, uploadMedia, folderOf } from '../api.js';
import { toast, esc, closeOnBackdrop } from '../dom.js';
import { fieldAddress, fieldConfigRecord } from '../address.js';
import { openFileManager } from '../filemanager.js';
import { makeUrlButton } from './urlrow.js';

function boolOf(v) { return v === true || v === 1 || v === '1' || v === 'true'; }
function fileName(path) { return (String(path || '').split('?')[0].split('/').pop()) || ''; }

// override = {addr, isVideo} → value-based режим (панель скрытых полей: el=null,
// тип video/audio передаётся явно, превью пустые). Иначе из el на странице.
export function openMediaEditor(el, override) {
    if (document.querySelector('.mpcve-modal')) { return; }
    var addr = (override && override.addr) || fieldAddress(el);
    if (!addr || !addr.section || !addr.fieldName) { toast('Нет адреса медиа', true); return; }

    var isVideo = override ? !!override.isVideo : (el.tagName.toLowerCase() === 'video');
    var rec = fieldConfigRecord(addr) || {};
    // Прежнее значение поля (как лежит в конфиге) — для диффа/отката в истории.
    var oldValue = JSON.stringify([rec]);
    var domSources = el ? Array.prototype.slice.call(el.querySelectorAll('source')) : [];

    // основной файл: ключ из конфига + превью-путь из DOM (а без DOM — из конфига:
    // value-based из панели, когда поля нет на странице).
    var main = {
        src: rec.src || '',
        preview: el ? (el.getAttribute('src') || el.currentSrc || '') : (rec.src || ''),
        file: null
    };
    var poster = {
        src: rec.poster || '',
        preview: el ? (el.getAttribute('poster') || '') : (rec.poster || ''),
        file: null
    };
    var sources = (Array.isArray(rec.sources) ? rec.sources : []).map(function (cs, k) {
        var dom = domSources[k];
        return {
            src: cs.src || '',
            type: cs.type || (dom ? dom.getAttribute('type') : '') || '',
            media: cs.media || (dom ? dom.getAttribute('media') : '') || '',
            preview: dom ? (dom.getAttribute('src') || dom.getAttribute('data-lazy') || '') : (cs.src || ''),
            file: null
        };
    });
    var attrInit = function (k) { return (k in rec) ? boolOf(rec[k]) : (el ? el.hasAttribute(k) : false); };
    var preloadInit = rec.preload || (el ? el.getAttribute('preload') : '') || 'metadata';

    var overlay = document.createElement('div');
    overlay.className = 'mpcve-modal';
    var accept = isVideo ? 'video/*' : 'audio/*';
    overlay.innerHTML =
        '<div class="mpcve-modal__card mpcve-modal__card--wide">' +
            '<div class="mpcve-modal__head">' + (isVideo ? 'Видео' : 'Аудио') + '</div>' +
            '<div class="mpcve-modal__field">Основной файл</div>' +
            '<div class="mpcve-media__main"></div>' +
            (isVideo ? '<div class="mpcve-modal__field">Постер (превью видео)</div><div class="mpcve-media__poster"></div>' : '') +
            '<div class="mpcve-media__attrs">' +
                '<label><input type="checkbox" data-a="controls"> controls</label>' +
                '<label><input type="checkbox" data-a="autoplay"> autoplay</label>' +
                '<label><input type="checkbox" data-a="loop"> loop</label>' +
                '<label><input type="checkbox" data-a="muted"> muted</label>' +
                '<label>preload <select data-a="preload">' +
                    '<option value="metadata">metadata</option>' +
                    '<option value="auto">auto</option>' +
                    '<option value="none">none</option>' +
                '</select></label>' +
            '</div>' +
            '<div class="mpcve-modal__field">Источники (форматы)</div>' +
            '<div class="mpcve-media__sources"></div>' +
            '<button type="button" class="mpcve-btn" data-act="addsrc">+ Добавить источник</button>' +
            '<div class="mpcve-modal__actions">' +
                '<button type="button" class="mpcve-btn" data-act="cancel">Отмена</button>' +
                '<button type="button" class="mpcve-btn mpcve-btn--primary" data-act="save">Сохранить</button>' +
            '</div>' +
        '</div>';
    document.body.appendChild(overlay);

    ['controls', 'autoplay', 'loop', 'muted'].forEach(function (k) {
        overlay.querySelector('[data-a=' + k + ']').checked = attrInit(k);
    });
    overlay.querySelector('[data-a=preload]').value = preloadInit;

    function close() { overlay.remove(); document.removeEventListener('keydown', onKey); }
    function onKey(e) { if (e.key === 'Escape') { close(); } }
    document.addEventListener('keydown', onKey);
    closeOnBackdrop(overlay, function () { close(); });
    overlay.querySelector('[data-act=cancel]').addEventListener('click', close);

    var fmAccept = isVideo ? 'video' : 'audio';

    // Слот медиа-файла: показывает ИМЯ файла (видео/аудио не превьюим) + замена +
    // выбор существующего через файловый менеджер (без загрузки).
    function fileSlot(container, model, acceptType) {
        function label() {
            return model.file ? model.file.name : (fileName(model.preview) || fileName(model.src) || 'нет файла');
        }
        container.classList.add('mpcve-media__slot');
        container.innerHTML = '<span class="mpcve-media__name"></span>' +
            '<label class="mpcve-pic__pick">Заменить<input type="file" accept="' + acceptType + '" hidden></label>' +
            '<button type="button" class="mpcve-pick-existing">📁 Выбрать существующий</button>';
        var name = container.querySelector('.mpcve-media__name');
        name.textContent = label();
        container.querySelector('input[type=file]').addEventListener('change', function () {
            var f = this.files[0];
            if (f) { model.file = f; name.textContent = label(); }
        });
        container.querySelector('.mpcve-pick-existing').addEventListener('click', function () {
            folderOf(model.src).then(function (startPath) {
                return openFileManager({ accept: fmAccept, title: 'Выбрать файл', startPath: startPath });
            }).then(function (file) {
                if (file) { model.file = null; model.src = file.url; model.preview = file.url; name.textContent = label(); }
            });
        });
        // По ссылке: скачиваем и работаем как с выбранным существующим файлом.
        container.appendChild(makeUrlButton({
            accept: fmAccept,
            getCurrentUrl: function () { return model.src; },
            onResolved: function (localUrl) { model.file = null; model.src = localUrl; model.preview = localUrl; name.textContent = label(); }
        }));
    }

    // Постер — картинка: превью-thumb + замена + выбор существующего.
    function posterSlot(container) {
        container.innerHTML = '<div class="mpcve-pic__thumb"></div>' +
            '<label class="mpcve-pic__pick">Заменить<input type="file" accept="image/*" hidden></label>' +
            '<button type="button" class="mpcve-pick-existing">📁 Выбрать существующий</button>';
        var thumb = container.querySelector('.mpcve-pic__thumb');
        function draw() {
            thumb.innerHTML = poster.preview ? '<img alt="">' : '<span class="mpcve-modal__empty">нет</span>';
            if (poster.preview) { thumb.querySelector('img').src = poster.preview; }
        }
        draw();
        container.querySelector('input[type=file]').addEventListener('change', function () {
            var f = this.files[0];
            if (f && f.type.indexOf('image/') === 0) {
                poster.file = f;
                var r = new FileReader();
                r.onload = function (ev) { poster.preview = ev.target.result; draw(); };
                r.readAsDataURL(f);
            }
        });
        container.querySelector('.mpcve-pick-existing').addEventListener('click', function () {
            folderOf(poster.src).then(function (startPath) {
                return openFileManager({ accept: 'image', title: 'Выбрать постер', startPath: startPath });
            }).then(function (file) {
                if (file) { poster.file = null; poster.src = file.url; poster.preview = file.url; draw(); }
            });
        });
        container.appendChild(makeUrlButton({
            accept: 'image',
            getCurrentUrl: function () { return poster.src; },
            onResolved: function (localUrl) { poster.file = null; poster.src = localUrl; poster.preview = localUrl; draw(); }
        }));
    }

    fileSlot(overlay.querySelector('.mpcve-media__main'), main, accept);
    if (isVideo) { posterSlot(overlay.querySelector('.mpcve-media__poster')); }

    function renderSources() {
        var box = overlay.querySelector('.mpcve-media__sources');
        box.innerHTML = '';
        sources.forEach(function (s, k) {
            var row = document.createElement('div');
            row.className = 'mpcve-pic__src';
            row.innerHTML = '<div class="mpcve-media__srcfile"></div>' +
                '<input type="text" class="mpcve-pic__media" data-r="type" placeholder="type (напр. ' + (isVideo ? 'video/mp4' : 'audio/mpeg') + ')" value="' + esc(s.type) + '">' +
                '<input type="text" class="mpcve-pic__media" data-r="media" placeholder="media (необяз.)" value="' + esc(s.media) + '">' +
                '<button type="button" class="mpcve-rows__btn mpcve-rows__btn--del" title="Удалить источник">✕</button>';
            box.appendChild(row);
            row.querySelector('[data-r=type]').addEventListener('input', function () { s.type = this.value; });
            row.querySelector('[data-r=media]').addEventListener('input', function () { s.media = this.value; });
            row.querySelector('[title]').addEventListener('click', function () { sources.splice(k, 1); renderSources(); });
            fileSlot(row.querySelector('.mpcve-media__srcfile'), s, accept);
        });
    }
    renderSources();

    overlay.querySelector('[data-act=addsrc]').addEventListener('click', function () {
        sources.push({ src: '', type: '', media: '', preview: '', file: null });
        renderSources();
    });

    overlay.querySelector('[data-act=save]').addEventListener('click', function (e) {
        var saveBtn = e.currentTarget;
        saveBtn.disabled = true; saveBtn.textContent = 'Загрузка…';
        var jobs = [];
        // currentUrl (model.src) → грузим рядом с текущим файлом этого слота; нет
        // текущего → каноническая папка типа. По-слотно: постер-картинка не уедет
        // в папку видео (гибрид-защита бэка иначе отклонила бы).
        if (main.file) { jobs.push(uploadMedia(main.file, isVideo ? 'video' : 'audio', main.src).then(function (url) { main.src = url; })); }
        if (isVideo && poster.file) { jobs.push(uploadMedia(poster.file, 'image', poster.src).then(function (url) { poster.src = url; })); }
        sources.forEach(function (s) {
            if (s.file) { jobs.push(uploadMedia(s.file, isVideo ? 'video' : 'audio', s.src).then(function (url) { s.src = url; })); }
        });
        Promise.all(jobs).then(function () {
            // Переносим запись целиком (литеральные ключи сохраняются), переопределяя
            // src/poster/атрибуты/sources. Неизменённые src/poster — КЛЮЧИ из конфига.
            var out = {};
            Object.keys(rec).forEach(function (k) { out[k] = rec[k]; });
            out.MIGX_id = rec.MIGX_id || 1;
            out.src = main.src;
            if (isVideo) { out.poster = poster.src; }
            // Булевы → 1/0: каттер рендерит условно ({if $rec.controls}…), 0 выключает.
            ['controls', 'autoplay', 'loop', 'muted'].forEach(function (k) {
                out[k] = overlay.querySelector('[data-a=' + k + ']').checked ? 1 : 0;
            });
            out.preload = overlay.querySelector('[data-a=preload]').value;
            out.sources = sources.filter(function (s) { return s.src; }).map(function (s, k) {
                return { MIGX_id: k + 1, type: s.type, media: s.media, src: s.src };
            });
            return api.post('field/save', { address: addr, value: JSON.stringify([out]), old: oldValue });
        }).then(function (r) {
            if (r && r.success) { toast('Сохранено, обновляю…'); window.location.reload(); }
            else { toast((r && r.message) || 'Ошибка сохранения', true); saveBtn.disabled = false; saveBtn.textContent = 'Сохранить'; }
        }).catch(function (err) {
            toast((err && err.message) || 'Ошибка', true); saveBtn.disabled = false; saveBtn.textContent = 'Сохранить';
        });
    });
}
