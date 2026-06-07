<?php

namespace MpcServices\Handlers;

/**
 * Единая точка регистрации xPDO-пакетов MPC (вместо рассредоточенных addPackage
 * в Base/Grabber/Render). Регистрирует обе модели: migxpageconfigurator
 * (mpcType/mpcTypeCollection/mpcTypeData) и migx (migxConfig). Идемпотентно +
 * once-guard по конкретному инстансу modX (addPackage и сам идемпотентен, гард —
 * чистота/без лишних вызовов).
 *
 * @author Arthur Shevchenko (https://t.me/ShevArtV)
 */
class PackageBootstrap
{
    /** @var array<int,bool> object-id modX → уже зарегистрировано */
    private static array $done = [];

    public static function ensure(\modX $modx, string $corePath): void
    {
        $id = spl_object_id($modx);
        if (isset(self::$done[$id])) {
            return;
        }
        $modx->addPackage('migxpageconfigurator', $corePath . 'components/migxpageconfigurator/model/');
        $modx->addPackage('migx', $corePath . 'components/migx/model/');
        self::$done[$id] = true;
    }
}
