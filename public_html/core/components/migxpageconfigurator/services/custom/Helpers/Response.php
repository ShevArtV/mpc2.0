<?php
/**
 * Сервис для формирования ответа
 */

namespace CustomServices\Helpers;

/**
 * @author Arthur Shevchenko (https://t.me/ShevArtV)
 * @example
 *      $response = new \CustomServices\Helpers\Response();
 *      $response->error(__METHOD__, 'Test', ['class' => $className]);
 */
class Response
{
    /**
     * @var Logging
     */
    private Logging $logging;

    /**
     * @param Logging $logging
     */
    public function __construct(Logging $logging)
    {
        $this->logging = $logging;
    }

    /**
     * @param string|null $method
     * @param string|null $msg
     * @param array|null $data
     * @return array
     */
    public function error(?string $method = '', ?string $msg = '', ?array $data = []): array
    {
        return $this->response(false, $method, $msg, $data);
    }

    /**
     * @param string|null $method
     * @param string|null $msg
     * @param array|null $data
     * @return array
     */
    public function success(?string $method = '', ?string $msg = '', ?array $data = []): array
    {
        return $this->response(true, $method, $msg, $data);
    }

    /**
     * @param bool $status
     * @param string $method
     * @param string|null $msg
     * @param array $data
     * @return array
     */
    private function response(bool $status, string $method, ?string $msg, array $data): array
    {
        if (!$status) {
            $data['callstack'] = $this->getCallStack();
            $this->logging->write($method, $msg, $data);
        } else {
            $this->logging->write($method, $msg);
        }

        return ['success' => $status, 'message' => $msg, 'data' => $data];
    }

    /**
     * @return array
     */
    private function getCallStack(): array
    {
        $trace = debug_backtrace();
        $output = [];
        foreach ($trace as $frame) {
            $output[] = sprintf("%s:%d - %s", $frame['file'], $frame['line'], $frame['function']);
        }
        return $output;
    }
}
