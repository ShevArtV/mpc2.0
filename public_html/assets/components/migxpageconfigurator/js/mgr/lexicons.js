/**
 * MPC Lexicons CMP
 * MODX 2 / ExtJS 3 Manager Page for lexicon management
 */

Ext.ns('MPC.page', 'MPC.grid');

/* =========================================================
 * Page: main border-layout container
 * ========================================================= */
MPC.page.Lexicons = Ext.extend(Ext.Panel, {
    constructor: function (config) {
        config = config || {};

        var langGrid      = new MPC.grid.Languages({ id: 'mpc-languages-grid' });
        var lexiconsGrid  = new MPC.grid.LexiconKeys({ id: 'mpc-lexicons-grid' });
        var resourcesGrid = new MPC.grid.Resources({ id: 'mpc-resources-grid' });

        Ext.applyIf(config, {
            layout: 'border',
            border: false,
            items: [
                {
                    region: 'west',
                    width:  400,
                    split:  true,
                    layout: 'border',
                    border: false,
                    items: [
                        {
                            region: 'west',
                            width:  120,
                            layout: 'fit',
                            border: false,
                            split:  true,
                            items:  [langGrid],
                        },
                        {
                            region: 'center',
                            layout: 'fit',
                            border: false,
                            items:  [resourcesGrid],
                        },
                    ],
                },
                {
                    region: 'center',
                    layout: 'fit',
                    border: false,
                    items:  [lexiconsGrid],
                },
            ],
        });

        MPC.page.Lexicons.superclass.constructor.call(this, config);

        lexiconsGrid.resourcesGrid = resourcesGrid;

        // Clicking a resource loads its lexicons with currently active languages
        resourcesGrid.on('rowclick', function (grid, idx) {
            var rec = grid.getStore().getAt(idx);
            lexiconsGrid.loadFile(rec.get('filename'), langGrid.getActiveLanguages());
        });

        // After resources reload — restore current resource if it's still in the list
        resourcesGrid.getStore().on('load', function (store) {
            if (!lexiconsGrid.currentFile) { return; }
            var idx = store.find('filename', lexiconsGrid.currentFile);
            if (idx >= 0) {
                var rec = store.getAt(idx);
                lexiconsGrid.loadFile(rec.get('filename'), langGrid.getActiveLanguages());
            } else {
                lexiconsGrid.currentFile = null;
            }
        });

        // Language toggle rebuilds the grid from cached rows
        langGrid.on('languagechange', function (langs) {
            lexiconsGrid.applyLanguages(langs);
        });
    },
});


/* =========================================================
 * Grid: language selector (far-left panel)
 * ========================================================= */
MPC.grid.Languages = Ext.extend(Ext.grid.GridPanel, {
    defaultLang: '',

    constructor: function (config) {
        config = config || {};

        this.store = new Ext.data.ArrayStore({
            fields: ['lang'],
            data:   [],
        });

        var sm = new Ext.grid.CheckboxSelectionModel({ singleSelect: false });

        Ext.applyIf(config, {
            store:       this.store,
            border:      false,
            sm:          sm,
            hideHeaders: true,
            viewConfig:  { forceFit: true },
            colModel: new Ext.grid.ColumnModel({
                columns: [
                    sm,
                    { dataIndex: 'lang', width: 80 },
                ],
            }),
            tbar: [{ xtype: 'tbtext', text: '<b>Языки</b>' }],
        });

        MPC.grid.Languages.superclass.constructor.call(this, config);

        this.getSelectionModel().on('selectionchange', this.onSelectionChange, this);
        this.loadLanguages();
    },

    loadLanguages: function () {
        Ext.Ajax.request({
            url:    MPC.config.connector_url,
            params: { action: 'lexicons/getlanguages' },
            scope:  this,
            success: function (resp) {
                var obj = Ext.decode(resp.responseText);
                if (!obj.success) { return; }
                this.defaultLang = obj.object.defaultLang;
                var data = [];
                for (var i = 0; i < obj.object.languages.length; i++) {
                    data.push([obj.object.languages[i]]);
                }
                this.store.loadData(data);
                this.getSelectionModel().selectAll();
            },
        });
    },

    onSelectionChange: function (sm) {
        var sm = this.getSelectionModel();
        // Default language must always stay selected
        var defaultRec = this.store.getAt(this.store.find('lang', this.defaultLang));
        if (defaultRec && !sm.isSelected(defaultRec)) {
            sm.selectRecords([defaultRec], true);
            return; // selectionchange will fire again
        }
        this.fireEvent('languagechange', this.getActiveLanguages());
    },

    getActiveLanguages: function () {
        var langs    = [];
        var selected = this.getSelectionModel().getSelections();
        for (var i = 0; i < selected.length; i++) {
            langs.push(selected[i].get('lang'));
        }
        var def = this.defaultLang;
        langs.sort(function (a, b) {
            if (a === def) { return -1; }
            if (b === def) { return 1; }
            return a < b ? -1 : 1;
        });
        return langs;
    },
});


