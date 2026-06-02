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
        rows: 'Список — клик, чтобы добавить/удалить/переставить строки'
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

        // Вложенное поле строки списка: data-mpc-field-N. Собираем ПОЛНЫЙ путь
        // [{field,idx}, …] от секции к строке (вложенность любой глубины), т.к.
        // поле уровня 2 лежит на 2 уровня глубже: cfg[sec][L1][i][L2][j][field].
        var nestAttr = null;
        for (var i = 0; i < el.attributes.length; i++) {
            if (/^data-mpc-field-\d+$/.test(el.attributes[i].name)) {
                nestAttr = el.attributes[i].name;
                break;
            }
        }
        if (nestAttr) {
            var lvl = parseInt(nestAttr.replace('data-mpc-field-', ''), 10);
            var path = buildRowPath(el, lvl);
            if (path && path.length) {
                addr.path = path;
                // back-compat: ближайший (самый глубокий) уровень → parentField/idx
                var deepest = path[path.length - 1];
                addr.parentField = deepest.field;
                addr.idx = deepest.idx;
            }
        }
        return addr;
    }

    // Путь [{field,idx}, …] от секции к строке для поля уровня lvl (data-mpc-field-lvl).
    // Уровень N: ряд = data-mpc-item-(N-1) (data-mpc-item для N=1), контейнер
    // списка = data-mpc-field-(N-1) (data-mpc-field для N=1).
    function buildRowPath(el, lvl) {
        var path = [];
        var base = el;
        for (var L = lvl; L >= 1; L--) {
            var itemAttr = L > 1 ? 'data-mpc-item-' + (L - 1) : 'data-mpc-item';
            var listAttr = L > 1 ? 'data-mpc-field-' + (L - 1) : 'data-mpc-field';
            var itemEl = base.closest('[' + itemAttr + ']');
            if (!itemEl) { return null; }
            var listEl = itemEl.closest('[' + listAttr + ']');
            if (!listEl || listEl === itemEl) { return null; }
            var idx = 0, sib = itemEl.previousElementSibling;
            while (sib) {
                if (sib.hasAttribute(itemAttr)) { idx++; }
                sib = sib.previousElementSibling;
            }
            path.unshift({ field: listEl.getAttribute(listAttr), idx: idx });
            base = listEl;
        }
        return path;
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
        image: { open: openImageEditor },
        rows: { open: openRowsEditor }
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

    // --- редактор СТРОК списка (add / delete / move) -----------------------
    // Клик по контейнеру списка (data-mpc-field с data-mpc-item внутри) → панель
    // строк. Операции пишутся в mpc_config (row/op → ConfigFieldWriter), значения
    // строк (вкл. лексикон-ключи) едут со строкой. После операции — перезагрузка,
    // чтобы список перерисовался из конфига. v1: списки уровня 1.
    function listAddress(listEl) {
        var sectionEl = listEl.closest('[data-mpc-section]');
        var rid = cfg.resourceId || 0;
        var resEl = listEl.closest('[data-mpc-res]');
        if (resEl) {
            var r = parseInt(resEl.getAttribute('data-mpc-res'), 10);
            if (r > 0) { rid = r; }
        }
        return {
            section: sectionEl ? sectionEl.getAttribute('data-mpc-section') : '',
            parentField: listEl.getAttribute('data-mpc-field') || '',
            level: (sectionEl && sectionEl.hasAttribute('data-mpc-static')) ? 'global' : 'resource',
            resourceId: rid
        };
    }

    function rowPreview(itemEl) {
        var img = (itemEl.tagName && itemEl.tagName.toLowerCase() === 'img')
            ? itemEl
            : (itemEl.querySelector ? itemEl.querySelector('img') : null);
        if (img) {
            return img.getAttribute('alt') || (img.getAttribute('src') || '').split('/').pop() || '(медиа)';
        }
        var t = (itemEl.textContent || '').replace(/\s+/g, ' ').trim();
        return t.length > 50 ? (t.slice(0, 50) + '…') : (t || '(пусто)');
    }

    // Ряды списка: структурный (data-mpc-item внутри контейнера) ИЛИ медиа-список
    // (повторяющиеся одноимённые соседи img/picture/video/audio — у них нет item).
    function listRows(el, field) {
        var items = Array.prototype.slice.call(el.querySelectorAll('[data-mpc-item]'));
        if (!items.length && isMedia(el) && el.parentElement) {
            items = Array.prototype.slice.call(el.parentElement.children).filter(function (c) {
                return c.getAttribute && c.getAttribute('data-mpc-field') === field;
            });
        }
        return items;
    }

    function openRowsEditor(listEl) {
        if (document.querySelector('.mpcve-modal')) { return; }
        // v1: только списки уровня 1 (контейнер с data-mpc-field). Вложенные
        // (data-mpc-field-1/2) — отдельный адрес parentField+idx, пока не тут.
        if (listEl.getAttribute('data-mpc-field') === null) {
            toast('Вложенные списки пока редактируются по полям, не строками', true);
            return;
        }
        var addr = listAddress(listEl);
        if (!addr.section || !addr.parentField) {
            toast('Не удалось определить адрес списка', true);
            return;
        }

        var items = listRows(listEl, addr.parentField);

        var rowsHtml = items.length
            ? items.map(function (it, idx) {
                return '<div class="mpcve-rows__row" data-idx="' + idx + '">' +
                    '<span class="mpcve-rows__num">' + (idx + 1) + '</span>' +
                    '<span class="mpcve-rows__prev">' + esc(rowPreview(it)) + '</span>' +
                    '<span class="mpcve-rows__act">' +
                        '<button type="button" class="mpcve-rows__btn" data-op="up" title="Вверх">↑</button>' +
                        '<button type="button" class="mpcve-rows__btn" data-op="down" title="Вниз">↓</button>' +
                        '<button type="button" class="mpcve-rows__btn mpcve-rows__btn--del" data-op="del" title="Удалить">✕</button>' +
                    '</span></div>';
            }).join('')
            : '<div class="mpcve-hpanel__empty">Строк пока нет — добавьте первую.</div>';

        var overlay = document.createElement('div');
        overlay.className = 'mpcve-modal';
        overlay.innerHTML =
            '<div class="mpcve-modal__card mpcve-modal__card--wide">' +
                '<div class="mpcve-modal__head">Строки списка · ' + esc(addr.parentField) + '</div>' +
                '<div class="mpcve-rows">' + rowsHtml + '</div>' +
                '<div class="mpcve-modal__actions">' +
                    '<button type="button" class="mpcve-btn mpcve-btn--primary" data-act="add">+ Добавить строку</button>' +
                    '<button type="button" class="mpcve-btn" data-act="close">Закрыть</button>' +
                '</div>' +
            '</div>';
        document.body.appendChild(overlay);

        function close() { overlay.remove(); document.removeEventListener('keydown', onKey); }
        function onKey(e) { if (e.key === 'Escape') { close(); } }
        document.addEventListener('keydown', onKey);
        overlay.addEventListener('click', function (e) { if (e.target === overlay) { close(); } });
        overlay.querySelector('[data-act=close]').addEventListener('click', close);

        function sendOp(extra, btn) {
            var a = { type: 'row', section: addr.section, parentField: addr.parentField, level: addr.level, resourceId: addr.resourceId };
            Object.keys(extra).forEach(function (k) { a[k] = extra[k]; });
            if (btn) { btn.disabled = true; }
            api.post('row/op', { address: a }).then(function (r) {
                if (r && r.success) {
                    toast('Готово, обновляю…');
                    window.location.reload();
                } else {
                    toast((r && r.message) || 'Ошибка операции со строкой', true);
                    if (btn) { btn.disabled = false; }
                }
            }).catch(function () {
                toast('Сетевая ошибка', true);
                if (btn) { btn.disabled = false; }
            });
        }

        overlay.querySelector('[data-act=add]').addEventListener('click', function (e) {
            sendOp({ op: 'add' }, e.currentTarget);
        });

        var n = items.length;
        overlay.querySelectorAll('.mpcve-rows__row').forEach(function (rowEl) {
            var idx = parseInt(rowEl.getAttribute('data-idx'), 10);
            rowEl.querySelectorAll('[data-op]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var op = btn.getAttribute('data-op');
                    if (op === 'del') {
                        sendOp({ op: 'delete', idx: idx }, btn);
                    } else if (op === 'up' && idx > 0) {
                        sendOp({ op: 'move', fromIdx: idx, toIdx: idx - 1 }, btn);
                    } else if (op === 'down' && idx < n - 1) {
                        sendOp({ op: 'move', fromIdx: idx, toIdx: idx + 1 }, btn);
                    }
                });
            });
        });
    }

    // --- скрытые поля У БЛОКА (config-driven) ------------------------------
    // Поля, вырезанные из страницы (data-mpc-remove) или вспомогательные, не
    // имеют DOM-маркера, но лежат в mpc_config. Грузим конфиг (config/get) и
    // вешаем на КАЖДЫЙ блок (секцию / элемент списка), у которого такие поля
    // есть, кнопку «⊕ N» в углу — клик открывает панель ЭТОГО блока. Запись —
    // прежним field/save (адрес с parentField/idx для под-полей строк списков).
    var configData = null; // { resourceId, resource:{}, global:{} } из config/get
    // Редактируемые поля СЕКЦИИ = вкладка «Стили секции», кроме css_file_path.
    var SECTION_STYLE_FIELDS = ['inline_styles', 'class_names'];
    // Структурные ключи (для скрытых под-полей СТРОК списков) — не редактируем.
    var STRUCTURAL = ['section_name', 'MIGX_formname', 'MIGX_id', 'id', 'position',
        'is_static', 'file_name', 'limit', 'lexicon_prefix', 'css_file_path',
        'inline_styles', 'class_names'];
    // Понятные подписи известных полей (приоритетнее captions из конфигуратора).
    var FIELD_LABELS = {
        inline_styles: 'Inline-стили',
        class_names: 'CSS-классы',
        resources: 'Ресурсы (resources)'
    };
    var labelsMap = {}; // имя поля → caption из конфигуратора (fields/types)

    // Подпись поля: ручная → caption конфигуратора → ключ (как fallback).
    function fieldLabel(name) {
        return FIELD_LABELS[name] || labelsMap[name] || name;
    }

    function loadConfig() {
        return api.post('config/get', { resourceId: cfg.resourceId || 0 }).then(function (r) {
            configData = (r && r.success && r.data) ? r.data : null;
            if (!configData) { console.warn('[mpcVE] config/get вернул без данных:', r); }
        }).catch(function (e) {
            configData = null;
            console.warn('[mpcVE] config/get ошибка запроса:', e);
        });
    }

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    // Найти секцию по имени в конфиге указанного уровня (resource|global).
    function findSectionInLevel(name, level) {
        if (!configData || !name) { return null; }
        var cfgObj = configData[level] || {};
        var keys = Object.keys(cfgObj);
        for (var i = 0; i < keys.length; i++) {
            var s = cfgObj[keys[i]];
            if (s && (s.section_name === name || s.MIGX_formname === name)) {
                return { level: level, section: name, obj: s };
            }
        }
        return null;
    }

    // Конфиг-объект секции для КОНТЕНТА/строк: static→global, иначе resource.
    function sectionConfig(sectionEl) {
        var name = sectionEl.getAttribute('data-mpc-section') || '';
        var level = sectionEl.hasAttribute('data-mpc-static') ? 'global' : 'resource';
        return findSectionInLevel(name, level);
    }

    function parseRecord(v) {
        if (typeof v !== 'string') { return null; }
        var d;
        try { d = JSON.parse(v); } catch (e) { return null; }
        return (Array.isArray(d) && d.length && d.every(function (r) {
            return r && typeof r === 'object';
        })) ? d : null;
    }

    function isScalar(v) {
        return v == null || typeof v === 'string' || typeof v === 'number' || typeof v === 'boolean';
    }

    // Редактируемые поля СЕКЦИИ = вкладка «Стили секции» (кроме css_file_path).
    // Фиксированный набор, заполняем из конфига (или пусто — чтобы можно было
    // ЗАДАТЬ ещё не существующее значение). Диф конфиг−DOM для секций не годится:
    // контент идёт через rfield/tv, а в конфиге — только служебные ключи
    // (MIGX_id/id/lexicon_prefix…), которые не редактируются.
    //
    // ВАЖНО (каскад): стилевые поля — каскад-поля mpc, на рендере РЕСУРС-уровень
    // перекрывает global (см. Render::applyResourceCascade). Поэтому правим их на
    // уровне РЕСУРСА (текущая страница), если секция есть в ресурс-конфиге; иначе
    // global. Иначе для static-секции правка в global маскируется ресурсным
    // переопределением — и «не обновляется» на странице.
    function sectionStyleHidden(sectionEl) {
        var name = sectionEl.getAttribute('data-mpc-section') || '';
        var sc = findSectionInLevel(name, 'resource') || findSectionInLevel(name, 'global');
        if (!sc) { return []; }
        return SECTION_STYLE_FIELDS.map(function (fname) {
            var value = sc.obj[fname];
            return {
                level: sc.level, section: sc.section,
                fieldName: fname, value: value == null ? '' : String(value),
                type: typesMap[fname] || 'text', label: fieldLabel(fname)
            };
        });
    }

    // Инфо строки списка по её DOM-элементу (level-1 item): section/level/obj/parentField/idx.
    function itemInfo(itemEl) {
        var sc = sectionConfig(itemEl.closest('[data-mpc-section]'));
        if (!sc) { return null; }
        var listEl = itemEl.closest('[data-mpc-field]'); // контейнер списка (предок)
        if (!listEl) { return null; }
        var idx = 0, sib = itemEl.previousElementSibling;
        while (sib) {
            if (sib.hasAttribute('data-mpc-item')) { idx++; }
            sib = sib.previousElementSibling;
        }
        return {
            section: sc.section, level: sc.level, obj: sc.obj,
            parentField: listEl.getAttribute('data-mpc-field'), idx: idx
        };
    }

    // Имена под-полей строки, имеющих DOM-маркер внутри ЭТОГО item (level-1).
    function visibleItemSubs(itemEl) {
        var seen = {};
        itemEl.querySelectorAll('[data-mpc-field-1]').forEach(function (el) {
            if (el.closest('[data-mpc-item]') === itemEl) {
                seen[el.getAttribute('data-mpc-field-1')] = true;
            }
        });
        return seen;
    }

    // Скрытые скалярные под-поля строки списка (level-1) + её инфо.
    function itemHidden(itemEl) {
        var info = itemInfo(itemEl);
        if (!info) { return null; }
        var rows = parseRecord(info.obj[info.parentField]);
        var row = rows && rows[info.idx];
        if (!row) { return null; }
        var vis = visibleItemSubs(itemEl);
        var out = [];
        Object.keys(row).forEach(function (sub) {
            if (sub === 'MIGX_id' || STRUCTURAL.indexOf(sub) !== -1 || vis[sub]) { return; }
            var sv = row[sub];
            if (!isScalar(sv) || parseRecord(sv)) { return; }
            out.push({
                level: info.level, section: info.section,
                parentField: info.parentField, idx: info.idx,
                fieldName: sub, value: sv == null ? '' : String(sv),
                type: typesMap[sub] || 'text', label: fieldLabel(sub)
            });
        });
        return { info: info, fields: out };
    }

    // --- триггеры у блоков + панель ----------------------------------------
    function attachTrigger(blockEl, title, descriptors, btnText) {
        // Якорим кнопку абсолютно в углу блока; если блок position:static —
        // временно делаем relative (откатываем в removeHiddenTriggers).
        if (window.getComputedStyle(blockEl).position === 'static') {
            blockEl.style.position = 'relative';
            blockEl.setAttribute('data-mpcve-posfix', '1');
        }
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'mpcve-hidden-trigger';
        btn.textContent = btnText || ('⊕ ' + descriptors.length);
        btn.title = title;
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            openBlockPanel(title, descriptors);
        });
        blockEl.appendChild(btn);
    }

    function buildHiddenTriggers() {
        removeHiddenTriggers();
        if (!configData) {
            console.warn('[mpcVE] скрытые поля: конфиг не загружен (config/get). Кнопок не будет.');
            return;
        }
        var sections = document.querySelectorAll('[data-mpc-section]');
        var items = document.querySelectorAll('[data-mpc-item]');
        var triggers = 0;
        sections.forEach(function (sectionEl) {
            var fields = sectionStyleHidden(sectionEl);
            if (fields.length) {
                attachTrigger(sectionEl, 'Стили секции «' + sectionEl.getAttribute('data-mpc-section') + '»', fields, 'Стили');
                triggers++;
            }
        });
        items.forEach(function (itemEl) {
            var h = itemHidden(itemEl);
            if (h && h.fields.length) {
                attachTrigger(itemEl, 'Скрытые поля строки ' + h.info.parentField + ' #' + (h.info.idx + 1), h.fields);
                triggers++;
            }
        });
        console.info('[mpcVE] скрытые поля: секций в DOM=' + sections.length +
            ', строк списков=' + items.length + ', кнопок навешено=' + triggers +
            (sections.length === 0 ? ' — нет data-mpc-section: проверь mpc_edit_mode (маркеры в рендере)' : ''));
    }

    function removeHiddenTriggers() {
        document.querySelectorAll('.mpcve-hidden-trigger').forEach(function (b) { b.remove(); });
        document.querySelectorAll('[data-mpcve-posfix]').forEach(function (el) {
            el.style.position = '';
            el.removeAttribute('data-mpcve-posfix');
        });
    }

    function rowHtml(f, idx) {
        var multiline = f.type === 'richtext' || f.type === 'textarea' ||
            f.value.length > 80 || f.value.indexOf('\n') !== -1;
        var control = multiline
            ? '<textarea>' + esc(f.value) + '</textarea>'
            : '<input type="text" value="' + esc(f.value) + '">';
        return '<div class="mpcve-hpanel__row" data-i="' + idx + '">' +
            '<div class="mpcve-hpanel__label">' + esc(f.label) + '</div>' +
            '<div class="mpcve-hpanel__ctrl">' + control +
            '<button type="button" class="mpcve-btn mpcve-btn--primary" data-act="save">Сохранить</button>' +
            '</div></div>';
    }

    function openBlockPanel(title, descriptors) {
        if (document.querySelector('.mpcve-modal')) { return; }
        var body = descriptors.map(function (f, i) { return rowHtml(f, i); }).join('');

        var overlay = document.createElement('div');
        overlay.className = 'mpcve-modal';
        overlay.innerHTML =
            '<div class="mpcve-modal__card mpcve-modal__card--wide">' +
                '<div class="mpcve-modal__head">Скрытые поля · ' + esc(title) + '</div>' +
                '<div class="mpcve-hpanel">' + body + '</div>' +
                '<div class="mpcve-modal__actions">' +
                    '<button type="button" class="mpcve-btn" data-act="close">Закрыть</button>' +
                '</div>' +
            '</div>';
        document.body.appendChild(overlay);

        function close() { overlay.remove(); document.removeEventListener('keydown', onKey); }
        function onKey(e) { if (e.key === 'Escape') { close(); } }
        document.addEventListener('keydown', onKey);
        overlay.addEventListener('click', function (e) { if (e.target === overlay) { close(); } });
        overlay.querySelector('[data-act=close]').addEventListener('click', close);

        overlay.querySelectorAll('.mpcve-hpanel__row').forEach(function (rowEl) {
            var f = descriptors[parseInt(rowEl.getAttribute('data-i'), 10)];
            var ctrl = rowEl.querySelector('input, textarea');
            var btn = rowEl.querySelector('[data-act=save]');
            btn.addEventListener('click', function () {
                var addr = {
                    type: 'field', level: f.level, section: f.section,
                    fieldName: f.fieldName, resourceId: cfg.resourceId || 0
                };
                if (f.parentField != null) { addr.parentField = f.parentField; addr.idx = f.idx; }
                btn.disabled = true; btn.textContent = '…';
                api.post('field/save', { address: addr, value: ctrl.value }).then(function (r) {
                    if (r && r.success) {
                        f.value = ctrl.value;
                        rowEl.classList.add('mpcve-hpanel__row--saved');
                        btn.textContent = '✓';
                        setTimeout(function () { btn.textContent = 'Сохранить'; btn.disabled = false; }, 1200);
                    } else {
                        toast((r && r.message) || 'Ошибка сохранения', true);
                        btn.textContent = 'Сохранить'; btn.disabled = false;
                    }
                }).catch(function () {
                    toast('Сетевая ошибка', true);
                    btn.textContent = 'Сохранить'; btn.disabled = false;
                });
            });
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
            buildHiddenTriggers();
            document.body.classList.add('mpcve-on');
        } else {
            unmarkEditable();
            removeHiddenTriggers();
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
            // Клик по кнопке скрытых полей — её обрабатывает собственный listener.
            if (e.target.closest && e.target.closest('.mpcve-hidden-trigger')) {
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
        Promise.all([
            api.post('fields/types', {}).then(function (res) {
                if (res && res.success && res.data) {
                    if (res.data.fields) { typesMap = res.data.fields; }
                    if (res.data.labels) { labelsMap = res.data.labels; }
                }
            }).catch(function () {}),
            // Конфиг нужен только в режиме правки (тумблер перезагружает страницу).
            editing ? loadConfig() : Promise.resolve()
        ]).then(applyEditingState).catch(applyEditingState);
    }

    window.MpcVE = { cfg: cfg, api: api, resolveAddress: resolveAddress, toast: toast };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})(window, document);
