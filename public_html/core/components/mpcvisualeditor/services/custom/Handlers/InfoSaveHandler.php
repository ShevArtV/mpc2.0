<?php

namespace MpcVEServices\Handlers;

/**
 * Экшен info/save: правка служебной информации (data-mpc-info) — системные /
 * контекстные / ClientConfig-настройки. Данные ГЛОБАЛЬНЫЕ (меняются на всём
 * сайте), поэтому требуется отдельное право `mpcve_edit_global`.
 *
 * Запись делегируется в mpc (write-API служебных настроек):
 *   \MpcServices\Handlers\Grabber\InformationUpdater::saveSetting(key, value, ctx)
 * — тот же код, что грабер при нарезке (с blacklist защищённых настроек).
 *
 * @author Arthur Shevchenko (https://t.me/ShevArtV)
 */
class InfoSaveHandler extends BaseHandler
{
    public function handle(array $request): array
    {
        // Глобальные данные → отдельное право.
        if (!(new PermissionChecker($this->modx))->canEditGlobal()) {
            return $this->err($this->modx->lexicon('mpcve_err_permission'));
        }

        $key = trim((string)($request['key'] ?? ''));
        if ($key === '') {
            return $this->err('key required');
        }
        $value = (string)($request['value'] ?? '');

        // ctx: ключа нет → системная/ClientConfig; есть → контекстная (пустой →
        // текущий контекст коннектора, обычно web).
        $ctx = null;
        if (array_key_exists('ctx', $request)) {
            $ctx = (string)$request['ctx'];
            if ($ctx === '') {
                $ctx = (string)($this->modx->context ? $this->modx->context->get('key') : 'web');
            }
        }

        if (!class_exists('\\MpcServices\\Handlers\\Grabber\\InformationUpdater')) {
            return $this->err('migxpageconfigurator (mpc) required');
        }
        $updater = new \MpcServices\Handlers\Grabber\InformationUpdater(
            $this->modx,
            [],
            new \MpcServices\Handlers\Parser()
        );
        $res = $updater->saveSetting($key, $value, $ctx);
        if (empty($res['success'])) {
            return $this->err($res['message'] ?? 'не сохранено');
        }

        // Аудит (revertable=0 — откат глобальных настроек из истории не поддержан).
        (new ChangeLog($this->modx))->add([
            'resource_id' => (int)($this->modx->resource ? $this->modx->resource->get('id') : 0),
            'user_id'     => (int)($this->modx->user ? $this->modx->user->get('id') : 0),
            'username'    => (string)($this->modx->user ? $this->modx->user->get('username') : ''),
            'action'      => 'info',
            'section'     => '',
            'field'       => $key . ($ctx !== null ? ('@' . $ctx) : ''),
            'new_value'   => $value,
            'revertable'  => 0,
        ]);

        return $this->ok('ok');
    }
}
