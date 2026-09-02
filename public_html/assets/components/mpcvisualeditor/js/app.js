/**
 * mpcVisualEditor — оркестрация: разметка полей, тулбар, клики, инициализация.
 * Тулбар доступен авторизованному ВСЕГДА; редактирование вкл/выкл тумблером
 * (cookie mpcve_editing). Редактор диспатчится по ТИПУ поля. Сохранение —
 * пер-полевое (field/save), без ре-граба DOM.
 */
import { S, setCookie, getCookie } from './state.js';
import { SELECTOR, INFO_SELECTOR } from './constants.js';
import { api, loadConfig } from './api.js';
import { toast } from './dom.js';
import { markEl, markInfo, markContacts } from './mark.js';
import { editors } from './editors/index.js';
import { buildHiddenTriggers, removeHiddenTriggers } from './panels.js';
import { buildUnwrapFrames, removeUnwrapFrames } from './unwrap.js';
import { toggleSidebar } from './sidebar.js';
import { toggleChangelog } from './changelog.js';
import { toggleSettings } from './settings.js';
import { acquireLock, startLockLifecycle, showLockBanner, releaseOnExit, markActivity } from './lock.js';
import { openForElement } from './scope.js';

// --- разметка / снятие разметки полей ----------------------------------
function markEditable() {
    document.querySelectorAll(SELECTOR).forEach(markEl);
    // Служебная информация (настройки) и контакты — только при праве на
    // глобальную правку (mpcve_edit_global).
    if (S.cfg && S.cfg.editGlobal) {
        document.querySelectorAll(INFO_SELECTOR).forEach(markInfo);
        markContacts();
    }
}

function unmarkEditable() {
    document.querySelectorAll('.mpcve-editable').forEach(function (el) {
        el.classList.remove('mpcve-editable', 'mpcve-editable--media', 'mpcve-editing');
        el.removeAttribute('data-mpcve-type');
        el.removeAttribute('data-mpcve-ph');
        el.removeAttribute('contenteditable');
        el.removeAttribute('title');
        // Снять временные controls, добавленные на <audio> в markEl.
        if (el.getAttribute('data-mpcve-controls') === '1') {
            el.removeAttribute('controls');
            el.removeAttribute('data-mpcve-controls');
        }
    });
}

// Копии лексикона: элементы с data-mpc-copy, НЕ являющиеся секцией. Их контент
// принадлежит оригиналу (адрес — в значении data-mpc-copy); правка идёт там.
// Помечаем информером (бейдж + тултип), чтобы менеджер видел, что это копия и
// где оригинал. Editability не трогаем — только уведомляем.
function markCopies() {
    document.querySelectorAll('[data-mpc-copy]:not([data-mpc-section])').forEach(function (el) {
        el.classList.add('mpcve-copy');
        var orig = (el.getAttribute('data-mpc-copy') || '').trim();
        var note = orig
            ? 'Это копия лексикона. Оригинал: ' + orig
            : 'Это копия лексикона (оригинал не указан)';
        // Поле может быть и редактируемым — markEl уже поставил подсказку типа;
        // добавляем примечание о копии в начало, не затирая её.
        var existing = el.getAttribute('title');
        el.setAttribute('title', (existing && existing !== note) ? (note + ' · ' + existing) : note);
    });
}

