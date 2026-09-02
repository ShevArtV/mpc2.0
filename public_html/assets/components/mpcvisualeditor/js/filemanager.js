/**
 * mpcVisualEditor — мини-файловый менеджер поверх modMediaSource.
 * Навигация по папкам + CRUD (создание/переименование/удаление папок и файлов)
 * + загрузка в текущую папку + ВЫБОР существующего файла.
 *
 * openFileManager(opts) → Promise<{url,name,path}|null>
 *   opts: { accept:'image'|'media'|'any', title, startPath } — startPath открывает
 *   менеджер сразу в нужной папке (напр. папке текущего файла поля).
 * Промис резолвится выбранным файлом («Выбрать»/двойной клик) или null (отмена).
 * Бэк — экшены files/* (FileManagerHandler). Источник один — выделенный источник
 * mpc (mpc_media_source); фронт его не выбирает. Пути относительны базе источника.
 */
import { files } from './api.js';
import { toast, esc, promptDialog, confirmDialog, openModal } from './dom.js';

var EXT_ICON = { pdf: '📄', zip: '🗜', doc: '📃', docx: '📃', xls: '📊', xlsx: '📊', mp4: '🎬', webm: '🎬', mp3: '🎵', wav: '🎵' };

export function openFileManager(opts) {
    opts = opts || {};
    var accept = opts.accept || 'any';

    return new Promise(function (resolve) {
        var state = { path: '', selected: null };

        // capture + stopImmediatePropagation: Escape закрывает ТОЛЬКО файловый
        // менеджер, не подлежащий под ним модал-редактор (тоже слушает Escape) —
        // это делает сама фабрика (captureEsc), включая пропуск, если поверх
        // менеджера открыт prompt/confirm (.mpcve-confirm).
        var m = openModal({
            guard: '.mpcve-fm',
            overlayClass: 'mpcve-fm',
            cardClass: 'mpcve-modal__card--wide mpcve-fm__card',
            title: opts.title || 'Файлы',
            bodyHtml:
                '<div class="mpcve-fm__bar">' +
                    '<button type="button" class="mpcve-btn mpcve-fm__up" title="Вверх">↑</button>' +
                    '<div class="mpcve-fm__crumbs"></div>' +
                    '<span class="mpcve-modal__spacer"></span>' +
                    '<button type="button" class="mpcve-btn mpcve-fm__mkdir">📁 Папка</button>' +
                    '<label class="mpcve-btn mpcve-fm__uploadlbl">⬆ Загрузить' +
                        '<input type="file" class="mpcve-fm__upload" hidden></label>' +
                    '<button type="button" class="mpcve-btn mpcve-fm__urldl">🔗 По ссылке</button>' +
                '</div>' +
                '<div class="mpcve-fm__grid"><div class="mpcve-fm__loading">Загрузка…</div></div>',
            actionsHtml:
                '<span class="mpcve-fm__sel"></span>' +
                '<span class="mpcve-modal__spacer"></span>' +
                '<button type="button" class="mpcve-btn" data-act="cancel">Отмена</button>' +
                '<button type="button" class="mpcve-btn mpcve-btn--primary" data-act="pick" disabled>Выбрать</button>',
            captureEsc: true,
            onClose: function () { resolve(null); }
        });
        if (!m) { resolve(null); return; }
        var ov = m.overlay;

        var grid     = ov.querySelector('.mpcve-fm__grid');
        var crumbs   = ov.querySelector('.mpcve-fm__crumbs');
        var selLabel = ov.querySelector('.mpcve-fm__sel');
        var pickBtn  = ov.querySelector('[data-act=pick]');
        var uploadEl = ov.querySelector('.mpcve-fm__upload');

        function done(v) { resolve(v); m.close(); }
        pickBtn.addEventListener('click', function () { if (state.selected) { done(state.selected); } });

        // Путь родителя: 'a/b/' → 'a/', 'a/' → ''.
        function parentOf(p) {
            var t = String(p || '').replace(/\/+$/, '');
            var i = t.lastIndexOf('/');
            return i < 0 ? '' : t.slice(0, i + 1);
        }

        function setSelected(file) {
            state.selected = file;
            pickBtn.disabled = !file;
            selLabel.textContent = file ? ('Выбрано: ' + file.name) : '';
            grid.querySelectorAll('.mpcve-fm__file').forEach(function (n) {
                n.classList.toggle('mpcve-fm__file--sel', !!file && n.getAttribute('data-path') === file.path);
            });
        }

        function renderCrumbs() {
            var parts = state.path.replace(/\/+$/, '').split('/').filter(Boolean);
            var html = '<a href="#" data-p="">🏠</a>';
            var acc = '';
            parts.forEach(function (seg) {
                acc += seg + '/';
                html += ' / <a href="#" data-p="' + esc(acc) + '">' + esc(seg) + '</a>';
            });
            crumbs.innerHTML = html;
            crumbs.querySelectorAll('a').forEach(function (a) {
                a.addEventListener('click', function (e) { e.preventDefault(); navigate(a.getAttribute('data-p')); });
            });
        }

        function navigate(path) {
            path = String(path || '');
            if (path.indexOf('..') !== -1) { path = ''; } // фронт-страховка от traversal (бэк санирует тоже)
            state.path = path;
            setSelected(null);
            grid.innerHTML = '<div class="mpcve-fm__loading">Загрузка…</div>';
            files.list(state.path, accept).then(function (r) {
                if (!r || !r.success) { grid.innerHTML = '<div class="mpcve-fm__loading">' + esc((r && r.message) || 'Ошибка') + '</div>'; return; }
                var d = r.data || {};
                renderCrumbs();
                renderGrid(d.dirs || [], d.files || []);
            }).catch(function () { grid.innerHTML = '<div class="mpcve-fm__loading">Сетевая ошибка</div>'; });
        }

        function renderGrid(dirs, fileList) {
            grid.innerHTML = '';
            if (!dirs.length && !fileList.length) {
                grid.innerHTML = '<div class="mpcve-fm__loading">Папка пуста</div>';
                return;
            }
            dirs.forEach(function (dir) { grid.appendChild(dirTile(dir)); });
            fileList.forEach(function (f) { grid.appendChild(fileTile(f)); });
        }

        function actBtns(kind, item) {
            var wrap = document.createElement('div');
            wrap.className = 'mpcve-fm__acts';
            wrap.innerHTML =
                '<button type="button" class="mpcve-fm__act" data-a="rename" title="Переименовать">✎</button>' +
                '<button type="button" class="mpcve-fm__act mpcve-fm__act--del" data-a="del" title="Удалить">✕</button>';
            wrap.querySelector('[data-a=rename]').addEventListener('click', function (e) {
                e.stopPropagation();
                promptDialog('Новое имя:', { title: 'Переименовать', value: item.name, okLabel: 'Переименовать' }).then(function (name) {
                    if (!name || name === item.name) { return; }
                    files.rename(item.path, name, kind).then(function (r) {
                        if (r && r.success) { toast(r.message || 'Переименовано'); navigate(state.path); }
                        else { toast((r && r.message) || 'Ошибка', true); }
                    });
                });
            });
            wrap.querySelector('[data-a=del]').addEventListener('click', function (e) {
                e.stopPropagation();
                confirmDialog('Удалить «' + item.name + '»' + (kind === 'dir' ? ' со всем содержимым' : '') + '?', { title: 'Удаление', okLabel: 'Удалить', danger: true }).then(function (ok) {
                    if (!ok) { return; }
                    files.remove(item.path, kind).then(function (r) {
                        if (r && r.success) { toast(r.message || 'Удалено'); navigate(state.path); }
                        else { toast((r && r.message) || 'Ошибка', true); }
                    });
                });
            });
            return wrap;
        }

        function dirTile(dir) {
            var tile = document.createElement('div');
            tile.className = 'mpcve-fm__tile mpcve-fm__dir';
            tile.innerHTML = '<div class="mpcve-fm__icon">📁</div><div class="mpcve-fm__name"></div>';
            tile.querySelector('.mpcve-fm__name').textContent = dir.name;
            tile.appendChild(actBtns('dir', dir));
            tile.addEventListener('click', function () { navigate(dir.path); });
            return tile;
        }

        function fileTile(f) {
            var tile = document.createElement('div');
            tile.className = 'mpcve-fm__tile mpcve-fm__file';
            tile.setAttribute('data-path', f.path);
            var thumb = f.image
                ? '<div class="mpcve-fm__icon mpcve-fm__icon--img"><img alt="" src="' + esc(f.url) + '"></div>'
                : '<div class="mpcve-fm__icon">' + (EXT_ICON[f.ext] || '📄') + '</div>';
            tile.innerHTML = thumb + '<div class="mpcve-fm__name"></div>';
            tile.querySelector('.mpcve-fm__name').textContent = f.name;
            tile.appendChild(actBtns('file', f));
            tile.addEventListener('click', function () { setSelected(f); });
            tile.addEventListener('dblclick', function () { done(f); });
            return tile;
        }

        ov.querySelector('.mpcve-fm__up').addEventListener('click', function () {
            if (state.path) { navigate(parentOf(state.path)); }
        });
        ov.querySelector('.mpcve-fm__mkdir').addEventListener('click', function () {
            promptDialog('Имя новой папки:', { title: 'Новая папка', okLabel: 'Создать', placeholder: 'folder' }).then(function (name) {
                if (!name) { return; }
                files.mkdir(state.path, name).then(function (r) {
                    if (r && r.success) { toast(r.message || 'Создано'); navigate(state.path); }
                    else { toast((r && r.message) || 'Ошибка', true); }
                });
            });
        });
        uploadEl.addEventListener('change', function () {
            var f = uploadEl.files[0];
            if (!f) { return; }
            toast('Загрузка…');
            files.upload(state.path, accept, f).then(function (r) {
                uploadEl.value = '';
                if (r && r.success) { toast('Загружено'); navigate(state.path); }
                else { toast((r && r.message) || 'Ошибка загрузки', true); }
            }).catch(function () { uploadEl.value = ''; toast('Сетевая ошибка', true); });
        });
        // Скачать в текущую папку по внешней ссылке (тот же механизм, что грабер).
        ov.querySelector('.mpcve-fm__urldl').addEventListener('click', function () {
            promptDialog('Ссылка на файл (http/https):', { title: 'Загрузить по ссылке', okLabel: 'Скачать', placeholder: 'https://…' }).then(function (url) {
                url = (url || '').trim();
                if (!url) { return; }
                if (!/^https?:\/\//i.test(url)) { toast('Укажите корректную http(s)-ссылку', true); return; }
                toast('Скачиваем…');
                files.downloadUrl(state.path, accept, url).then(function (r) {
                    if (r && r.success) { toast('Скачано'); navigate(state.path); }
                    else { toast((r && r.message) || 'Не удалось скачать', true); }
                }).catch(function (e) { toast((e && e.message) || 'Сетевая ошибка', true); });
            });
        });

        navigate(opts.startPath ? String(opts.startPath) : '');
    });
}
