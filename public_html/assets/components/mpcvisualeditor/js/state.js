/**
 * mpcVisualEditor — общее изменяемое состояние.
 * Живые биндинги ES-модулей нельзя переприсваивать между модулями, поэтому
 * состояние держим как СВОЙСТВА одного объекта S — их мутируют (S.typesMap = …,
 * S.configData = …), а импортирующие модули видят актуальное значение.
 */

export function getCookie(name) {
    var m = document.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]*)'));
    return m ? decodeURIComponent(m[1]) : null;
}
export function setCookie(name, val) {
    document.cookie = name + '=' + val + '; path=/; max-age=31536000';
}

export var S = {
    cfg: window.mpcVEConfig || {},
    // По умолчанию редактирование включено; выключается тумблером (cookie '0').
    editing: getCookie('mpcve_editing') !== '0',
    typesMap: {},        // имя config-поля → тип (карта fields/types из mpc_base)
    tvTypes: {},         // имя TV → тип редактора (modTemplateVar.type) — своя карта
    labelsMap: {},       // имя поля → caption из конфигуратора
    settingsFields: [],  // имена полей таба «Настройки секции» (исключаем из панели)
    configData: null,    // { resourceId, isType, resource:{}, type:{}, global:{}, lexicons:{} }
    lexicons: { resource: {}, type: {}, global: {} } // карты key→value по уровням
};

// Значение лексикона по ключу (в режиме лексиконов конфиг хранит КЛЮЧ, перевод —
// в файле). Показываем перевод, а не ключ. Если v не ключ (или лексиконы выкл) —
// возвращаем как есть. Карты приходят из config/get (S.lexicons по уровням).
// Живёт здесь, а не в panels.js: значение из конфига нужно и редакторам (picture),
// а импорт из panels.js замкнул бы цикл panels → editors/picture → panels.
export function lexValue(v, level) {
    if (typeof v !== 'string') { return v; }
    var map = (S.lexicons && S.lexicons[level]) || null;
    return (map && Object.prototype.hasOwnProperty.call(map, v)) ? map[v] : v;
}