function unmarkCopies() {
    document.querySelectorAll('.mpcve-copy').forEach(function (el) {
        el.classList.remove('mpcve-copy');
        if (!el.classList.contains('mpcve-editable')) { el.removeAttribute('title'); }
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
            if (editors.media && editors.media.open) {
                openForElement(el, function () { editors.media.open(el); });
            }
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

// Списки (data-mpcve-type="rows"): когда обёртка списка и её элементы одинаковой
// ширины, hover-пиктограммы (::after, левый верхний угол) налезают друг на друга,
// и до обёртки не докликаться (клик ловит самый глубокий элемент, бейджи
// pointer-events:none). Поэтому даём списку ОТДЕЛЬНУЮ всегда-видимую кликабельную
// кнопку ☰ (правый верхний угол), открывающую редактор строк (порядок/добавить/
// удалить) — независимо от перекрытий. Абсолютная позиция → не влияет на flex/grid.
function attachRowsBadges() {
    document.querySelectorAll('.mpcve-editable[data-mpcve-type="rows"]').forEach(function (el) {
        if (el.querySelector(':scope > .mpcve-rows-badge')) { return; }
        var badge = document.createElement('button');
        badge.type = 'button';
        badge.className = 'mpcve-rows-badge';
        badge.textContent = '☰'; // ☰
        badge.title = 'Список: порядок, добавить, удалить';
        badge.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            markActivity();
            if (editors.rows && editors.rows.open) {
                openForElement(el, function () { editors.rows.open(el); });
            }
        });
        el.appendChild(badge);
    });
}

function removeRowsBadges() {
    document.querySelectorAll('.mpcve-rows-badge').forEach(function (b) { b.remove(); });
}

function applyEditingState() {
    if (S.editing) {
        markEditable();
        markCopies();
        attachAudioBadges();
        attachRowsBadges();
        buildHiddenTriggers();
        document.body.classList.add('mpcve-on');
        // Строго после markEditable и класса mpcve-on: накладки ищут уже
        // помеченные поля и меряют их в геометрии режима правки.
        buildUnwrapFrames();
    } else {
        unmarkEditable();
        unmarkCopies();
        removeAudioBadges();
        removeRowsBadges();
        removeHiddenTriggers();
        removeUnwrapFrames();
        document.body.classList.remove('mpcve-on');
    }
}

// --- UI ----------------------------------------------------------------
function buildToolbar() {
    // Положение панели — из системной настройки mpcve_toolbar_position (top|bottom).
    var pos = (S.cfg && S.cfg.toolbarPosition === 'bottom') ? 'bottom' : 'top';
    // Свёрнутость панели — клиентское состояние (cookie), переживает перезагрузку.
    var collapsed = getCookie('mpcve_collapsed') === '1';

    var bar = document.createElement('div');
    bar.className = 'mpcve-toolbar'
        + (pos === 'bottom' ? ' mpcve-toolbar--bottom' : '')
        + (collapsed ? ' mpcve-toolbar--collapsed' : '');
    bar.innerHTML =
        '<button type="button" class="mpcve-toolbar__collapse" data-mpcve="collapse"></button>' +
        '<span class="mpcve-toolbar__title">mpcVisualEditor</span>' +
        '<span class="mpcve-toolbar__hint">клик по полю — править; Enter или уход — сохранить</span>' +
        (S.editing ? '<button type="button" data-mpcve="sections">☰ Секции</button>' : '') +
        (S.editing && S.cfg && S.cfg.editGlobal ? '<button type="button" data-mpcve="settings" title="Служебные настройки сайта (data-mpc-info)">⚙ Настройки</button>' : '') +
        (S.editing ? '<button type="button" data-mpcve="history" title="История изменений ресурса">🕓 История</button>' : '') +
        '<button type="button" data-mpcve="cache" title="Очистить кэш сайта">🧹 Кэш</button>' +
        '<button type="button" data-mpcve="admin" title="Открыть текущий ресурс в админке">⚙ Админка</button>' +
        '<button type="button" data-mpcve="toggle"></button>';
    document.body.appendChild(bar);
    document.body.classList.add('mpcve-active');
    if (pos === 'bottom') { document.body.classList.add('mpcve-pos-bottom'); }
    if (collapsed) { document.body.classList.add('mpcve-collapsed'); }

    // Сворачивание/разворачивание панели (остаётся компактный хэндл в углу).
    var colBtn = bar.querySelector('[data-mpcve="collapse"]');
    function syncCollapse() {
        var c = bar.classList.contains('mpcve-toolbar--collapsed');
        colBtn.textContent = c ? '☰' : '–';
        colBtn.title = c ? 'Развернуть панель' : 'Свернуть панель';
    }
    syncCollapse();
    colBtn.addEventListener('click', function () {
        var c = bar.classList.toggle('mpcve-toolbar--collapsed');
        document.body.classList.toggle('mpcve-collapsed', c);
        setCookie('mpcve_collapsed', c ? '1' : '0');
        syncCollapse();
    });

    var secBtn = bar.querySelector('[data-mpcve="sections"]');
    if (secBtn) { secBtn.addEventListener('click', toggleSidebar); }

    var histBtn = bar.querySelector('[data-mpcve="history"]');
    if (histBtn) { histBtn.addEventListener('click', toggleChangelog); }

    var setBtn = bar.querySelector('[data-mpcve="settings"]');
    if (setBtn) { setBtn.addEventListener('click', toggleSettings); }

    // Очистка кэша сайта (полный refresh MODX).
    var cacheBtn = bar.querySelector('[data-mpcve="cache"]');
    cacheBtn.addEventListener('click', function () {
        cacheBtn.disabled = true;
        api.post('cache/clear', {}).then(function (r) {
            toast((r && r.success) ? 'Кэш очищен' : ((r && r.message) || 'Ошибка'), !(r && r.success));
        }).catch(function () { toast('Сетевая ошибка', true); })
          .then(function () { cacheBtn.disabled = false; });
    });

    // Открыть текущий ресурс в админке MODX (новая вкладка).
    var adminBtn = bar.querySelector('[data-mpcve="admin"]');
    adminBtn.addEventListener('click', function () {
        var rid = S.cfg.resourceId || 0;
        var mgr = S.cfg.managerUrl || '/manager/';
        window.open(mgr + (mgr.indexOf('?') === -1 ? '?' : '&') + 'a=resource/update&id=' + rid, '_blank');
    });

    var btn = bar.querySelector('[data-mpcve="toggle"]');
    function syncBtn() {
        btn.textContent = S.editing ? 'Завершить редактирование' : 'Редактировать';
        btn.classList.toggle('mpcve-btn--on', S.editing);
    }
    syncBtn();
    btn.addEventListener('click', function () {
        // Переключение режима меняет отображение → перезагружаем страницу.
        // Тулбар не пропадёт: плагин инжектит его авторизованному всегда.
        if (S.editing) { releaseOnExit(); } // выходим из правки — снять блокировку
        setCookie('mpcve_editing', S.editing ? '0' : '1');
        window.location.reload();
    });
}

function bindClicks() {
    document.addEventListener('click', function (e) {
        if (!S.editing) {
            return;
        }
        // Клик внутри собственного UI редактора (модалки/файловый менеджер,
        // сайдбар, тулбар) — не вмешиваемся: у этих элементов свои обработчики.
        // Иначе глобальное гашение <a> (preventDefault+stopPropagation в capture)
        // ломает их ссылки — например, хлебные крошки файлового менеджера.
        if (e.target.closest && e.target.closest('.mpcve-modal, .mpcve-sidebar, .mpcve-toolbar')) {
            return;
        }
        // Клик по кнопке скрытых полей — её обрабатывает собственный listener.
        if (e.target.closest && e.target.closest('.mpcve-hidden-trigger')) {
            return;
        }
        var el = e.target.closest ? e.target.closest('.mpcve-editable') : null;
        if (el && el.getAttribute('contenteditable') === 'true') {
            // Поле уже редактируется инлайн — НЕ переоткрываем редактор. Но если
            // оно внутри <a>, повторный/двойной клик иначе уходит в навигацию по
            // ссылке (перезагрузка). Гасим переход; каретка ставится на mousedown,
            // поэтому preventDefault на click её не ломает.
            if (e.target.closest && e.target.closest('a')) {
                e.preventDefault();
            }
            return;
        }
        if (el) {
            e.preventDefault();
            e.stopPropagation();
            var type = el.getAttribute('data-mpcve-type') || 'text';
            var ed = editors[type];
            if (ed && ed.open) {
                markActivity(); // открытие редактора = активность (для idle-лока)
                openForElement(el, function () { ed.open(el); });
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
    ]).then(function () {
        if (!S.editing) { applyEditingState(); return; }
        // В режиме правки сперва берём блокировку ресурса.
        return acquireLock().then(function (lock) {
            if (lock && lock.mine === false) {
                // Занято другим → правку НЕ включаем, показываем баннер.
                S.lockedByOther = lock.by;
                showLockBanner(lock.by);
                document.body.classList.add('mpcve-active');
                return;
            }
            startLockLifecycle(lock ? lock.ttl : 300);
            applyEditingState();
        });
    }).catch(applyEditingState);
}
