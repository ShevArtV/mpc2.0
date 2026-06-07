<?php
/**
 * Сервис для логирования работы скриптов
 */

namespace MpcServices\Helpers;

/**
 * @author Arthur Shevchenko (https://t.me/ShevArtV)
 * @example
 *      $logging = new \MpcServices\Helpers\Logging();
 *      $logFileName = self::class . 'txt';
 *      $logging->setPath($logFileName);
 *      $logging->write(__METHOD__, 'Test', ['class' => $className]);
 */
class Logging
{
    /** Уровни: DEBUG пишется только при debug=true; ERROR — всегда (виден на проде). */
    public const DEBUG = 0;
    public const WARN  = 1;
    public const ERROR = 2;

    /**
     * @var string
     */
    public string $path;
    /**
     * @var bool|null
     */
    private bool $debug;

    /**
     * @param bool|null $debug
     */
    public function __construct(?bool $debug = false)
    {
        // по умолчанию выключено: иначе логи пишутся и на проде. Кому нужно —
        // передаёт true явно (или прокидывает mpc_dev_mode).
        $this->debug = (bool)$debug;
        $this->initialize();
    }

    /**
     * @return void
     */
    private function initialize()
    {
        $this->setPath('log.txt');
    }

    public function setPath(?string $fileName = '', ?string $dir = '')
    {
        $dir = $dir ?: dirname(__FILE__, 3) . '/logs/' . date('d-m-Y') . '/';
        $this->path = $dir . $fileName;
        if (!file_exists($dir)) {
            mkdir($dir, 0777, true);
        }
    }

    /**
     * @param $method
     * @param $msg
     * @param $data
     * @param $noDate
     * @return void
     */
    public function write($method, $msg, $data = [], $noDate = false, int $level = self::DEBUG)
    {
        // ERROR пишем всегда (критичные сбои не должны теряться на проде, где
        // debug=false); DEBUG/WARN — только в debug-режиме. Поведение существующих
        // вызовов (без $level) не меняется.
        if ($this->debug || $level >= self::ERROR) {
            if (!$noDate) {
                $date = date('d.m.Y H:i:s');
                $text = "**$date** [$method] $msg" . PHP_EOL;
            } else {
                $text = PHP_EOL . "*************************** [$method] $msg ***************************" . PHP_EOL;
            }


            if (!empty($data)) {
                file_put_contents($this->path, $text . print_r($data, 1) . PHP_EOL, FILE_APPEND);
            } else {
                file_put_contents($this->path, $text, FILE_APPEND);
            }
        }
    }
}
