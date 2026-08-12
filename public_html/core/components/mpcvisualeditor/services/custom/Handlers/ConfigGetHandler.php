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
class ConfigGetHandler extends BaseHandler
{


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
        // type — секции ресурса-ТИПА (сайдбар покажет наследуемые секции под замком).
        // На самой странице-типе родительского типа нет → type пустой, isType=true.
        $isType   = $this->mpcve->isTypeResource($resourceId);
        $type     = $isType ? ['data' => ['config' => []]] : $this->mpcve->readConfig('type', $resourceId);

        return [
            'success' => true,
            'message' => '',
            'data'    => [
                'resourceId' => $resourceId,
                'isType'     => $isType,
                'resource'   => $resource['data']['config'] ?? [],
                'type'       => $type['data']['config'] ?? [],
                'global'     => $global['data']['config'] ?? [],
                // Карты лексикона по уровням — панель показывает значения, не ключи.
                'lexicons'   => [
                    'resource' => $this->mpcve->readLexicons('resource', $resourceId),
                    'type'     => $isType ? [] : $this->mpcve->readLexicons('type', $resourceId),
                    'global'   => $this->mpcve->readLexicons('global', $resourceId),
                ],
            ],
        ];
    }
}
