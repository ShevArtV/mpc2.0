/**
 * mpcVisualEditor — аффорданс полей, сидящих на обёртке [data-mpc-unwrap].
 *
 * Каттер намеренно НЕ снимает обёртку, несущую маркер поля (иначе редактор
 * теряет адрес), а overlay.css убирает её влияние на вёрстку через
 * `display: contents`. Такой элемент бокса не создаёт: штатные outline-подсветка
 * и бейдж ::after рисуются на самом поле, поэтому у него нет ни рамки, ни
 * ховера — менеджер читает поле как нередактируемое. Клик при этом работает и
 * без нас: app.js берёт e.target.closest('.mpcve-editable'), а display:contents
 * элемент из DOM не убирает.
 *
 * Поэтому рамку рисуем НЕ на поле, а накладкой в портальном слое — одной на
 * поле, по объединённому боксу его потомков. Механика слоя та же, что у
 * триггеров скрытых полей (panels.js): position:fixed + пересчёт от
 * getBoundingClientRect на скролле и ресайзе через requestAnimationFrame.
 */

// Поле, у которого маркер стоит на unwrap-обёртке. Класс ставит markEl().
var SEL = '.mpcve-editable[data-mpc-unwrap]';

// Глубина спуска за видимым боксом: потомок сам может быть без бокса
// (вложенная unwrap-обёртка, display:contents в теме).
var MAX_DEPTH = 6;

// Пауза после правок DOM перед пересборкой накладок (инлайн-ввод, вставка
// строк списка). Позиции без пересборки двигаются на каждом кадре скролла.
var REBUILD_DELAY = 200;

// Собственный UI редактора: его мутации накладок не касаются (иначе слой,
// который мы сами и наполняем, вызывал бы бесконечную пересборку).
var OWN_UI = '.mpcve-unwrap-layer, .mpcve-hidden-layer, .mpcve-modal, .mpcve-sidebar,'
    + ' .mpcve-toolbar, .mpcve-toast';

var _layer = null;
var _items = [];            // [{ el, frame }]
var _reposScheduled = false;
var _rebuildTimer = null;
var _mo = null;             // MutationObserver: правки контента
var _ro = null;             // ResizeObserver: догрузка картинок/шрифтов
var _hovered = null;

// --- геометрия ---------------------------------------------------------

function addRect(box, r) {
    if (!r || (r.width === 0 && r.height === 0)) { return; }
    if (!box.has) {
        box.has = true;
        box.top = r.top; box.left = r.left; box.right = r.right; box.bottom = r.bottom;
        return;
    }
    if (r.top < box.top) { box.top = r.top; }
    if (r.left < box.left) { box.left = r.left; }
    if (r.right > box.right) { box.right = r.right; }
    if (r.bottom > box.bottom) { box.bottom = r.bottom; }
}

// Бокс одного узла в общий box. Текст меряем через Range (у текстового узла
// своего rect нет), элемент без бокса раскрываем до потомков.
function collectNode(node, depth, box) {
    if (node.nodeType === 3) {
        if (!node.nodeValue || !node.nodeValue.trim()) { return; }
        var range = document.createRange();
        range.selectNodeContents(node);
        addRect(box, range.getBoundingClientRect());
        return;
    }
    if (node.nodeType !== 1) { return; }
    // Служебные кнопки редактора (бейдж списка) в бокс поля не входят.
    if (node.classList && node.classList.contains('mpcve-rows-badge')) { return; }
    var r = node.getBoundingClientRect();
    if (r.width > 0 || r.height > 0) { addRect(box, r); return; }
    if (depth >= MAX_DEPTH) { return; }
    collectChildren(node, depth + 1, box);
}

function collectChildren(el, depth, box) {
    var kids = el.childNodes;
    for (var i = 0; i < kids.length; i++) {
        collectNode(kids[i], depth, box);
    }
}

// Объединённый бокс содержимого поля; null — мерить нечего (поле пустое либо
// целиком скрыто), тогда накладку не рисуем.
function fieldBox(el) {
    var box = { has: false, top: 0, left: 0, right: 0, bottom: 0 };
    collectChildren(el, 1, box);
    return box.has ? box : null;
}

// --- слой и накладки ---------------------------------------------------

function ensureLayer() {
    if (_layer) { return _layer; }
    var layer = document.createElement('div');
    layer.className = 'mpcve-unwrap-layer';
    document.body.appendChild(layer);
    _layer = layer;
    // capture: ловим скролл вложенных контейнеров, а не только окна.
    window.addEventListener('scroll', scheduleReposition, true);
    window.addEventListener('resize', scheduleReposition);
    document.addEventListener('mouseover', onMouseOver, true);
    return layer;
}

function createFrame(el) {
    var frame = document.createElement('div');
    frame.className = 'mpcve-unwrap-frame';
    // Тип нужен бейджу: иконка та же, что у обычного поля этого типа.
    frame.setAttribute('data-mpcve-type', el.getAttribute('data-mpcve-type') || 'text');
    ensureLayer().appendChild(frame);
    return frame;
}

