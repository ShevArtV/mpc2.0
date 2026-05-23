/**
 * mpcType: добавляет в форму ресурса два read-only поля — type_name / file_name
 * (служебка типа страницы из mpc_type). Грузится только на странице
 * редактирования mpcType (через mpcTypeUpdateManagerController), поэтому патч
 * прототипа панели ресурса затрагивает только тип.
 *
 * Значения берутся из window.mpcTypeData (инжектит контроллер).
 */
(function () {
    if (typeof MODx === 'undefined' || !MODx.panel || !MODx.panel.Resource) {
        return;
    }
    var data = window.mpcTypeData || {};
    var orig = MODx.panel.Resource.prototype.getMainFields;

    MODx.panel.Resource.prototype.getMainFields = function (config) {
        var fields = orig.call(this, config);
        try {
            var cols = (fields[0] && fields[0].id === 'modx-resource-main-columns') ? fields[0] : null;
            var left = (cols && cols.items[0] && cols.items[0].id === 'modx-resource-main-left') ? cols.items[0] : null;
            if (left && Ext.isArray(left.items)) {
                left.items.push({
                    xtype: 'statictextfield',
                    fieldLabel: 'Имя типа (type_name)',
                    value: data.type_name || '',
                    anchor: '100%',
                    submitValue: false
                }, {
                    xtype: 'statictextfield',
                    fieldLabel: 'Файл секции (file_name)',
                    value: data.file_name || '',
                    anchor: '100%',
                    submitValue: false
                });
            }
        } catch (e) {
            if (console && console.warn) { console.warn('mpcType fields inject failed', e); }
        }
        return fields;
    };
})();
