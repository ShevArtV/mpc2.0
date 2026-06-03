<?php

namespace MpcVEServices\Handlers;

use MpcVEServices\Mpcve;

/**
 * Экшен config/get: отдаёт фронту декодированный mpc_config ресурса для панели
 * СКРЫТЫХ полей. Скрытые поля (вырезанные `data-mpc-remove` или вспомогательные)
 * не имеют DOM-маркера, но лежат в конфиге — фронт сам вычитает уже видимые.
 *
 * Возвращаем два уровня сразу (один запрос на вход в edit-mode):
 *   - resource — конфиг самого ресурса (нестатичные секции);
 *   - global   — конфиг страницы статичных блоков (static-секции).
 * Уровень секции на фронте определяется как при записи: static → global,
 * иначе → resource (см. fieldAddress в mpcve.js). Запись скрытого поля идёт
 * прежним путём field/save (address с parentField/idx для полей внутри строк).
 */
class ConfigGetHandler
{
    private \modX $modx;
    private Mpcve $mpcve;

    public function __construct(\modX $modx, Mpcve $mpcve)
    {
        $this->modx = $modx;
        $this->mpcve = $mpcve;
    }

    public function handle(array $request): array
    {
        $resourceId = (int)($request['resourceId'] ?? 0);
        if ($resourceId <= 0 && $this->modx->resource) {
            $resourceId = (int)$this->modx->resource->get('id');
        }
        if ($resourceId <= 0) {
            return ['success' => false, 'message' => 'resourceId required', 'data' => []];
        }

        $resource = $this->mpcve->readConfig('resource', $resourceId);
        $global   = $this->mpcve->readConfig('global', $resourceId);

        return [
            'success' => true,
            'message' => '',
            'data'    => [
                'resourceId' => $resourceId,
                'resource'   => $resource['data']['config'] ?? [],
                'global'     => $global['data']['config'] ?? [],
                // Карты лексикона по уровням — панель показывает значения, не ключи.
                'lexicons'   => [
                    'resource' => $this->mpcve->readLexicons('resource', $resourceId),
                    'global'   => $this->mpcve->readLexicons('global', $resourceId),
                ],
            ],
        ];
    }
}