/* =========================================================
 * Grid: resource / lexicon-file list
 * ========================================================= */
MPC.grid.Resources = Ext.extend(Ext.grid.GridPanel, {
    constructor: function (config) {
        config = config || {};

        this.store = new Ext.data.JsonStore({
            url:        MPC.config.connector_url,
            baseParams: { action: 'lexicons/getlist' },
            root:       'results',
            fields:     ['filename', 'title'],
            autoLoad:   true,
        });

        var sm = new Ext.grid.CheckboxSelectionModel();

        Ext.applyIf(config, {
            store:      this.store,
            border:     false,
            sm:         sm,
            viewConfig: { forceFit: true },
            colModel:   new Ext.grid.ColumnModel({
                columns: [
                    sm,
                    { header: 'Ресурс', dataIndex: 'title', width: 220 },
                ],
            }),
            tbar: [
                {
                    text:    'Обновить',
                    handler: function () { this.store.reload(); },
                    scope:   this,
                },
                '->',
                {
                    xtype:      'textfield',
                    emptyText:  'Поиск...',
                    width:      140,
                    enableKeyEvents: true,
                    listeners: {
                        keyup: { fn: this.onSearchKeyUp, scope: this, buffer: 300 },
                    },
                },
            ],
        });

        MPC.grid.Resources.superclass.constructor.call(this, config);
    },

    onSearchKeyUp: function (field) {
        var val = field.getValue().toLowerCase();
        this.store.filterBy(function (rec) {
            if (!val) { return true; }
            return (rec.get('title') || '').toLowerCase().indexOf(val) !== -1;
        });
    },

});


/* =========================================================
 * Grid: editable lexicon key-value grid (center panel)
 * ========================================================= */