function syncFrame(item, vw, vh) {
    var frame = item.frame;
    if (!item.el.isConnected) { frame.style.display = 'none'; return; }
    var box = fieldBox(item.el);
    // Нечего обвести (пустое или скрытое поле) либо содержимое вне экрана.
    if (!box || box.bottom <= 0 || box.top >= vh || box.right <= 0 || box.left >= vw) {
        frame.style.display = 'none';
        return;
    }
    frame.style.display = '';
    // Инлайн-правка поля (contenteditable) — накладка зеленеет так же, как
    // штатная рамка .mpcve-editing у поля с собственным боксом.
    frame.classList.toggle('is-editing', item.el.classList.contains('mpcve-editing'));
    frame.style.top = box.top + 'px';
    frame.style.left = box.left + 'px';
    frame.style.width = Math.max(0, box.right - box.left) + 'px';
    frame.style.height = Math.max(0, box.bottom - box.top) + 'px';
}

function repositionFrames() {
    var vw = window.innerWidth;
    var vh = window.innerHeight;
    for (var i = 0; i < _items.length; i++) {
        syncFrame(_items[i], vw, vh);
    }
}

function scheduleReposition() {
    if (_reposScheduled) { return; }
    _reposScheduled = true;
    window.requestAnimationFrame(function () {
        _reposScheduled = false;
        repositionFrames();
    });
}

// --- ховер -------------------------------------------------------------

function setHover(el) {
    if (_hovered === el) { return; }
    _hovered = el;
    for (var i = 0; i < _items.length; i++) {
        _items[i].frame.classList.toggle('is-hover', _items[i].el === el);
    }
}

// Накладка pointer-events:none, событий мыши не получает — ховер ловим на
// самом поле (курсор физически над его потомками).
function onMouseOver(e) {
    if (!_items.length) { return; }
    var el = (e.target && e.target.closest) ? e.target.closest(SEL) : null;
    setHover(el);
}

// --- наблюдение за изменениями -----------------------------------------

function scheduleRebuild() {
    if (_rebuildTimer) { window.clearTimeout(_rebuildTimer); }
    _rebuildTimer = window.setTimeout(function () {
        _rebuildTimer = null;
        rebuild();
    }, REBUILD_DELAY);
}

function onMutations(records) {
    for (var i = 0; i < records.length; i++) {
        var target = records[i].target;
        var host = (target.nodeType === 1) ? target : target.parentNode;
        // Мутации внутри собственного UI редактора игнорируем: слой накладок
        // мы наполняем сами, иначе пересборка зациклится.
        if (host && host.closest && host.closest(OWN_UI)) { continue; }
        // Смена класса (вход/выход из инлайн-правки) геометрию не меняет —
        // хватит пересчёта, он же перечитает is-editing.
        if (records[i].type === 'attributes') { scheduleReposition(); continue; }
        scheduleRebuild();
        return;
    }
}

function observe() {
    if (window.MutationObserver && !_mo) {
        _mo = new window.MutationObserver(onMutations);
        _mo.observe(document.body, {
            childList: true,
            subtree: true,
            characterData: true,
            attributes: true,
            attributeFilter: ['class']
        });
    }
    if (window.ResizeObserver && !_ro) {
        _ro = new window.ResizeObserver(scheduleReposition);
    }
    if (!_ro) { return; }
    // Наблюдаем прямых потомков полей: размер меняется без мутаций DOM
    // (догрузка картинок, подмена шрифта, перенос строк при ресайзе).
    for (var i = 0; i < _items.length; i++) {
        var kids = _items[i].el.children;
        for (var k = 0; k < kids.length; k++) { _ro.observe(kids[k]); }
    }
}

// --- сборка / разборка -------------------------------------------------

function rebuild() {
    var fields = document.querySelectorAll(SEL);
    // Кадры переиспользуем: поле осталось — оставляем его накладку.
    var kept = [];
    var seen = [];
    fields.forEach(function (el) {
        var found = null;
        for (var i = 0; i < _items.length; i++) {
            if (_items[i].el === el) { found = _items[i]; break; }
        }
        kept.push(found || { el: el, frame: createFrame(el) });
        seen.push(el);
    });
    for (var i = 0; i < _items.length; i++) {
        if (seen.indexOf(_items[i].el) === -1) { _items[i].frame.remove(); }
    }
    _items = kept;
    if (_ro) { _ro.disconnect(); }
    observe();
    setHover(null);
    repositionFrames();
}

export function buildUnwrapFrames() {
    removeUnwrapFrames();
    if (!document.querySelector(SEL)) { return; }
    ensureLayer();
    rebuild();
}

export function removeUnwrapFrames() {
    if (_rebuildTimer) { window.clearTimeout(_rebuildTimer); _rebuildTimer = null; }
    if (_mo) { _mo.disconnect(); _mo = null; }
    if (_ro) { _ro.disconnect(); _ro = null; }
    _items = [];
    _hovered = null;
    if (_layer) {
        _layer.remove();
        _layer = null;
        window.removeEventListener('scroll', scheduleReposition, true);
        window.removeEventListener('resize', scheduleReposition);
        document.removeEventListener('mouseover', onMouseOver, true);
    }
}
