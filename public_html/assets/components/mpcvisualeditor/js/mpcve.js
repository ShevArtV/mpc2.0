/**
 * mpcVisualEditor — точка входа (ES-модули, без сборки; <script type="module">).
 * Подключает app.init(), пробрасывает публичный window.MpcVE и стартует после
 * парсинга DOM. Логика разнесена по модулям:
 *   constants.js  — константы (SELECTOR, типы, наборы полей)
 *   state.js      — общее изменяемое состояние (объект S) + cookie-хелперы
 *   dom.js        — DOM/формат-хелперы, toast
 *   api.js        — коннектор, загрузка файлов, config/get
 *   address.js    — адресация полей из DOM + поиск записей в конфиге
 *   editors/*.js  — редакторы text / image / picture / rows + реестр
 *   panels.js     — скрытые поля у блока (триггеры + панель)
 *   app.js        — разметка, тулбар, клики, init
 */
import { S } from './state.js';
import { api } from './api.js';
import { resolveAddress } from './address.js';
import { toast } from './dom.js';
import { setRteProvider } from './editors/rte.js';
import { init } from './app.js';

// Без коннектора работать нечем (плагин не передал конфиг) — выходим.
if (S.cfg.connectorUrl) {
    // Публичный мини-API (отладка / интеграции). setRte(provider) — подменить RTE:
    // provider.create(container, {value,allowedTags,upload}) → {getHTML,focus,destroy}.
    window.MpcVE = { cfg: S.cfg, api: api, resolveAddress: resolveAddress, toast: toast, setRte: setRteProvider };

    // Модуль-скрипты deferred: к моменту выполнения DOM уже распарсен
    // (readyState !== 'loading'), поэтому обычно стартуем сразу.
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
}
