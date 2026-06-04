<?php

namespace MpcServices\Plugins;

use MpcServices\Handlers\AdminAudit;

/**
 * OnBeforeDocFormSave: снимок СТАРЫХ значений ресурса до сохранения — для аудита
 * правок из админки в лог изменений (диффит OnDocFormSave). См. AdminAudit.
 *
 * @author Arthur Shevchenko (https://t.me/ShevArtV)
 */
class OnBeforeDocFormSave extends PluginHandler
{
    public function run(): void
    {
        $resource = $this->scriptProperties['resource'] ?? null;
        if ($resource instanceof \modResource) {
            (new AdminAudit($this->modx))->capture($resource);
        }
    }
}
