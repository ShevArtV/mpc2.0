<?php

namespace MpcTests\Stubs;

use MpcServices\Helpers\Logging;

/**
 * Логгер-шпион: собирает строки вместо отправки в mxLogger.
 *
 * Нужен там, где сам факт записи в лог — часть требования (молчаливая
 * перезапись словаря, #2609-156), а не побочный эффект.
 */
class LoggingSpy extends Logging
{
    /** @var array<int, array{message: string, context: array, level: int}> */
    public array $rows = [];

    public function write($method, $msg, $data = array(), $noDate = false, int $level = self::DEBUG, $tags = array())
    {
        $this->rows[] = [
            'message' => (string)$msg,
            'context' => is_array($data) ? $data : ['data' => $data],
            'level'   => $level,
        ];
    }

    /** Строки, в контексте которых есть искомый ключ словаря. */
    public function rowsForKey(string $key): array
    {
        return array_values(array_filter($this->rows, static function (array $row) use ($key): bool {
            return ($row['context']['key'] ?? null) === $key;
        }));
    }
}
