/**
 * mpcVisualEditor — редактор изображений (загрузка файла, замена src/фона,
 * migx-запись img с атрибутами alt/title/width/height).
 */
import { api, folderOf } from '../api.js';
import { toast, bgUrl, hasBg } from '../dom.js';
import { fieldAddress } from '../address.js';
import { openFileManager } from '../filemanager.js';
import { makeUrlButton } from './urlrow.js';

function currentImageSrc(el) {
    if (el.tagName.toLowerCase() === 'img') {
        return el.currentSrc || el.src || '';
    }
    var bg = bgUrl(el);
    if (bg) {
        return bg;
    }
    var img = el.querySelector ? el.querySelector('img') : null;
    return img ? (img.currentSrc || img.src || '') : '';
}

function setImageSrc(el, url) {
    if (el.tagName.toLowerCase() === 'img') {
        el.removeAttribute('srcset');
        el.src = url;
        return;
    }
    // Фон: пишем обратно в inline style того же элемента.
    if (bgUrl(el)) {
        el.style.backgroundImage = 'url("' + url + '")';
        return;
    }
    var img = el.querySelector ? el.querySelector('img') : null;
    if (img) {
        img.removeAttribute('srcset');
        img.src = url;
    }
}

// Картинка-ЗАПИСЬ (migx-поле img) vs простой путь (фон/bg_img/image-TV). У записи
// значение в конфиге — [{MIGX_id,src,alt,title,width,height}], у пути — строка.
// Простой image-TV (data-mpc-tv) хранит ПУТЬ-строку: каттер выводит его как
// src="{$resource.tvs.<name>}", и migx-запись (JSON) сломала бы Fenom при запекании
// parsed/. migx-TV в редакторе пока не поддержаны (M29), так что любой
// data-mpc-tv <img> — это путь. Запись — только для config-полей (data-mpc-field).
function isRecordImage(el) {
    if (el.tagName.toLowerCase() !== 'img' || hasBg(el)) { return false; }
    if (el.hasAttribute('data-mpc-tv')) { return false; }
    var ftype = el.getAttribute('data-mpc-ftype') || '';
    return ftype !== 'bg_img';
}

