<?php

require_once dirname(__DIR__) . '/vendor/autoload.php';

// В рантайме MODX определяет MODX_CORE_PATH сам; в юнит-окружении его нет.
// Указываем во временную папку — код, который его использует (напр.
// FieldWriter::invalidateParsed), безопасно сделает no-op на отсутствующем parsed/.
if (!defined('MODX_CORE_PATH')) {
    define('MODX_CORE_PATH', sys_get_temp_dir() . '/mpc_test_core/');
}

// Заглушка modX — базовый класс, от которого наследует ModxStub
if (!class_exists('modX')) {
    class modX
    {
        const LOG_LEVEL_FATAL = 0;
        const LOG_LEVEL_ERROR = 1;
        const LOG_LEVEL_WARN = 2;
        const LOG_LEVEL_INFO = 3;
        const LOG_LEVEL_DEBUG = 4;

        public object $event;
        public object $cacheManager;

        public function __construct()
        {
            $this->event = new stdClass();
            $this->event->returnedValues = [];
            $this->cacheManager = new stdClass();
        }

        public function getOption(string $key, $default = null, $defaultValue = null): mixed
        {
            return $defaultValue ?? $default;
        }

        public function invokeEvent(string $name, array $params = []): void {}

        public function getObject(string $class, $conditions = null): ?object
        {
            return null;
        }

        public function newObject(string $class): object
        {
            return new \MpcTests\Stubs\ModxObjectStub($class);
        }

        public function newQuery(string $class): object
        {
            return new \MpcTests\Stubs\ModxQueryStub();
        }

        public function addPackage(string $name, string $path, ?string $tablePrefix = null): bool
        {
            return true;
        }

        public function log(int $level, string $msg): void {}
    }
}

require_once __DIR__ . '/Stubs/ModxObjectStub.php';
require_once __DIR__ . '/Stubs/ModxStub.php';
