<?php
/**
 * Resolver: возвращает executable-бит CLI-обёртке console/mpc после установки.
 *
 * MODX transport раскладывает файлы пакета с правами 0644 (executable-бит не
 * сохраняется при упаковке) → запуск `./console/mpc` даёт "Permission denied".
 * Восстанавливаем 0755 на install/upgrade. mpc.php и прочие *.php запускаются
 * через `php file.php` и в +x не нуждаются — чиним только bash-обёртку.
 *
 * @var xPDOTransport $transport
 * @var array $options
 * @var modX $modx
 */

if (!$transport->xpdo) {
    return true;
}
$modx =& $transport->xpdo;

switch ($options[xPDOTransport::PACKAGE_ACTION]) {
    case xPDOTransport::ACTION_INSTALL:
    case xPDOTransport::ACTION_UPGRADE:
        $bin = MODX_CORE_PATH . 'components/migxpageconfigurator/console/mpc';
        if (is_file($bin)) {
            @chmod($bin, 0755);
        }
        break;
}

return true;
