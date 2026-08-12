/**
 * mpcVisualEditor — связь с коннектором + загрузка конфига.
 */
import { S } from './state.js';
import { toast, choiceDialog } from './dom.js';

function appendPageContext(body) {
    body.append('contextKey', (S.cfg && S.cfg.contextKey) || 'web');
}

function rawPost(action, payload) {
    var body = new FormData();
    body.append('action', action);
    body.append('nonce', (S.cfg && S.cfg.nonce) || ''); // CSRF-токен
    appendPageContext(body);
    Object.keys(payload || {}).forEach(function (k) {
        var v = payload[k];
        body.append(k, typeof v === 'object' ? JSON.stringify(v) : v);
    });
    return fetch(S.cfg.connectorUrl, {
        method: 'POST',
        credentials: 'same-origin',
        body: body
    }).then(function (r) {
        // non-ok HTTP (403/500/HTML-страница ошибки) → понятная ошибка вместо
        // падения r.json() на не-JSON («перелогиньтесь», а не «сетевая ошибка»).
        if (!r.ok) { throw new Error('HTTP ' + r.status); }
        return r.json();
    });
}

// Запрос с клиентским таймаутом (AbortController). Серверный таймаут скачивания
// по ссылке жёсткий (mpcve_url_download_timeout), но если соединение залипнет
// до серверного обрыва — UI не должен висеть. ms — запас поверх серверного.
function rawPostAbortable(action, payload, ms) {
    var body = new FormData();
    body.append('action', action);
    body.append('nonce', (S.cfg && S.cfg.nonce) || ''); // CSRF-токен
    appendPageContext(body);
    Object.keys(payload || {}).forEach(function (k) {
        var v = payload[k];
        body.append(k, typeof v === 'object' ? JSON.stringify(v) : v);
    });
    var ctrl  = (typeof AbortController !== 'undefined') ? new AbortController() : null;
    var timer = ctrl ? setTimeout(function () { ctrl.abort(); }, ms || 60000) : null;
    return fetch(S.cfg.connectorUrl, {
        method: 'POST',
        credentials: 'same-origin',
        body: body,
        signal: ctrl ? ctrl.signal : undefined
    }).then(function (r) {
        if (timer) { clearTimeout(timer); }
        if (!r.ok) { throw new Error('HTTP ' + r.status); }
        return r.json();
    }, function (err) {
        if (timer) { clearTimeout(timer); }
        if (err && err.name === 'AbortError') { throw new Error('Таймаут — источник не отвечает'); }
        throw err;
    });
}

// Защитный fallback на случай устаревшего config/get: штатно address.js заранее
// ставит level=type и предупреждение уже показано перед открытием редактора.
function handleInheritChoice(action, payload, res) {
    var addr = (payload && payload.address) || {};
    if (res && res.data && res.data.code === 'inherit_choice' && !addr.inherit) {
        return choiceDialog(
            'Изменения затронут все страницы этого типа',
            {
                title: 'Область изменений',
                choices: [
                    { key: 'type', label: 'Продолжить', primary: true },
                    { key: 'copy', label: 'Локализовать секцию' }
                ],
                cancelLabel: 'Отмена'
            }
        ).then(function (choice) {
            if (!choice) { return res; }
            var p2 = {};
            Object.keys(payload).forEach(function (k) { p2[k] = payload[k]; });
            p2.address = {};
            Object.keys(addr).forEach(function (k) { p2.address[k] = addr[k]; });
            p2.address.inherit = choice;
            return rawPost(action, p2).then(function (r2) {
                if (r2 && r2.success && choice === 'copy') { toast('Секция локализована для этой страницы'); }
                return r2;
            });
        });
    }
    if (res && res.success && addr.level === 'global') {
        toast('Статичная секция: изменение глобально (для всех страниц)');
    }
    return res;
}

export var api = {
    post: function (action, payload) {
        return rawPost(action, payload).then(function (res) {
            return (action === 'field/save' || action === 'row/op')
                ? handleInheritChoice(action, payload, res) : res;
        });
    },
    // Загрузка файла (multipart): file под ключом 'file' + доп. поля.
    upload: function (action, file, extra) {
        var body = new FormData();
        body.append('action', action);
        body.append('nonce', (S.cfg && S.cfg.nonce) || ''); // CSRF-токен
        appendPageContext(body);
        body.append('file', file);
        Object.keys(extra || {}).forEach(function (k) {
            var v = extra[k];
            body.append(k, typeof v === 'object' ? JSON.stringify(v) : v);
        });
        return fetch(S.cfg.connectorUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: body
        }).then(function (r) {
        // non-ok HTTP (403/500/HTML-страница ошибки) → понятная ошибка вместо
        // падения r.json() на не-JSON («перелогиньтесь», а не «сетевая ошибка»).
        if (!r.ok) { throw new Error('HTTP ' + r.status); }
        return r.json();
    });
    }
};

