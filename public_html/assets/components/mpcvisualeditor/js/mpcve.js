/**
 * mpcVisualEditor — фронт-контроллер (M8).
 * Тулбар доступен авторизованному ВСЕГДА; редактирование вкл/выкл тумблером
 * (состояние в cookie mpcve_editing), без перезагрузки. Редактор диспатчится по
 * ТИПУ поля (карта fields/types из mpc_base). Сохранение — ПЕР-ПОЛЕВОЕ: при
 * коммите шлём адрес поля + значение в connector field/save → FieldWriter mpc
 * (static→sbp/global, иначе→ресурс). НЕ ре-грабим весь DOM.
 */
(function (window, document) {
    'use strict';

    var cfg = window.mpcVEConfig || {};
    if (!cfg.connectorUrl) {
        return;
    }

    var FIELD_ATTRS = ['data-mpc-field', 'data-mpc-rfield', 'data-mpc-tv',
        'data-mpc-field-1', 'data-mpc-field-2', 'data-mpc-field-3'];
    var SELECTOR = FIELD_ATTRS.map(function (a) { return '[' + a + ']'; }).join(',');

    var TYPE_HINT = {
        text: 'Текст — клик, чтобы редактировать',
        richtext: 'Текст с форматированием — клик',
        image: 'Изображение — клик, чтобы заменить',
        media: 'Медиа — клик (редактор в разработке)',
        rows: 'Список — клик (редактор в разработке)'
    };

    var typesMap = {};

    function getCookie(name) {
        var m = document.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]*)'));
        return m ? decodeURIComponent(m[1]) : null;
    }
    function setCookie(name, val) {
        document.cookie = name + '=' + val + '; path=/; max-age=31536000';
    }

    // По умолчанию редактирование включено; выключается тумблером (cookie '0').
    var editing = getCookie('mpcve_editing') !== '0';

    var api = {
        post: function (action, payload) {
            var body = new FormData();
            body.append('action', action);
            Object.keys(payload || {}).forEach(function (k) {
                var v = payload[k];
                body.append(k, typeof v === 'object' ? JSON.stringify(v) : v);
            });
            return fetch(cfg.connectorUrl, {
                method: 'POST',
                credentials: 'same-origin',
                body: body
            }).then(function (r) { return r.json(); });
        },
        // Загрузка файла (multipart): file под ключом 'file' + доп. поля.
        upload: function (action, file, extra) {
            var body = new FormData();
            body.append('action', action);
            body.append('file', file);
            Object.keys(extra || {}).forEach(function (k) {
                var v = extra[k];
                body.append(k, typeof v === 'object' ? JSON.stringify(v) : v);
            });
            return fetch(cfg.connectorUrl, {
                method: 'POST',
                credentials: 'same-origin',
                body: body
            }).then(function (r) { return r.json(); });
        }
    };

    // --- адрес поля из DOM («one address space») ---------------------------
    function resolveAddress(el) {
        var type = null, fieldName = null;
        if (el.hasAttribute('data-mpc-rfield')) {
            type = 'rfield';
            fieldName = el.getAttribute('data-mpc-rfield');
        } else if (el.hasAttribute('data-mpc-tv')) {
            type = 'tv';
            fieldName = el.getAttribute('data-mpc-tv');
        } else {
            for (var i = 0; i < el.attributes.length; i++) {
                var a = el.attributes[i];
                if (a.name === 'data-mpc-field' || /^data-mpc-field-\d+$/.test(a.name)) {
                    type = 'field';
                    fieldName = a.value;
                    break;
                }
            }
        }
        if (!type) {
            return null;
        }
        return { type: type, fieldName: fieldName };
    }

    function fieldAddress(el) {
        var addr = resolveAddress(el);
        if (!addr) {
            return null;
        }
        var sectionEl = el.closest('[data-mpc-section]');
        addr.section = sectionEl ? sectionEl.getAttribute('data-mpc-section') : '';
        addr.level = (sectionEl && sectionEl.hasAttribute('data-mpc-static')) ? 'global' : 'resource';
        addr.resourceId = cfg.resourceId || 0;

        // Кросс-ресурс: обёртка data-mpc-res="<id>" (поле другого ресурса,
        // выведенного сниппетом) → пишем в ТОТ ресурс, а не в текущую страницу.
        var resEl = el.closest('[data-mpc-res]');
        if (resEl) {
            var rid = parseInt(resEl.getAttribute('data-mpc-res'), 10);
            if (rid > 0) {
                addr.resourceId = rid;
            }
        }

        // Вложенное поле строки списка: data-mpc-field-N → parentField + idx,
        // чтобы запись попала в нужную строку (ConfigFieldWriter: rows[idx][field]).
        var nestAttr = null;
        for (var i = 0; i < el.attributes.length; i++) {
            if (/^data-mpc-field-\d+$/.test(el.attributes[i].name)) {
                nestAttr = el.attributes[i].name;
                break;
            }
        }
        if (nestAttr) {
            var lvl = parseInt(nestAttr.replace('data-mpc-field-', ''), 10);
            var parentAttr = lvl > 1 ? 'data-mpc-field-' + (lvl - 1) : 'data-mpc-field';
            var itemAttr = lvl > 1 ? 'data-mpc-item-' + lvl : 'data-mpc-item';
            var parentEl = el.closest('[' + parentAttr + ']');
            var itemEl = el.closest('[' + itemAttr + ']');
            if (parentEl && itemEl) {
                addr.parentField = parentEl.getAttribute(parentAttr);
                var idx = 0, sib = itemEl.previousElementSibling;
                while (sib) {
                    if (sib.hasAttribute(itemAttr)) { idx++; }
                    sib = sib.previousElementSibling;
                }
                addr.idx = idx;
            }
        }
        return addr;
    }

    function isMedia(el) {
        var t = el.tagName.toLowerCase();
        return t === 'img' || t === 'picture' || t === 'video' || t === 'audio';
    }

    // URL фоновой картинки из INLINE style (background-image / background-шорткат
    // CSSOM раскладывает на longhand). Пусто, если фона нет.
    function bgUrl(el) {
        var raw = (el.style && el.style.backgroundImage) || '';
        var m = raw.match(/url\((['"]?)(.*?)\1\)/i);
        return m ? m[2] : '';
    }
    function hasBg(el) {
        return !!bgUrl(el);
    }

    // Тип редактора по значению data-mpc-ftype (имя типа-прототипа mpc_base).
    function ftypeToEditor(ftype) {
        if (!ftype) { return ''; }
        if (ftype === 'richtext') { return 'richtext'; }
        if (ftype === 'img' || ftype === 'picture' || ftype === 'bg_img') { return 'image'; }
        if (ftype === 'video' || ftype === 'audio') { return 'media'; }
        if (ftype.indexOf('list') === 0) { return 'rows'; }
        return 'text'; // text/textarea/number/checkbox — инлайн-текст
    }

    function isListEl(el) {
        return !!(el.querySelector && el.querySelector('[data-mpc-item]'));
    }

    function editorTypeFor(el, addr) {
        // Тип, заявленный автором через data-mpc-ftype (в edit-mode маркеры
        // сохраняются), — самый точный сигнал, важнее карты mpc_base.
        var byFtype = ftypeToEditor(el.getAttribute('data-mpc-ftype'));
        if (byFtype) {
            return byFtype;
        }
        // Структурный список без ftype (динамический) → редактор строк.
        if (isListEl(el)) {
            return 'rows';
        }
        var mapped = (addr.fieldName && typesMap[addr.fieldName]) || '';
        // Явный не-картиночный тип из mpc_base (richtext/media/rows) — приоритет.
        if (mapped && mapped !== 'text' && mapped !== 'image') {
            return mapped;
        }
        if (isMedia(el)) {
            var t = el.tagName.toLowerCase();
            return (t === 'img' || t === 'picture') ? 'image' : 'media';
        }
        // Картинка по типу поля ИЛИ фон через inline style (data-mpc-field + style).
        if (mapped === 'image' || hasBg(el)) {
            return 'image';
        }
        return 'text';
    }

    // --- пер-полевое сохранение --------------------------------------------
    function saveField(el) {
        var addr = fieldAddress(el);
        if (!addr) {
            return;
        }
        var value = el.getAttribute('data-mpcve-type') === 'richtext' ? el.innerHTML : el.innerText;
        api.post('field/save', { address: addr, value: value }).then(function (res) {
            if (res && res.success) {
                toast('Сохранено');
            } else {
                toast((res && res.message) || 'Ошибка сохранения', true);
            }
        }).catch(function () {
            toast('Сетевая ошибка', true);
        });
    }

    // --- реестр редакторов по типу -----------------------------------------
    var editors = {
        text: { open: openTextEditor },
        richtext: { open: openTextEditor },
        image: { open: openImageEditor }
    };

    function openTextEditor(el) {
        if (el.getAttribute('contenteditable') === 'true') {
            return;
        }
        var orig = el.innerHTML;
        el.setAttribute('contenteditable', 'true');
        el.setAttribute('spellcheck', 'false');
        el.classList.add('mpcve-editing');
        el.focus();
        var sel = window.getSelection();
        if (sel && el.childNodes.length) {
            var range = document.createRange();
            range.selectNodeContents(el);
            sel.removeAllRanges();
            sel.addRange(range);
        }

        function cleanup() {
            el.removeAttribute('contenteditable');
            el.removeAttribute('spellcheck');
            el.classList.remove('mpcve-editing');
            el.removeEventListener('keydown', onKey);
            el.removeEventListener('blur', onBlur);
        }
        function onKey(e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                el.blur();
            } else if (e.key === 'Escape') {
                e.preventDefault();
                el.innerHTML = orig;
                cleanup();
            }
        }
        function onBlur() {
            cleanup();
            if (el.innerHTML !== orig) {
                saveField(el);
            }
        }
        el.addEventListener('keydown', onKey);
        el.addEventListener('blur', onBlur);
    }

    // --- редактор изображений (загрузка файла) -----------------------------
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

    // Картинка-ЗАПИСЬ (migx-поле img) vs простой путь (фон/bg_img). У записи
    // значение в конфиге — [{MIGX_id,src,alt,title,width,height}], у пути — строка.
    function isRecordImage(el) {
        var ftype = el.getAttribute('data-mpc-ftype') || '';
        return el.tagName.toLowerCase() === 'img' && ftype !== 'bg_img' && !hasBg(el);
    }

    function openImageEditor(el) {
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
            var value;
            if (asRecord) {
                value = JSON.stringify([{
                    MIGX_id: 1,
                    src: src,
                    alt: altInput ? altInput.value : curAlt,
                    title: titleInput ? titleInput.value : curTitle,
                    width: (chosen && newW) ? newW : curW,
                    height: (chosen && newH) ? newH : curH
                }]);
            } else {
                value = src;
            }
            api.post('field/save', { address: addr, value: value }).then(function (r2) {
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
            var addr = fieldAddress(el);
            if (!addr) {
                toast('У элемента нет data-mpc-адреса — некуда сохранять', true);
                return;
            }
            if (!asRecord && !chosen) {
                return;
            }
            busy(true, 'Сохранение…');
            if (chosen) {
                api.upload('image/upload', chosen).then(function (res) {
                    if (!res || !res.success || !res.data || !res.data.url) {
                        toast((res && res.message) || 'Ошибка загрузки', true);
                        busy(false, 'Сохранить');
                        return;
                    }
                    persist(res.data.url, addr);
                }).catch(function () { toast('Сетевая ошибка', true); busy(false, 'Сохранить'); });
            } else {
                // запись без нового файла — сохраняем текущий src + изменённые атрибуты
                persist(cur, addr);
            }
        });
    }

    // --- разметка / снятие разметки полей ----------------------------------
    function markEditable() {
        document.querySelectorAll(SELECTOR).forEach(function (el) {
            var addr = resolveAddress(el);
            if (!addr) {
                return;
            }
            var type = editorTypeFor(el, addr);
            el.classList.add('mpcve-editable');
            el.setAttribute('data-mpcve-type', type);
            el.setAttribute('title', TYPE_HINT[type] || 'клик — редактировать');
            if (isMedia(el)) {
                el.classList.add('mpcve-editable--media');
            }
        });
    }

    function unmarkEditable() {
        document.querySelectorAll('.mpcve-editable').forEach(function (el) {
            el.classList.remove('mpcve-editable', 'mpcve-editable--media', 'mpcve-editing');
            el.removeAttribute('data-mpcve-type');
            el.removeAttribute('contenteditable');
            el.removeAttribute('title');
        });
    }

    function applyEditingState() {
        if (editing) {
            markEditable();
            document.body.classList.add('mpcve-on');
        } else {
            unmarkEditable();
            document.body.classList.remove('mpcve-on');
        }
    }

    // --- UI ----------------------------------------------------------------
    function toast(message, isError) {
        var node = document.createElement('div');
        node.className = 'mpcve-toast' + (isError ? ' mpcve-toast--error' : '');
        node.textContent = message;
        document.body.appendChild(node);
        requestAnimationFrame(function () { node.classList.add('mpcve-toast--show'); });
        setTimeout(function () {
            node.classList.remove('mpcve-toast--show');
            setTimeout(function () { node.remove(); }, 250);
        }, 2400);
    }

    function buildToolbar() {
        var bar = document.createElement('div');
        bar.className = 'mpcve-toolbar';
        bar.innerHTML =
            '<span class="mpcve-toolbar__title">mpcVisualEditor</span>' +
            '<span class="mpcve-toolbar__hint">клик по полю — править; Enter или уход — сохранить</span>' +
            '<button type="button" data-mpcve="toggle"></button>';
        document.body.appendChild(bar);
        document.body.classList.add('mpcve-active');

        var btn = bar.querySelector('[data-mpcve="toggle"]');
        function syncBtn() {
            btn.textContent = editing ? 'Завершить редактирование' : 'Редактировать';
            btn.classList.toggle('mpcve-btn--on', editing);
        }
        syncBtn();
        btn.addEventListener('click', function () {
            // Переключение режима меняет отображение → перезагружаем страницу.
            // Тулбар не пропадёт: плагин инжектит его авторизованному всегда.
            setCookie('mpcve_editing', editing ? '0' : '1');
            window.location.reload();
        });
    }

    function bindClicks() {
        document.addEventListener('click', function (e) {
            if (!editing) {
                return;
            }
            var el = e.target.closest ? e.target.closest('.mpcve-editable') : null;
            if (!el || el.getAttribute('contenteditable') === 'true') {
                return;
            }
            e.preventDefault();
            e.stopPropagation();
            var type = el.getAttribute('data-mpcve-type') || 'text';
            var ed = editors[type];
            if (ed && ed.open) {
                ed.open(el);
            } else {
                toast('Редактор «' + type + '» ещё в разработке', true);
            }
        }, true);
    }

    function init() {
        buildToolbar();
        bindClicks();
        api.post('fields/types', {}).then(function (res) {
            if (res && res.success && res.data && res.data.fields) {
                typesMap = res.data.fields;
            }
            applyEditingState();
        }).catch(function () {
            applyEditingState();
        });
    }

    window.MpcVE = { cfg: cfg, api: api, resolveAddress: resolveAddress, toast: toast };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})(window, document);
