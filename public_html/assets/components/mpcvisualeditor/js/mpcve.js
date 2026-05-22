/**
 * mpcVisualEditor — фронт-контроллер (каркас, M6).
 * Редакторы (Text/Image/RichText/RowOps) и UI-уровни — M8.
 * Здесь: бутстрап, разметка редактируемых полей, разбор адреса поля из DOM,
 * API-хелпер к коннектору. «one address space» — адрес читается из data-mpc-*.
 */
(function (window, document) {
    'use strict';

    var cfg = window.mpcVEConfig || {};
    if (!cfg.connectorUrl) {
        return;
    }

    var FIELD_ATTRS = ['data-mpc-field', 'data-mpc-rfield', 'data-mpc-tv'];

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

    /**
     * Разбор адреса поля из DOM-элемента («one address space»).
     * type: field | rfield | tv. resourceId — ближайший [data-mpc-resource]
     * либо ресурс текущей страницы (cfg.resourceId).
     */
    function resolveAddress(el) {
        var type = null, fieldName = null;
        if (el.hasAttribute('data-mpc-rfield')) {
            type = 'rfield';
            fieldName = el.getAttribute('data-mpc-rfield');
        } else if (el.hasAttribute('data-mpc-tv')) {
            type = 'tv';
            fieldName = el.getAttribute('data-mpc-tv');
        } else {
            // data-mpc-field или data-mpc-field-N (вложенные уровни)
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

        var scope = el.closest('[data-mpc-resource]');
        var resourceId = scope ? parseInt(scope.getAttribute('data-mpc-resource'), 10) : (cfg.resourceId || 0);
        var sectionEl = el.closest('[data-mpc-section]');

        return {
            type: type,
            fieldName: fieldName,
            resourceId: resourceId,
            section: sectionEl ? sectionEl.getAttribute('data-mpc-section') : null
        };
    }

    function isMedia(el) {
        var t = el.tagName.toLowerCase();
        return t === 'img' || t === 'picture' || t === 'video' || t === 'audio';
    }

    function markEditable() {
        var selector = FIELD_ATTRS.map(function (a) { return '[' + a + ']'; }).join(',')
            + ',[data-mpc-field-1],[data-mpc-field-2],[data-mpc-field-3]';
        document.querySelectorAll(selector).forEach(function (el) {
            if (!resolveAddress(el)) {
                return;
            }
            el.classList.add('mpcve-editable');
            if (isMedia(el)) {
                el.classList.add('mpcve-editable--media');
            }
            // TODO M8: навесить нужный редактор (Text/RichText/Image/...) по типу элемента.
        });
    }

    function toast(message, isError) {
        var node = document.createElement('div');
        node.className = 'mpcve-toast' + (isError ? ' mpcve-toast--error' : '');
        node.textContent = message;
        document.body.appendChild(node);
        requestAnimationFrame(function () { node.classList.add('mpcve-toast--show'); });
        setTimeout(function () {
            node.classList.remove('mpcve-toast--show');
            setTimeout(function () { node.remove(); }, 250);
        }, 2200);
    }

    function buildToolbar() {
        var bar = document.createElement('div');
        bar.className = 'mpcve-toolbar';
        bar.innerHTML =
            '<span class="mpcve-toolbar__title">mpcVisualEditor</span>' +
            '<button type="button" data-mpcve="exit">Выйти из редактирования</button>';
        document.body.appendChild(bar);
        document.body.classList.add('mpcve-active');

        bar.querySelector('[data-mpcve="exit"]').addEventListener('click', function () {
            document.cookie = 'mpcve_on=; path=/; max-age=0';
            var url = new URL(window.location.href);
            url.searchParams.delete(cfg.editParam || 'mpcedit');
            window.location.href = url.toString();
        });
    }

    function init() {
        // Запоминаем включённый режим в cookie (чтобы переживал переходы без параметра)
        document.cookie = 'mpcve_on=1; path=/';
        buildToolbar();
        markEditable();
    }

    // Экспорт для M8-редакторов
    window.MpcVE = { cfg: cfg, api: api, resolveAddress: resolveAddress, toast: toast };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})(window, document);