// Файловый менеджер: тонкие обёртки над экшенами files/*. Источник всегда один
// (выделенный источник mpc на бэке) — id не передаём.
export var files = {
    list: function (path, accept) {
        return rawPost('files/list', { path: path || '', accept: accept || 'any' });
    },
    mkdir: function (path, name) {
        return rawPost('files/mkdir', { path: path || '', name: name });
    },
    rename: function (path, name, kind) {
        return rawPost('files/rename', { path: path, name: name, kind: kind || 'file' });
    },
    remove: function (path, kind) {
        return rawPost('files/remove', { path: path, kind: kind || 'file' });
    },
    // Загрузка в текущую папку (multipart) → ответ {success,data:{url,path}}.
    upload: function (path, accept, file) {
        return api.upload('files/upload', file, { path: path || '', accept: accept || 'any' });
    },
    // Скачивание по внешнему URL в текущую папку → {success,data:{url,path}}.
    downloadUrl: function (path, accept, url) {
        var payload = { accept: accept || 'any', url: url };
        if (path != null) { payload.path = path; }
        return rawPostAbortable('files/download-url', payload, 60000);
    }
};

// Папка ИСТОЧНИКА, где лежит текущее значение поля (по его url), через files/locate
// → Promise<string|null>. null = нет значения / не резолвится → загрузка пойдёт в
// каноническую папку типа (бэк подставит canonicalDir). Используется и для старта
// файлового менеджера в нужной папке, и как path при загрузке «рядом с текущим».
export function folderOf(url) {
    if (!url) { return Promise.resolve(null); }
    return rawPost('files/locate', { url: url }).then(function (r) {
        return (r && r.success && r.data && typeof r.data.path === 'string') ? r.data.path : null;
    }).catch(function () { return null; });
}

// extra для загрузки редактора: accept + path (если папка резолвлена; null → бэк
// кладёт в каноническую папку типа — НЕ шлём path вовсе).
function uploadExtra(accept, folder) {
    var extra = { accept: accept };
    if (folder != null) { extra.path = folder; }
    return extra;
}

function uploadResultUrl(res) {
    if (!res || !res.success || !res.data || !res.data.url) {
        throw new Error((res && res.message) || 'Ошибка загрузки');
    }
    return res.data.url;
}

// Загрузка медиа-файла (video|audio|image) → Promise<url>. Без замера размеров.
// currentUrl — текущее значение поля: грузим в его папку (или canonicalDir, если нет).
export function uploadMedia(file, kind, currentUrl) {
    return folderOf(currentUrl).then(function (folder) {
        return api.upload('files/upload', file, uploadExtra(kind, folder));
    }).then(uploadResultUrl);
}

// Скачивание медиа по URL (image|video|audio|media) → Promise<url>. Кладём в папку
// текущего значения (currentUrl) или canonicalDir. Зеркало uploadMedia для ссылок.
export function downloadMedia(url, kind, currentUrl) {
    return folderOf(currentUrl).then(function (folder) {
        return files.downloadUrl(folder, kind, url);
    }).then(uploadResultUrl);
}

// Скачивание по URL + замер размеров → Promise<{url,width,height}>. Зеркало uploadAndProbe.
export function downloadAndProbe(url, currentUrl) {
    return downloadMedia(url, 'image', currentUrl).then(function (u) {
        return new Promise(function (resolve) {
            var probe = new Image();
            probe.onload = function () { resolve({ url: u, width: String(probe.naturalWidth || ''), height: String(probe.naturalHeight || '') }); };
            probe.onerror = function () { resolve({ url: u, width: '', height: '' }); };
            probe.src = u;
        });
    });
}

// Загрузка файла + замер размеров → Promise<{url,width,height}>. currentUrl — как выше.
export function uploadAndProbe(file, currentUrl) {
    return folderOf(currentUrl).then(function (folder) {
        return api.upload('files/upload', file, uploadExtra('image', folder));
    }).then(uploadResultUrl).then(function (url) {
        return new Promise(function (resolve) {
            var probe = new Image();
            probe.onload = function () { resolve({ url: url, width: String(probe.naturalWidth || ''), height: String(probe.naturalHeight || '') }); };
            probe.onerror = function () { resolve({ url: url, width: '', height: '' }); };
            probe.src = url;
        });
    });
}

// Грузим конфиг (config/get) в S.configData. Нужен в режиме правки.
export function loadConfig() {
    return api.post('config/get', { resourceId: S.cfg.resourceId || 0 }).then(function (r) {
        S.configData = (r && r.success && r.data) ? r.data : null;
        if (!S.configData) { console.warn('[mpcVE] config/get вернул без данных:', r); }
        var lx = S.configData && S.configData.lexicons;
        S.lexicons = {
            resource: (lx && lx.resource) || {},
            type: (lx && lx.type) || {},
            global: (lx && lx.global) || {}
        };
    }).catch(function (e) {
        S.configData = null;
        console.warn('[mpcVE] config/get ошибка запроса:', e);
    });
}

// Список служебных настроек (settings/list) — единый источник для панели и
// редактора info: ключи из исходных шаблонов (вкл. data-mpc-remove) + тип/значение
// из БД. Кэшируется в рамках сессии режима правки.
var _settingsList = null;
export function loadSettingsList(force) {
    if (_settingsList && !force) { return Promise.resolve(_settingsList); }
    return api.post('settings/list', {}).then(function (r) {
        _settingsList = (r && r.success && r.data && r.data.settings) ? r.data.settings : [];
        return _settingsList;
    }).catch(function () { _settingsList = []; return _settingsList; });
}
// settings/list схлопывает sys+ctx одного ключа в одну эффективную запись, так что
// ищем просто по key (hasCtx больше не различает строки — таргет несёт сама запись).
export function findSetting(key) {
    if (!_settingsList) { return null; }
    var byKey = _settingsList.filter(function (s) { return s.key === key; });
    return byKey.length ? byKey[0] : null;
}
