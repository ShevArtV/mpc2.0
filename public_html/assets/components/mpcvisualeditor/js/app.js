/**
 * mpcVisualEditor — оркестрация: разметка полей, тулбар, клики, инициализация.
 * Тулбар доступен авторизованному ВСЕГДА; редактирование вкл/выкл тумблером
 * (cookie mpcve_editing). Редактор диспатчится по ТИПУ поля. Сохранение —
 * пер-полевое (field/save), без ре-граба DOM.
 */
import { S, setCookie } from './state.js';
import { SELECTOR } from './constants.js';
import { api, loadConfig } from './api.js';
import { toast } from './dom.js';
import { markEl } from './mark.js';
import { editors } from './editors/index.js';
import { buildHiddenTriggers, removeHiddenTriggers } from './panels.js';

// --- разметка / снятие разметки полей ----------------------------------
function markEditable() {
    document.querySelectorAll(SELECTOR).forEach(markEl);
}

function unmarkEditable() {
    document.querySelectorAll('.mpcve-editable').forEach(function (el) {
        el.classList.remove('mpcve-editable', 'mpcve-editable--media', 'mpcve-editing');
        el.removeAttribute('data-mpcve-type');
        el.removeAttribute('contenteditable');
        el.removeAttribute('title');
        // Снять временные controls, добавленные на <audio> в markEl.
        if (el.getAttribute('data-mpcve-controls') === '1') {
            el.removeAttribute('controls');
            el.removeAttribute('data-mpcve-controls');
        }
    });
}

// <audio> целиком занят нативным контрол-баром: UA глотает клики по нему и НЕ
// шлёт click на host-элемент → наш bindClicks не срабатывает (в отличие от video,
// где есть кликабельный кадр над баром). Поэтому вешаем на аудио явный аффорданс
// «✎» в обёртке-контейнере; клик по нему открывает медиа-редактор.
function attachAudioBadges() {
    document.querySelectorAll('audio.mpcve-editable').forEach(function (el) {
        var parent = el.parentNode;
        if (!parent || (parent.classList && parent.classList.contains('mpcve-audio-wrap'))) {
            return; // нет родителя или уже обёрнут
        }
        // Обёртка shrink-to-fit (inline-block) схлопнула бы аудио с темовым
        // width:100% до своей мин-ширины. Замеряем реальную ширину аудио ДО
        // оборачивания и задаём её обёртке — аудио сохраняет вид.
        var w = Math.round(el.getBoundingClientRect().width);
        var wrap = document.createElement('span');
        wrap.className = 'mpcve-audio-wrap';
        if (w > 0) { wrap.style.width = w + 'px'; }
        parent.insertBefore(wrap, el);
        wrap.appendChild(el);
        var badge = document.createElement('button');
        badge.type = 'button';
        badge.className = 'mpcve-media-badge';
        badge.textContent = '✎';
        badge.title = 'Редактировать аудио';
        badge.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            if (editors.media && editors.media.open) { editors.media.open(el); }
        });
        wrap.appendChild(badge);
    });
}

function removeAudioBadges() {
    document.querySelectorAll('.mpcve-audio-wrap').forEach(function (wrap) {
        var audio = wrap.querySelector('audio');
        if (audio && wrap.parentNode) {
            wrap.parentNode.insertBefore(audio, wrap);
        }
        wrap.remove();
    });
}

function applyEditingState() {
    if (S.editing) {
        markEditable();
        attachAudioBadges();
        buildHiddenTriggers();
        document.body.classList.add('mpcve-on');
    } else {
        unmarkEditable();
        removeAudioBadges();
        removeHiddenTriggers();
        document.body.classList.remove('mpcve-on');
    }
}

// --- UI ----------------------------------------------------------------
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
        btn.textContent = S.editing ? 'Завершить редактирование' : 'Редактировать';
        btn.classList.toggle('mpcve-btn--on', S.editing);
    }
    syncBtn();
    btn.addEventListener('click', function () {
        // Переключение режима меняет отображение → перезагружаем страницу.
        // Тулбар не пропадёт: плагин инжектит его авторизованному всегда.
        setCookie('mpcve_editing', S.editing ? '0' : '1');
        window.location.reload();
    });
}

function bindClicks() {
    document.addEventListener('click', function (e) {
        if (!S.editing) {
            return;
        }
        // Клик по кнопке скрытых полей — её обрабатывает собственный listener.
        if (e.target.closest && e.target.closest('.mpcve-hidden-trigger')) {
            return;
        }
        var el = e.target.closest ? e.target.closest('.mpcve-editable') : null;
        if (el && el.getAttribute('contenteditable') === 'true') {
            return;
        }
        if (el) {
            e.preventDefault();
            e.stopPropagation();
            var type = el.getAttribute('data-mpcve-type') || 'text';
            var ed = editors[type];
            if (ed && ed.open) {
                ed.open(el);
            } else {
                toast('Редактор «' + type + '» ещё в разработке', true);
            }
            return;
        }
        // Вне маркеров: в режиме правки НЕ переходим по ссылкам (внутренним и
        // внешним) — клик по <a> только редактирует (если ссылка-маркер) либо
        // гасится. Здесь гасим навигацию для немаркированных ссылок.
        var link = e.target.closest ? e.target.closest('a') : null;
        if (link) {
            e.preventDefault();
            e.stopPropagation();
        }
    }, true);
}

export function init() {
    buildToolbar();
    bindClicks();
    Promise.all([
        api.post('fields/types', {}).then(function (res) {
            if (res && res.success && res.data) {
                if (res.data.fields) { S.typesMap = res.data.fields; }
                if (res.data.tvs) { S.tvTypes = res.data.tvs; }
                if (res.data.labels) { S.labelsMap = res.data.labels; }
                if (res.data.settings) { S.settingsFields = res.data.settings; }
            }
        }).catch(function () {}),
        // Конфиг нужен только в режиме правки (тумблер перезагружает страницу).
        S.editing ? loadConfig() : Promise.resolve()
    ]).then(applyEditingState).catch(applyEditingState);
}
