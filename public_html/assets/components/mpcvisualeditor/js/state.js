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
    typesMap: {},        // имя поля → тип (карта fields/types из mpc_base)
    labelsMap: {},       // имя поля → caption из конфигуратора
    settingsFields: [],  // имена полей таба «Настройки секции» (исключаем из панели)
    configData: null,    // { resourceId, resource:{}, global:{}, lexicons:{} } из config/get
    lexicons: { resource: {}, global: {} } // карты key→value лексикона по уровням
};
