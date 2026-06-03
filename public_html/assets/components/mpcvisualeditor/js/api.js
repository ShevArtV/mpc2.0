/**
 * mpcVisualEditor — связь с коннектором + загрузка конфига.
 */
import { S } from './state.js';

export var api = {
    post: function (action, payload) {
        var body = new FormData();
        body.append('action', action);
        Object.keys(payload || {}).forEach(function (k) {
            var v = payload[k];
            body.append(k, typeof v === 'object' ? JSON.stringify(v) : v);
        });
        return fetch(S.cfg.connectorUrl, {
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
        return fetch(S.cfg.connectorUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: body
        }).then(function (r) { return r.json(); });
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
