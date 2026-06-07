<?php
/**
 * Кастомные кнопки тулбара MIGX-грида `mpc_config` (секции ресурса). Подключается
 * самим MIGX, когда в конфиге mpc_config задан extended.packageName=migxpageconfigurator
 * (см. migx.class.php::loadConfigs). Vendored-migx не патчим.
 *
 * Две кнопки — замена TV-галочки copy_sections:
 *   «Дополнить секции из типа»   (merge)     — добавить недостающие секции типа;
 *   «Перезаписать секции из типа» (overwrite) — заменить конфиг типом целиком.
 * Обе → процессор resource/copysections → грид обновляется БЕЗ перезагрузки
 * (процессор возвращает новый конфиг, JS пишет его в скрытое поле TV и
 * перечитывает store грида).
 *
 * Грабли: handler кнопки = первый из списка (клик), остальные добавляются в набор
 * для сборки gridfunctions — поэтому общий this._mpcCopyFromType указан вторым
 * хендлером у обеих кнопок (иначе его JS не попадёт в грид). id ресурса —
 * MODx.request.id (на форме object_id пуст). MODx.msg.confirm сам шлёт Ajax на
 * url+params, fn-колбэк не принимает.
 */

$objectId = (int)($_REQUEST['object_id'] ?? 0);

$gridactionbuttons['copySectionsMerge']['text']       = "'Дополнить секции из типа'";
$gridactionbuttons['copySectionsMerge']['handler']    = 'this.copySectionsMerge,this._mpcCopyFromType';
$gridactionbuttons['copySectionsMerge']['scope']      = 'this';
$gridactionbuttons['copySectionsMerge']['standalone'] = '1';

$gridactionbuttons['copySectionsOverwrite']['text']       = "'Перезаписать секции из типа'";
$gridactionbuttons['copySectionsOverwrite']['handler']    = 'this.copySectionsOverwrite,this._mpcCopyFromType';
$gridactionbuttons['copySectionsOverwrite']['scope']      = 'this';
$gridactionbuttons['copySectionsOverwrite']['standalone'] = '1';

$gridactionbuttons['copySectionsClear']['text']       = "'Очистить секции'";
$gridactionbuttons['copySectionsClear']['handler']    = 'this.copySectionsClear,this._mpcCopyFromType';
$gridactionbuttons['copySectionsClear']['scope']      = 'this';
$gridactionbuttons['copySectionsClear']['standalone'] = '1';

$gridfunctions['this.copySectionsMerge'] = "
copySectionsMerge: function(btn, e) { return this._mpcCopyFromType('merge'); }
";
$gridfunctions['this.copySectionsOverwrite'] = "
copySectionsOverwrite: function(btn, e) { return this._mpcCopyFromType('overwrite'); }
";
$gridfunctions['this.copySectionsClear'] = "
copySectionsClear: function(btn, e) { return this._mpcCopyFromType('clear'); }
";
$gridfunctions['this._mpcCopyFromType'] = "
_mpcCopyFromType: function(mode) {
    var grid = this;
    var rid = MODx.request['id'] || '" . $objectId . "';
    var text;
    if (mode === 'overwrite') {
        text = 'Перезаписать секции ресурса секциями из типа? Правки секций ресурса будут потеряны.';
    } else if (mode === 'clear') {
        text = 'Очистить все секции этого ресурса? Конфигурация секций будет удалена.';
    } else {
        text = 'Добавить недостающие секции из типа в этот ресурс?';
    }
    MODx.msg.confirm({
        title: 'Секции из типа'
        ,text: text
        ,url: MODx.config.assets_url + 'components/migxpageconfigurator/connector.php'
        ,params: { action: 'resource/copysections', id: rid, mode: mode }
        ,listeners: {
            'success': {fn: function(r) {
                var o = (r && r.object) ? r.object : {};
                if (o.tvid && typeof o.config !== 'undefined') {
                    var el = Ext.get('tv' + o.tvid);
                    if (el) { el.dom.value = o.config; }
                    if (grid.loadData) { grid.loadData(); }
                    if (grid.getView) { grid.getView().refresh(); }
                }
            }, scope: grid}
            ,'failure': {fn: function(r) { MODx.msg.alert('Ошибка', (r && r.message) || 'Не удалось скопировать секции'); }, scope: grid}
        }
    });
    return true;
}
";