export function openImageEditor(el, forcedAddr) {
    if (document.querySelector('.mpcve-modal')) {
        return;
    }
    var asRecord = isRecordImage(el);
    var cur      = currentImageSrc(el);
    var curAlt   = el.getAttribute('alt') || '';
    var curTitle = el.getAttribute('title') || '';
    var curW     = el.getAttribute('width') || '';
    var curH     = el.getAttribute('height') || '';

    var overlay = document.createElement('div');
    overlay.className = 'mpcve-modal';
    var attrFields = asRecord
        ? '<label class="mpcve-modal__field">alt<input type="text" data-f="alt"></label>' +
          '<label class="mpcve-modal__field">title<input type="text" data-f="title"></label>'
        : '';
    overlay.innerHTML =
        '<div class="mpcve-modal__card">' +
            '<div class="mpcve-modal__head">Изображение</div>' +
            '<div class="mpcve-modal__preview"></div>' +
            '<label class="mpcve-modal__drop">' +
                '<span>Перетащите файл сюда или <b>выберите</b></span>' +
                '<input type="file" accept="image/*" hidden>' +
            '</label>' +
            '<div class="mpcve-modal__picks">' +
                '<button type="button" class="mpcve-pick-existing" data-act="browse">📁 Выбрать существующий</button>' +
            '</div>' +
            attrFields +
            '<div class="mpcve-modal__actions">' +
                '<button type="button" class="mpcve-btn" data-act="cancel">Отмена</button>' +
                '<button type="button" class="mpcve-btn mpcve-btn--primary" data-act="save">Сохранить</button>' +
            '</div>' +
        '</div>';
    document.body.appendChild(overlay);

    var preview    = overlay.querySelector('.mpcve-modal__preview');
    var input      = overlay.querySelector('input[type=file]');
    var drop       = overlay.querySelector('.mpcve-modal__drop');
    var saveBtn    = overlay.querySelector('[data-act=save]');
    var altInput   = overlay.querySelector('[data-f=alt]');
    var titleInput = overlay.querySelector('[data-f=title]');
    if (altInput)   { altInput.value = curAlt; }
    if (titleInput) { titleInput.value = curTitle; }

    var chosen = null;
    var picked = '';            // url выбранного существующего файла
    var newW = '', newH = '';

    // В режиме пути (фон) без файла сохранять нечего; у записи можно править атрибуты.
    if (!asRecord) {
        saveBtn.disabled = true;
        saveBtn.textContent = 'Загрузить и сохранить';
    }

    renderPreview(cur, false);

    function renderPreview(src, isNew) {
        preview.innerHTML = src
            ? '<img alt="">' + (isNew ? '<span class="mpcve-modal__badge">новое</span>' : '')
            : '<span class="mpcve-modal__empty">нет изображения</span>';
        if (src) { preview.querySelector('img').src = src; }
    }

    function close() {
        overlay.remove();
        document.removeEventListener('keydown', onKey);
    }
    function onKey(e) { if (e.key === 'Escape') { close(); } }
    document.addEventListener('keydown', onKey);
    overlay.addEventListener('click', function (e) { if (e.target === overlay) { close(); } });
    overlay.querySelector('[data-act=cancel]').addEventListener('click', close);

    function pick(file) {
        if (!file || file.type.indexOf('image/') !== 0) {
            toast('Это не изображение', true);
            return;
        }
        chosen = file;
        var reader = new FileReader();
        reader.onload = function (ev) {
            renderPreview(ev.target.result, true);
            var probe = new Image();
            probe.onload = function () {
                newW = String(probe.naturalWidth || '');
                newH = String(probe.naturalHeight || '');
            };
            probe.src = ev.target.result;
        };
        reader.readAsDataURL(file);
        saveBtn.disabled = false;
    }

    input.addEventListener('change', function () { pick(input.files[0]); });

    // Выбрать существующий файл через файловый менеджер (без загрузки). Менеджер
    // открываем в папке текущей картинки (если она есть).
    overlay.querySelector('[data-act=browse]').addEventListener('click', function () {
        folderOf(cur).then(function (startPath) {
            return openFileManager({ accept: 'image', title: 'Выбрать изображение', startPath: startPath });
        }).then(function (file) {
            if (!file) { return; }
            chosen = null;
            picked = file.url;
            renderPreview(file.url, true);
            var probe = new Image();
            probe.onload = function () { newW = String(probe.naturalWidth || ''); newH = String(probe.naturalHeight || ''); };
            probe.src = file.url;
            saveBtn.disabled = false;
        });
    });

    // «Вставил ссылку → скачалось»: качаем по URL и работаем как с выбранным
    // существующим файлом (picked). Папка-цель — папка текущей картинки.
    overlay.querySelector('.mpcve-modal__picks').appendChild(makeUrlButton({
        accept: 'image',
        getCurrentUrl: function () { return cur; },
        onResolved: function (localUrl) {
            chosen = null;
            picked = localUrl;
            renderPreview(localUrl, true);
            var probe = new Image();
            probe.onload = function () { newW = String(probe.naturalWidth || ''); newH = String(probe.naturalHeight || ''); };
            probe.src = localUrl;
            saveBtn.disabled = false;
        }
    }));

    ['dragover', 'dragenter'].forEach(function (ev) {
        drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('mpcve-modal__drop--over'); });
    });
    ['dragleave', 'drop'].forEach(function (ev) {
        drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.remove('mpcve-modal__drop--over'); });
    });
    drop.addEventListener('drop', function (e) {
        if (e.dataTransfer && e.dataTransfer.files[0]) { pick(e.dataTransfer.files[0]); }
    });

    function busy(on, label) {
        saveBtn.disabled = on;
        if (label) { saveBtn.textContent = label; }
    }

    // Записываем поле: для записи — полная migx-запись (src+атрибуты),
    // иначе — путь-строка. ConfigFieldWriter хранит value как есть.
    function persist(src, addr) {
        var value, old;
        if (asRecord) {
            value = JSON.stringify([{
                MIGX_id: 1,
                src: src,
                alt: altInput ? altInput.value : curAlt,
                title: titleInput ? titleInput.value : curTitle,
                width: ((chosen || picked) && newW) ? newW : curW,
                height: ((chosen || picked) && newH) ? newH : curH
            }]);
            // old в той же форме (значения, захваченные при открытии) — для диффа/отката.
            old = JSON.stringify([{
                MIGX_id: 1, src: cur, alt: curAlt, title: curTitle, width: curW, height: curH
            }]);
        } else {
            value = src;
            old = cur;
        }
        api.post('field/save', { address: addr, value: value, old: old }).then(function (r2) {
            if (r2 && r2.success) {
                setImageSrc(el, src);
                if (asRecord) {
                    if (altInput) { el.setAttribute('alt', altInput.value); }
                    if (titleInput && titleInput.value) { el.setAttribute('title', titleInput.value); }
                }
                toast('Сохранено');
                close();
            } else {
                toast((r2 && r2.message) || 'Ошибка сохранения', true);
                busy(false, 'Сохранить');
            }
        }).catch(function () { toast('Сетевая ошибка', true); busy(false, 'Сохранить'); });
    }

    saveBtn.addEventListener('click', function () {
        var addr = forcedAddr || fieldAddress(el);
        if (!addr) {
            toast('У элемента нет data-mpc-адреса — некуда сохранять', true);
            return;
        }
        if (!asRecord && !chosen && !picked) {
            return;
        }
        busy(true, 'Сохранение…');
        if (chosen) {
            // Грузим в папку текущей картинки (folderOf); нет текущей → canonicalDir.
            folderOf(cur).then(function (folder) {
                var extra = { accept: 'image' };
                if (folder != null) { extra.path = folder; }
                return api.upload('files/upload', chosen, extra);
            }).then(function (res) {
                if (!res || !res.success || !res.data || !res.data.url) {
                    toast((res && res.message) || 'Ошибка загрузки', true);
                    busy(false, 'Сохранить');
                    return;
                }
                persist(res.data.url, addr);
            }).catch(function () { toast('Сетевая ошибка', true); busy(false, 'Сохранить'); });
        } else {
            // без нового файла — сохраняем выбранный существующий ИЛИ текущий src
            persist(picked || cur, addr);
        }
    });
}
