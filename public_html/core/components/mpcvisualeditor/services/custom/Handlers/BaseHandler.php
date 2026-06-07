<?php

namespace MpcVEServices\Handlers;

use MpcVEServices\Mpcve;

/**
 * База экшен-хендлеров фронт-коннектора: общий конструктор (\modX + Mpcve) и
 * хелперы ответа ok()/err(). Хендлеры с особым форматом ответа (LockHandler,
 * FileManager/ImageUpload с собственным fallback-сообщением) переопределяют их.
 *
 * @author Arthur Shevchenko (https://t.me/ShevArtV)
 */
abstract class BaseHandler
{
    protected \modX $modx;
    protected Mpcve $mpcve;

    public function __construct(\modX $modx, Mpcve $mpcve)
    {
        $this->modx  = $modx;
        $this->mpcve = $mpcve;
    }

    protected function ok(string $message = '', array $data = []): array
    {
        return ['success' => true, 'message' => $message, 'data' => $data];
    }

    protected function err(string $message): array
    {
        return ['success' => false, 'message' => $message, 'data' => []];
    }
}
