<?php
/**
 * Манифест пакетов. Применение (деструктив → нужен --force):
 *   php console/mpc.php packages apply путь/к/packages.php --dry-run
 *   php console/mpc.php packages apply путь/к/packages.php --force
 *
 * Идемпотентно: уже установленный пакет пропускается. Записи:
 *   ['name'=>'pdoTools']                 — установка из провайдера (по имени, последняя версия)
 *   ['file'=>'/abs/x.transport.zip']     — установка из локального transport-zip
 *   ['remove'=>'oldpackage']             — удаление установленного пакета
 */
return [
    ['name' => 'pdoTools'],
    ['file' => MODX_CORE_PATH . 'packages/mypackage-1.0.0-pl.transport.zip'],
    // ['remove' => 'legacyAddon'],
];
