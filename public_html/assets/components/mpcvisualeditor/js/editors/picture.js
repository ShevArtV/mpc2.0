/**
 * mpcVisualEditor — редактор PICTURE: главная картинка (fallback) + источники
 * <source> (адаптив). Конфиг — источник правды (лексикон-ключи), DOM — превью.
 */
import { api, uploadAndProbe } from '../api.js';
import { toast, parseRecord } from '../dom.js';
import { fieldAddress, fieldConfigRecord } from '../address.js';

// override = {addr} → value-based режим (панель скрытых полей: el=null, адрес и
// запись из конфига; превью пустые — DOM нет). Иначе обычный режим (адрес/превью
// из el на странице).
export function openPictureEditor(el, override) {
    if (document.querySelector('.mpcve-modal')) { return; }
    var addr = (override && override.addr) || fieldAddress(el);
    if (!addr || !addr.section || !addr.fieldName) { toast('Нет адреса картинки', true); return; }
    // Конфиг — источник правды (ключи лексикона); DOM — для превью (готовые url).
    var rec = fieldConfigRecord(addr) || {};
    var imgRec = (parseRecord(rec.img) || [{}])[0] || {};
    var cfgSources = Array.isArray(rec.sources) ? rec.sources : [];
    var domMain = el ? el.querySelector('img') : null;
    var domSources = el ? Array.prototype.slice.call(el.querySelectorAll('source')) : [];

    var main = {
        src: imgRec.src || '',                 // ключ из конфига (если не меняли)
        alt: domMain ? (domMain.getAttribute('alt') || '') : '',
        title: imgRec.title || '',
        width: imgRec.width || '', height: imgRec.height || '',
        preview: domMain ? (domMain.currentSrc || domMain.src || '') : '',
        file: null
    };
    var sources = cfgSources.map(function (cs, k) {
        var dom = domSources[k];
        return {
            srcset: cs.srcset || '',           // ключ из конфига
            media: cs.media || (dom ? dom.getAttribute('media') : '') || '',
            type: cs.type || null, sizes: cs.sizes || null, width: cs.width || null, height: cs.height || null,
            preview: dom ? (dom.getAttribute('srcset') || dom.getAttribute('data-lazy') || '') : '',
            file: null
        };
    });

    var overlay = document.createElement('div');
    overlay.className = 'mpcve-modal';
    overlay.innerHTML =
        '<div class="mpcve-modal__card mpcve-modal__card--wide">' +
            '<div class="mpcve-modal__head">Адаптивная картинка</div>' +
            '<div class="mpcve-modal__field">Основная картинка (fallback)</div>' +
            '<div class="mpcve-pic__main"></div>' +
            '<label class="mpcve-modal__field">alt<input type="text" data-f="alt"></label>' +
            '<div class="mpcve-modal__field">Источники (адаптив)</div>' +
            '<div class="mpcve-pic__sources"></div>' +
            '<button type="button" class="mpcve-btn" data-act="addsrc">+ Добавить источник</button>' +
            '<div class="mpcve-modal__actions">' +
                '<button type="button" class="mpcve-btn" data-act="cancel">Отмена</button>' +
                '<button type="button" class="mpcve-btn mpcve-btn--primary" data-act="save">Сохранить</button>' +
            '</div>' +
        '</div>';
    document.body.appendChild(overlay);
    overlay.querySelector('[data-f=alt]').value = main.alt;

    function close() { overlay.remove(); document.removeEventListener('keydown', onKey); }
    function onKey(e) { if (e.key === 'Escape') { close(); } }
    document.addEventListener('keydown', onKey);
    overlay.addEventListener('click', function (e) { if (e.target === overlay) { close(); } });
    overlay.querySelector('[data-act=cancel]').addEventListener('click', close);

    // мини-загрузчик: превью + file-input. onPick(file) кладёт файл в модель.
    function mediaSlot(container, getPreview, onPick) {
        container.innerHTML = '<div class="mpcve-pic__thumb"></div>' +
            '<label class="mpcve-pic__pick">Заменить<input type="file" accept="image/*" hidden></label>';
        var thumb = container.querySelector('.mpcve-pic__thumb');
        function draw() {
            var p = getPreview();
            thumb.innerHTML = p ? '<img alt="">' : '<span class="mpcve-modal__empty">нет</span>';
            if (p) { thumb.querySelector('img').src = p; }
        }
        draw();
        container.querySelector('input[type=file]').addEventListener('change', function () {
            var f = this.files[0];
            if (f && f.type.indexOf('image/') === 0) { onPick(f, draw); }
        });
    }

    // основная картинка
    mediaSlot(overlay.querySelector('.mpcve-pic__main'),
        function () { return main.preview; },
        function (f, draw) {
            main.file = f;
            var r = new FileReader();
            r.onload = function (ev) { main.preview = ev.target.result; draw(); };
            r.readAsDataURL(f);
        });

    function renderSources() {
        var box = overlay.querySelector('.mpcve-pic__sources');
        box.innerHTML = '';
        sources.forEach(function (s, k) {
            var row = document.createElement('div');
            row.className = 'mpcve-pic__src';
            row.innerHTML = '<div class="mpcve-pic__slot"></div>' +
                '<input type="text" class="mpcve-pic__media" placeholder="media (напр. (min-width: 992px))">' +
                '<button type="button" class="mpcve-rows__btn mpcve-rows__btn--del" title="Удалить источник">✕</button>';
            box.appendChild(row);
            row.querySelector('.mpcve-pic__media').value = s.media;
            row.querySelector('.mpcve-pic__media').addEventListener('input', function () { s.media = this.value; });
            row.querySelector('[title]').addEventListener('click', function () { sources.splice(k, 1); renderSources(); });
            mediaSlot(row.querySelector('.mpcve-pic__slot'),
                function () { return s.preview; },
                function (f, draw) {
                    s.file = f;
                    var r = new FileReader();
                    r.onload = function (ev) { s.preview = ev.target.result; draw(); };
                    r.readAsDataURL(f);
                });
        });
    }
    renderSources();

    overlay.querySelector('[data-act=addsrc]').addEventListener('click', function () {
        sources.push({ srcset: '', media: '', type: null, sizes: null, width: null, height: null, preview: '', file: null });
        renderSources();
    });

    overlay.querySelector('[data-act=save]').addEventListener('click', function (e) {
        var saveBtn = e.currentTarget;
        saveBtn.disabled = true; saveBtn.textContent = 'Загрузка…';
        // грузим все выбранные файлы (основной + источники)
        var jobs = [];
        if (main.file) { jobs.push(uploadAndProbe(main.file).then(function (r) { main.src = r.url; main.width = r.width; main.height = r.height; })); }
        sources.forEach(function (s) {
            if (s.file) { jobs.push(uploadAndProbe(s.file).then(function (r) { s.srcset = r.url; })); }
        });
        Promise.all(jobs).then(function () {
            // собираем запись: неизменённые src/srcset остаются КЛЮЧАМИ → бэк их не трогает
            var img = [{ MIGX_id: 1, src: main.src, alt: overlay.querySelector('[data-f=alt]').value, title: main.title, width: main.width, height: main.height }];
            var recOut = [{
                MIGX_id: 1, preview: main.preview, img: JSON.stringify(img),
                sources: sources.filter(function (s) { return s.srcset; }).map(function (s, k) {
                    return { MIGX_id: k + 1, type: s.type, media: s.media, srcset: s.srcset, sizes: s.sizes, width: s.width, height: s.height };
                })
            }];
            return api.post('field/save', { address: addr, value: JSON.stringify(recOut) });
        }).then(function (r) {
            if (r && r.success) { toast('Сохранено, обновляю…'); window.location.reload(); }
            else { toast((r && r.message) || 'Ошибка сохранения', true); saveBtn.disabled = false; saveBtn.textContent = 'Сохранить'; }
        }).catch(function (err) {
            toast((err && err.message) || 'Ошибка', true); saveBtn.disabled = false; saveBtn.textContent = 'Сохранить';
        });
    });
}
