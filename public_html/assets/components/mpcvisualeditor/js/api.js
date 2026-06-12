/**
 * mpcVisualEditor — связь с коннектором + загрузка конфига.
 */
import { S } from './state.js';
import { toast, choiceDialog } from './dom.js';

function rawPost(action, payload) {
    var body = new FormData();
    body.append('action', action);
    body.append('nonce', (S.cfg && S.cfg.nonce) || ''); // CSRF-токен
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

// Наследование уровней при field/save. Бэк отвечает code=inherit_choice, если
// секция отсутствует в конфиге ресурса (наследуется от типа) → спрашиваем юзера:
//   • «Скопировать в эту страницу» — сидируем секцию в ресурс, правка локальна
//     (повтор запроса с address.inherit='copy');
//   • «Открыть страницу-источник» — редирект на ресурс-тип (data.typeUrl), где
//     секция реально лежит, и правка идёт там обычным resource-путём.
// Для статичной секции (level=global) диалога нет — предупреждаем, что глобально.
// Централизовано здесь, чтобы все редакторы работали единообразно. Применяется и
// к field/save (правка поля), и к row/op (добавление/удаление строки списка) —
// повтор идёт через ТОТ ЖЕ action.
function handleInheritChoice(action, payload, res) {
    var addr = (payload && payload.address) || {};
    if (res && res.data && res.data.code === 'inherit_choice' && !addr.inherit) {
        var name = (res.data.section || addr.section || '');
        var typeUrl = res.data.typeUrl || '';
        var choices = [{ key: 'copy', label: 'Скопировать в эту страницу', primary: true }];
        if (typeUrl) { choices.push({ key: 'goto', label: 'Открыть страницу-источник и править там' }); }
        return choiceDialog(
            'Секция «' + name + '» наследуется от типа страницы. Скопировать её в эту страницу (править локально) или открыть страницу-источник?',
            { title: 'Наследуемая секция', choices: choices, cancelLabel: 'Отмена' }
        ).then(function (choice) {
            if (!choice) { return res; } // отмена — отдаём исходный ответ
            if (choice === 'goto') {
                // Редирект на источник. Возвращаем «вечный» промис: страница всё
                // равно уходит, а редактор не успеет мигнуть тостом 'section
                // inherited...' (иначе .then показывал бы сообщение до навигации).
                window.location.href = typeUrl;
                return new Promise(function () {});
            }
            // copy: повтор запроса с inherit='copy'
            var p2 = {};
            Object.keys(payload).forEach(function (k) { p2[k] = payload[k]; });
            p2.address = {};
            Object.keys(addr).forEach(function (k) { p2.address[k] = addr[k]; });
            p2.address.inherit = 'copy';
            return rawPost(action, p2).then(function (r2) {
                if (r2 && r2.success) { toast('Скопировано в эту страницу'); }
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
    }
};

// Загрузка медиа-файла (video|audio|image) → Promise<url>. Без замера размеров
// (для видео/аудио он не нужен). kind задаёт белый список/подпапку на бэке.
export function uploadMedia(file, kind) {
    return api.upload('image/upload', file, { kind: kind }).then(function (res) {
        if (!res || !res.success || !res.data || !res.data.url) {
            throw new Error((res && res.message) || 'Ошибка загрузки');
        }
        return res.data.url;
    });
}

// Загрузка файла + замер размеров → Promise<{url,width,height}>.
export function uploadAndProbe(file) {
    return api.upload('image/upload', file).then(function (res) {
        if (!res || !res.success || !res.data || !res.data.url) {
            throw new Error((res && res.message) || 'Ошибка загрузки');
        }
        var url = res.data.url;
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
