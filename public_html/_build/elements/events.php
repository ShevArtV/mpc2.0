<?php

return [
    'mpcOnGetSectionFieldsValues' => [
        'groupname' => 'MigxPageConfigurator',
    ],
    'mpcOnHandleContact' => [
        'groupname' => 'MigxPageConfigurator',
    ],
    'mpcOnBeforeDownloadFile' => [
        'groupname' => 'MigxPageConfigurator',
    ],
    'mpcOnBeforeParseConfig' => [
        'groupname' => 'MigxPageConfigurator',
    ],
    'mpcOnBeforeSetLanguageSettings' => [
        'groupname' => 'MigxPageConfigurator',
    ],
    'mpcOnBeforeRender' => [
        'groupname' => 'MigxPageConfigurator',
    ],
    'mpcOnGetLexiconKey' => [
        'groupname' => 'MigxPageConfigurator',
    ],
    'mpcOnGetResourceIdentifier' => [
        'groupname' => 'MigxPageConfigurator',
    ],
    'mpcOnGetNewHtml' => [
        'groupname' => 'MigxPageConfigurator',
    ],
    'mpcOnGetSectionHtml' => [
        'groupname' => 'MigxPageConfigurator',
    ],
    'mpcOnFieldSave' => [
        'groupname' => 'MigxPageConfigurator',
    ],

    'mpcOnImportLexiconValue' => [
        'groupname' => 'MigxPageConfigurator',
    ],
    'mpcOnAddCellToExcel' => [
        'groupname' => 'MigxPageConfigurator',
    ],
    'mpcOnBeforeSaveExcel' => [
        'groupname' => 'MigxPageConfigurator',
    ],
    // Точка расширения правил именования файлов/папок. Одна на оба пакета: mpcVE
    // жёстко зависит от mpc и зовёт ту же MpcServices\Handlers\Support\FileName,
    // поэтому проекту хватает одного плагина на все потоки записи.
    'mpcOnSanitizeFileName' => [
        'groupname' => 'MigxPageConfigurator',
    ],
];
