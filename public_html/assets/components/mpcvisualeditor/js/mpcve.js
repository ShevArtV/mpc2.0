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
        image: 'Изображение — клик (редактор в разработке)',
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

    function editorTypeFor(el, addr) {
        if (addr.fieldName && typesMap[addr.fieldName]) {
            return typesMap[addr.fieldName];
        }
        if (isMedia(el)) {
            var t = el.tagName.toLowerCase();
            return (t === 'img' || t === 'picture') ? 'image' : 'media';
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
        richtext: { open: openTextEditor }
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
