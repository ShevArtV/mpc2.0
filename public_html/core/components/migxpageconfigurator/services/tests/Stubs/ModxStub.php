<?php

namespace MpcTests\Stubs;

/**
 * Заглушка modX для тестирования.
 *
 * getOption() возвращает значения из Fixtures/config.php.
 * getObject() возвращает данные из Fixtures/resources/{id}.json если есть,
 * иначе возвращает null.
 */
class ModxStub extends \modX
{
    private array $config;

    public function __construct(?string $fixturesDir = null, array $configOverrides = [])
    {
        parent::__construct();

        $fixturesDir ??= dirname(__DIR__) . '/Fixtures';
        $configFile = $fixturesDir . '/config.php';

        $base = file_exists($configFile) ? (require $configFile) : [];
        // configOverrides позволяют тесту включить mpc_use_lexicons / задать
        // mpc_exclude_lexicons_filename, не плодя отдельные config.php.
        $this->config = array_merge($base, $configOverrides);

        // Инициализируем event с пустым returnedValues
        $this->event = new \stdClass();
        $this->event->returnedValues = [];

        $this->cacheManager = new class {
            public function refresh(array $options = []): bool { return true; }
        };
    }

    /** Зеркалит modX::getCacheManager() (код использует его вместо ->cacheManager). */
    public function getCacheManager()
    {
        return $this->cacheManager;
    }

    /**
     * Возвращает значение из config.php или $defaultValue
     */
    public function getOption(string $key, $default = null, $defaultValue = null): mixed
    {
        if (array_key_exists($key, $this->config)) {
            return $this->config[$key];
        }
        // modX::getOption сигнатура: getOption($key, $options = null, $default = null)
        // В Base.php вызывается как getOption('key', null, 'default_value')
        // $default здесь — это $options (всегда null в нашем коде), $defaultValue — реальный дефолт
        return $defaultValue ?? $default;
    }

    /**
     * Возвращает объект из JSON-фикстуры или null
     */
    public function getObject(string $class, $conditions = null): ?object
    {
        return null;
    }

    public function newObject(string $class): object
    {
        return new ModxObjectStub($class);
    }

    public function newQuery(string $class): object
    {
        return new ModxQueryStub();
    }

    public function invokeEvent(string $name, array $params = []): void
    {
        // no-op: события не нужны в тестах
        $this->event->returnedValues = [];
    }

    public function addPackage(string $name, string $path, ?string $tablePrefix = null): bool
    {
        return true;
    }

    public function log(int $level, string $msg): void {}
}