MPC.grid.LexiconKeys = Ext.extend(Ext.grid.EditorGridPanel, {
    currentFile: null,
    allRows:     [],

    constructor: function (config) {
        config = config || {};

        this.store = new Ext.data.ArrayStore({ fields: ['key'], data: [] });

        Ext.applyIf(config, {
            store:        this.store,
            border:       false,
            clicksToEdit: 1,
            stripeRows:   true,
            loadMask:     true,
            colModel:     new Ext.grid.ColumnModel({ columns: [
                { header: 'Ключ', dataIndex: 'key', width: 300, sortable: true },
            ]}),
            tbar: [
                '->',
                {
                    text:    'Экспорт всё одним файлом',
                    tooltip: 'Один XLSX: вкладка-на-ресурс, колонка «Контекст» (секция: поле)',
                    handler: this.exportAllInOne,
                    scope:   this,
                },
                {
                    text:    'Экспорт выбранных (ZIP)',
                    handler: this.exportSelected,
                    scope:   this,
                },
                {
                    text:    'Экспорт',
                    handler: this.exportFile,
                    scope:   this,
                },
                {
                    text:    'Экспорт непереведённых',
                    tooltip: 'Только ключи, ещё не переведённые в выбранных языках',
                    handler: this.exportUntranslated,
                    scope:   this,
                },
                {
                    text:    'Импорт',
                    handler: this.importFile,
                    scope:   this,
                },
            ],
        });

        MPC.grid.LexiconKeys.superclass.constructor.call(this, config);

        this.on('afteredit', this.onAfterEdit, this);
    },

    loadFile: function (filename, activeLangs) {
        this.currentFile = filename;
        this.setDisabled(true);

        Ext.Ajax.request({
            url:    MPC.config.connector_url,
            params: { action: 'lexicons/get', filename: filename },
            scope:  this,
            success: function (resp) {
                var obj = Ext.decode(resp.responseText);
                this.setDisabled(false);

                if (!obj.success) {
                    MODx.msg.alert('Ошибка', obj.message || 'Не удалось загрузить лексиконы');
                    return;
                }

                this.allRows         = obj.object.rows;
                var langs            = (activeLangs && activeLangs.length) ? activeLangs : obj.object.languages;
                this.activeLanguages = langs;
                this.rebuildGrid(langs, this.allRows);
            },
            failure: function () {
                this.setDisabled(false);
                MODx.msg.alert('Ошибка', 'Запрос не выполнен');
            },
        });
    },

    applyLanguages: function (langs) {
        this.activeLanguages = langs;
        if (this.allRows.length) {
            this.rebuildGrid(langs, this.allRows);
        }
    },

    rebuildGrid: function (languages, rows) {
        var fields  = ['context', 'key'];
        var columns = [{
            header:    'Контекст',
            dataIndex: 'context',
            width:     260,
            sortable:  true,
            renderer:  Ext.util.Format.htmlEncode,
        }, {
            header:    'Ключ',
            dataIndex: 'key',
            width:     260,
            sortable:  true,
            renderer:  Ext.util.Format.htmlEncode,
        }];

        Ext.each(languages, function (lang) {
            fields.push(lang);
            columns.push({
                header:    lang,
                dataIndex: lang,
                width:     250,
                sortable:  false,
                renderer:  Ext.util.Format.htmlEncode,
                editor:    new Ext.form.TextField({ allowBlank: true }),
            });
        });

        var newStore  = new Ext.data.ArrayStore({ fields: fields });
        var storeData = [];
        for (var i = 0; i < rows.length; i++) {
            var rowArr = [];
            for (var j = 0; j < fields.length; j++) {
                rowArr.push(rows[i][fields[j]] || '');
            }
            storeData.push(rowArr);
        }
        newStore.loadData(storeData);

        this.reconfigure(newStore, new Ext.grid.ColumnModel({ columns: columns }));
        this.store = newStore;
    },

    onAfterEdit: function (e) {
        var rec  = e.record;
        var lang = e.field;
        if (lang === 'key' || lang === 'context') { return; }

        Ext.Ajax.request({
            url:    MPC.config.connector_url,
            params: {
                action:   'lexicons/updatekey',
                filename: this.currentFile,
                key:      rec.get('key'),
                lang:     lang,
                value:    e.value,
            },
            failure: function () {
                MODx.msg.alert('Ошибка', 'Не удалось сохранить значение');
            },
        });
    },

    exportAllInOne: function () {
        // Если ресурсы выделены — экспортируем их, иначе все. Языки — активные.
        var filenames = [];
        if (this.resourcesGrid) {
            var sel = this.resourcesGrid.getSelectionModel().getSelections();
            for (var i = 0; i < sel.length; i++) {
                filenames.push(sel[i].get('filename'));
            }
        }
        var langs = (this.activeLanguages || []);
        Ext.Ajax.request({
            url:     MPC.config.connector_url,
            params:  {
                action:    'lexicons/exportallinone',
                filenames: filenames.join(','),
                languages: langs.join(','),
            },
            timeout: 120000,
            scope:   this,
            success: function (resp) {
                var obj = Ext.decode(resp.responseText);
                if (obj.success) {
                    if (!obj.object || !obj.object.filePath) {
                        MODx.msg.alert('Готово', 'Лексиконов для экспорта не найдено');
                        return;
                    }
                    window.location.href = obj.object.filePath;
                } else {
                    MODx.msg.alert('Ошибка', obj.message);
                }
            },
            failure: function () {
                MODx.msg.alert('Ошибка', 'Запрос не выполнен');
            },
        });
    },

    exportSelected: function () {
        if (!this.resourcesGrid) { return; }
        var selected = this.resourcesGrid.getSelectionModel().getSelections();
        if (!selected.length) {
            MODx.msg.alert('Внимание', 'Выберите хотя бы один ресурс');
            return;
        }
        var filenames = [];
        for (var i = 0; i < selected.length; i++) {
            filenames.push(selected[i].get('filename'));
        }
        var langs = (this.activeLanguages || []);
        Ext.Ajax.request({
            url:     MPC.config.connector_url,
            params:  {
                action:    'lexicons/exportall',
                filenames: filenames.join(','),
                languages: langs.join(','),
            },
            timeout: 120000,
            success: function (resp) {
                var obj = Ext.decode(resp.responseText);
                if (obj.success) {
                    window.location.href = obj.object.filePath;
                } else {
                    MODx.msg.alert('Ошибка', obj.message);
                }
            },
        });
    },

    exportFile: function () {
        this.doExport(false);
    },

    exportUntranslated: function () {
        this.doExport(true);
    },

    doExport: function (untranslated) {
        if (!this.currentFile) {
            MODx.msg.alert('Внимание', 'Выберите ресурс');
            return;
        }
        Ext.Ajax.request({
            url:    MPC.config.connector_url,
            params: {
                action:       'lexicons/export',
                filename:     this.currentFile,
                languages:    (this.activeLanguages || []).join(','),
                untranslated: untranslated ? 1 : 0,
            },
            scope:  this,
            success: function (resp) {
                var obj = Ext.decode(resp.responseText);
                if (obj.success) {
                    if (!obj.object || !obj.object.filePath) {
                        MODx.msg.alert('Готово', 'Непереведённых ключей не найдено');
                        return;
                    }
                    window.location.href = obj.object.filePath;
                } else {
                    MODx.msg.alert('Ошибка', obj.message);
                }
            },
        });
    },

    importFile: function () {
        var grid = this;

        var form = new Ext.form.FormPanel({
            fileUpload: true,
            border:     false,
            padding:    10,
            items: [{
                xtype:      'fileuploadfield',
                name:       'file',
                fieldLabel: 'XLSX или ZIP',
                buttonText: 'Выбрать...',
                allowBlank: false,
            }],
        });

        var win = new Ext.Window({
            title:  'Импорт лексиконов — выбор файла',
            width:  420,
            modal:  true,
            items:  [form],
            buttons: [
                {
                    text:    'Далее',
                    cls:     'primary-button',
                    handler: function () {
                        if (!form.getForm().isValid()) { return; }
                        // mode=preview: бэкенд разбирает файл и возвращает план без записи
                        form.getForm().submit({
                            url:     MPC.config.connector_url + '?action=lexicons/import',
                            params:  { mode: 'preview' },
                            waitMsg: 'Анализ файла...',
                            success: function (f, action) {
                                win.close();
                                grid.showImportPreview(action.result.object || {});
                            },
                            failure: function (f, action) {
                                var msg = (action.result && action.result.message)
                                    ? action.result.message : 'Ошибка анализа файла';
                                MODx.msg.alert('Ошибка', msg);
                            },
                        });
                    },
                },
                {
                    text:    'Отмена',
                    handler: function () { win.close(); },
                },
            ],
        });

        win.show();
    },

    // Превью импорта: план «вкладка → ресурс» с галочками, ремапом нераспознанных
    // вкладок и счётчиками; запись только после «Импортировать выбранное».
    showImportPreview: function (data) {
        var grid = this;
        var plan = data.plan || [];

        var recs = [];
        for (var i = 0; i < plan.length; i++) {
            var p = plan[i];
            recs.push([p.id, p.sheet, p.file, p.target, p.recognized, p.langs, p.keys, p['new'], p.changed]);
        }
        var store = new Ext.data.ArrayStore({
            fields: ['id', 'sheet', 'file', 'target', 'recognized', 'langs', 'keys', 'new', 'changed'],
            data:   recs,
        });

        var resData = [];
        var resources = data.resources || [];
        for (var j = 0; j < resources.length; j++) {
            // поддержка обоих форматов: {rid,label} или просто строка
            var rr = resources[j];
            if (rr && typeof rr === 'object') { resData.push([rr.rid, rr.label || rr.rid]); }
            else { resData.push([rr, rr]); }
        }
        var resStore = new Ext.data.ArrayStore({ fields: ['rid', 'label'], data: resData });

        var targetCombo = new Ext.form.ComboBox({
            store:          resStore,
            displayField:   'label',
            valueField:     'rid',
            mode:           'local',
            triggerAction:  'all',
            editable:       true,
            forceSelection: false,
        });

        var sm = new Ext.grid.CheckboxSelectionModel();

        var pgrid = new Ext.grid.EditorGridPanel({
            store:        store,
            sm:           sm,
            clicksToEdit: 1,
            autoScroll:   true,
            border:       false,
            colModel: new Ext.grid.ColumnModel({ columns: [
                sm,
                { header: 'Вкладка',  dataIndex: 'sheet', width: 170, renderer: Ext.util.Format.htmlEncode },
                {
                    header: '→ Ресурс (двойной клик)', dataIndex: 'target', width: 180, editor: targetCombo,
                    renderer: function (v) {
                        return v ? Ext.util.Format.htmlEncode(v)
                                 : '<span style="color:#c00">выберите ресурс…</span>';
                    },
                },
                { header: 'Ключей',   dataIndex: 'keys',    width: 60 },
                { header: 'Новых',    dataIndex: 'new',     width: 60 },
                { header: 'Изменён.', dataIndex: 'changed', width: 70 },
                { header: 'Языки',    dataIndex: 'langs',   width: 90 },
            ]}),
        });

        var win = new Ext.Window({
            title:  'Импорт — предпросмотр и подтверждение',
            modal:  true,
            width:  820,
            height: 470,
            layout: 'fit',
            items:  [pgrid],
            buttons: [
                {
                    text: 'Импортировать выбранное',
                    cls:  'primary-button',
                    handler: function () {
                        var sel = sm.getSelections();
                        if (!sel.length) {
                            MODx.msg.alert('Внимание', 'Отметьте галочками вкладки для импорта');
                            return;
                        }
                        var selections = [];
                        for (var k = 0; k < sel.length; k++) {
                            var t = sel[k].get('target');
                            if (!t) {
                                MODx.msg.alert('Внимание', 'У вкладки «' + sel[k].get('sheet')
                                    + '» не задан целевой ресурс — выберите его двойным кликом.');
                                return;
                            }
                            selections.push({ id: sel[k].get('id'), target: t });
                        }
                        Ext.Ajax.request({
                            url:     MPC.config.connector_url,
                            params:  {
                                action:     'lexicons/import',
                                mode:       'apply',
                                token:      data.token,
                                selections: Ext.encode(selections),
                            },
                            timeout: 120000,
                            success: function (resp) {
                                var o = Ext.decode(resp.responseText);
                                win.close();
                                if (o.success) {
                                    if (grid.currentFile) { grid.loadFile(grid.currentFile, grid.activeLanguages); }
                                    MODx.msg.status({ title: 'Успех', message: o.message || 'Импортировано' });
                                } else {
                                    MODx.msg.alert('Ошибка', o.message);
                                }
                            },
                            failure: function () { MODx.msg.alert('Ошибка', 'Запрос не выполнен'); },
                        });
                    },
                },
                { text: 'Отмена', handler: function () { win.close(); } },
            ],
        });

        win.show();

        // распознанные вкладки сразу отмечаем галочкой
        var toSelect = [];
        store.each(function (rec) { if (rec.get('recognized')) { toSelect.push(rec); } });
        if (toSelect.length) { sm.selectRecords(toSelect); }
    },
});
